<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Competence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OffreController extends Controller
{
    /**
     * Récupérer toutes les offres actives
     */
    public function index(Request $request)
    {
        $query = Offre::with(['entreprise.user', 'competences'])
            ->where('statut', 'active');

        // Filtrage par type
        if ($request->has('type') && !empty($request->type)) {
            $types = is_array($request->type) ? $request->type : [$request->type];
            $query->whereIn('type', $types);
        }

        // Filtrage par localisation
        if ($request->has('localisation') && !empty($request->localisation)) {
            $localisations = is_array($request->localisation) ? $request->localisation : [$request->localisation];
            $query->whereIn('localisation', $localisations);
        }

        // Filtrage par compétences
        if ($request->has('competences') && !empty($request->competences)) {
            $competences = is_array($request->competences) ? $request->competences : [$request->competences];
            $query->whereHas('competences', function($q) use ($competences) {
                $q->whereIn('competences.id', $competences);
            });
        }

        // Recherche textuelle
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('titre', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('localisation', 'like', "%{$search}%")
                  ->orWhereHas('entreprise', function($q2) use ($search) {
                      $q2->where('nom_entreprise', 'like', "%{$search}%");
                  });
            });
        }

        $offres = $query->latest()->paginate(10);

        return response()->json([
            'offres' => $offres,
            'message' => 'Offres récupérées avec succès'
        ]);
    }

    /**
     * Récupérer une offre spécifique
     */
    public function show($id)
    {
        $offre = Offre::with(['entreprise.user', 'competences', 'test'])->findOrFail($id);
        
        // Incrémenter le compteur de vues
        $offre->increment('vues_count');

        // Vérifier si l'utilisateur est connecté
        $candidatureStatus = null;
        $saved = false;
        
        if (Auth::check() && Auth::user()->role === 'etudiant') {
            $etudiant = Auth::user()->etudiant;
            
            // Vérifier si l'étudiant a postulé
            $candidature = Candidature::where('etudiant_id', $etudiant->id)
                ->where('offre_id', $offre->id)
                ->first();
                
            if ($candidature) {
                $candidatureStatus = [
                    'status' => $candidature->statut,
                    'date_candidature' => $candidature->date_candidature,
                    'test_complete' => $candidature->test_complete,
                    'score_test' => $candidature->score_test,
                    'candidature_id' => $candidature->id
                ];
            }
            
            // Vérifier si l'offre est sauvegardée
            $saved = $etudiant->offres_sauvegardees()->where('offre_id', $id)->exists();
        }
        
        // Récupérer des offres similaires
        $similarOffres = Offre::with('entreprise')
            ->where('id', '!=', $id)
            ->where('type', $offre->type)
            ->where('statut', 'active')
            ->take(3)
            ->get();
        
        return response()->json([
            'offre' => $offre,
            'entreprise' => $offre->entreprise,
            'similar_offres' => $similarOffres,
            'candidature_status' => $candidatureStatus,
            'saved' => $saved
        ]);
    }

    /**
     * Récupérer les offres d'une entreprise spécifique
     */
    public function getEntrepriseOffres($entrepriseId)
    {
        $offres = Offre::with(['entreprise', 'competences'])
            ->where('entreprise_id', $entrepriseId)
            ->where('statut', 'active')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json([
            'offres' => $offres,
            'message' => 'Offres de l\'entreprise récupérées avec succès'
        ]);
    }

    /**
     * Sauvegarder une offre dans les favoris
     */
    public function saveOffre($id)
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $offre = Offre::findOrFail($id);
        
        // Ajouter aux favoris s'il n'y est pas déjà
        if (!$etudiant->offres_sauvegardees()->where('offre_id', $id)->exists()) {
            $etudiant->offres_sauvegardees()->attach($id);
        }
        
        return response()->json([
            'message' => 'Offre sauvegardée avec succès'
        ]);
    }

    /**
     * Retirer une offre des favoris
     */
    public function unsaveOffre($id)
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $etudiant->offres_sauvegardees()->detach($id);
        
        return response()->json([
            'message' => 'Offre retirée des favoris avec succès'
        ]);
    }

    /**
     * Postuler à une offre
     */
    public function postuler(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'lettre_motivation' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $offre = Offre::findOrFail($id);
        
        // Vérifier si l'étudiant a déjà postulé
        $candidatureExistante = Candidature::where('etudiant_id', $etudiant->id)
            ->where('offre_id', $offre->id)
            ->first();
            
        if ($candidatureExistante) {
            return response()->json([
                'message' => 'Vous avez déjà postulé à cette offre'
            ], 422);
        }
        
        // Créer la candidature
        $candidature = new Candidature();
        $candidature->etudiant_id = $etudiant->id;
        $candidature->offre_id = $offre->id;
        $candidature->lettre_motivation = $request->lettre_motivation;
        $candidature->statut = 'en_attente';
        $candidature->date_candidature = now();
        $candidature->save();
        
        // Notifier l'entreprise
        $entreprise = $offre->entreprise;
        $entreprise->user->notifications()->create([
            'titre' => 'Nouvelle candidature',
            'contenu' => "Un étudiant a postulé à votre offre: {$offre->titre}",
            'type' => 'candidature',
            'lien' => "/candidatures/{$candidature->id}"
        ]);
        
        return response()->json([
            'message' => 'Candidature soumise avec succès',
            'candidature' => $candidature
        ]);
    }

    /**
     * Récupérer le statut de candidature pour une offre
     */
    public function getCandidatureStatus($id)
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $candidature = Candidature::where('etudiant_id', $etudiant->id)
            ->where('offre_id', $id)
            ->first();
            
        $saved = $etudiant->offres_sauvegardees()->where('offre_id', $id)->exists();
        
        return response()->json([
            'status' => $candidature ? [
                'status' => $candidature->statut,
                'date_candidature' => $candidature->date_candidature,
                'test_complete' => $candidature->test_complete,
                'score_test' => $candidature->score_test,
                'candidature_id' => $candidature->id
            ] : null,
            'saved' => $saved
        ]);
    }

    /**
     * Créer une nouvelle offre (entreprise uniquement)
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'titre' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:stage,emploi,alternance',
            'niveau_requis' => 'required|string|max:255',
            'localisation' => 'required|string|max:255',
            'remuneration' => 'nullable|numeric',
            'date_debut' => 'required|date',
            'duree' => 'nullable|integer',
            'competences_requises' => 'nullable|array',
            'competences_requises.*' => 'exists:competences,id',
            'test_requis' => 'boolean',
            'questions' => 'nullable|array',
            'questions.*.contenu' => 'required|string',
            'questions.*.reponses' => 'required|array|min:2',
            'questions.*.reponses.*.contenu' => 'required|string',
            'questions.*.reponses.*.est_correcte' => 'required|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $offre = new Offre();
        $offre->entreprise_id = $user->entreprise->id;
        $offre->titre = $request->titre;
        $offre->description = $request->description;
        $offre->type = $request->type;
        $offre->niveau_requis = $request->niveau_requis;
        $offre->localisation = $request->localisation;
        $offre->remuneration = $request->remuneration;
        $offre->date_debut = $request->date_debut;
        $offre->duree = $request->duree;
        $offre->test_requis = $request->test_requis ?? false;
        $offre->statut = 'active';
        $offre->save();
        
        // Ajouter les compétences
        if ($request->has('competences_requises') && !empty($request->competences_requises)) {
            $offre->competences()->sync($request->competences_requises);
        }
        
        // Créer le test si requis
        if ($offre->test_requis && $request->has('questions') && !empty($request->questions)) {
            $test = $offre->test()->create([
                'titre' => "Test pour {$offre->titre}",
                'description' => "Test de compétences pour l'offre {$offre->titre}",
                'duree_minutes' => 60 // Durée par défaut
            ]);
            
            // Ajouter les questions et réponses
            foreach ($request->questions as $questionData) {
                $question = $test->questions()->create([
                    'contenu' => $questionData['contenu']
                ]);
                
                foreach ($questionData['reponses'] as $reponseData) {
                    $question->reponses()->create([
                        'contenu' => $reponseData['contenu'],
                        'est_correcte' => $reponseData['est_correcte']
                    ]);
                }
            }
        }
        
        return response()->json([
            'message' => 'Offre créée avec succès',
            'offre' => $offre
        ], 201);
    }

    /**
     * Mettre à jour une offre (entreprise uniquement)
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $offre = Offre::findOrFail($id);
        
        // Vérifier que l'offre appartient à cette entreprise
        if ($offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'titre' => 'string|max:255',
            'description' => 'string',
            'type' => 'in:stage,emploi,alternance',
            'niveau_requis' => 'string|max:255',
            'localisation' => 'string|max:255',
            'remuneration' => 'nullable|numeric',
            'date_debut' => 'date',
            'duree' => 'nullable|integer',
            'competences_requises' => 'nullable|array',
            'competences_requises.*' => 'exists:competences,id',
            'test_requis' => 'boolean',
            'statut' => 'in:active,inactive,cloturee'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Mettre à jour les champs de l'offre
        $offre->fill($request->only([
            'titre',
            'description',
            'type',
            'niveau_requis',
            'localisation',
            'remuneration',
            'date_debut',
            'duree',
            'test_requis',
            'statut'
        ]));
        
        $offre->save();
        
        // Mettre à jour les compétences
        if ($request->has('competences_requises')) {
            $offre->competences()->sync($request->competences_requises);
        }
        
        return response()->json([
            'message' => 'Offre mise à jour avec succès',
            'offre' => $offre
        ]);
    }

    /**
     * Supprimer une offre (entreprise uniquement)
     */
    public function destroy($id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $offre = Offre::findOrFail($id);
        
        // Vérifier que l'offre appartient à cette entreprise
        if ($offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Supprimer l'offre et ses relations
        $offre->delete();
        
        return response()->json([
            'message' => 'Offre supprimée avec succès'
        ]);
    }

    /**
     * Récupérer les offres recommandées pour un étudiant
     */
    public function getRecommendedOffers()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Récupérer les compétences de l'étudiant
        $competencesIds = $etudiant->competences()->pluck('competences.id')->toArray();
        
        // Récupérer les offres qui correspondent aux compétences de l'étudiant
        $query = Offre::with(['entreprise', 'competences'])
            ->where('statut', 'active');
            
        if (!empty($competencesIds)) {
            $query->whereHas('competences', function($q) use ($competencesIds) {
                $q->whereIn('competences.id', $competencesIds);
            });
        }
        
        $recommendedOffers = $query->latest()->limit(5)->get();
        
        // Calculer un score de correspondance pour chaque offre
        $recommendedOffers->each(function($offre) use ($competencesIds) {
            $offreCompetencesIds = $offre->competences()->pluck('competences.id')->toArray();
            $matchingCompetences = array_intersect($competencesIds, $offreCompetencesIds);
            $matchScore = count($matchingCompetences) > 0 
                ? round((count($matchingCompetences) / count($offreCompetencesIds)) * 100) 
                : 0;
            $offre->match = min($matchScore, 100);
        });
        
        // Trier par score de correspondance décroissant
        $recommendedOffers = $recommendedOffers->sortByDesc('match')->values();
        
        return response()->json([
            'recommended_offers' => $recommendedOffers
        ]);
    }
}