<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Competence;
use App\Models\Test;
use App\Models\Question;
use App\Models\Reponse;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

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
        $offre = Offre::with(['entreprise.user', 'competences', 'test.questions.reponses'])->findOrFail($id);
        
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
        
        // Validation des données de base pour l'offre
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
            'statut' => 'in:active,inactive,cloturee'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Validation supplémentaire si un test est requis
        if ($request->test_requis) {
            $testValidator = Validator::make($request->all(), [
                'test_titre' => 'required|string|max:255',
                'test_description' => 'required|string',
                'test_duree_minutes' => 'required|integer|min:1',
                'questions' => 'required|array|min:1',
                'questions.*.contenu' => 'required|string',
                'questions.*.reponses' => 'required|array|min:2',
                'questions.*.reponses.*.contenu' => 'required|string',
                'questions.*.reponses.*.est_correcte' => 'required|boolean'
            ]);
            
            if ($testValidator->fails()) {
                return response()->json([
                    'message' => 'Données du test invalides',
                    'errors' => $testValidator->errors()
                ], 422);
            }
        }
        
        // Démarrer une transaction pour assurer l'intégrité des données
        DB::beginTransaction();
        
        try {
            // Créer l'offre
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
            $offre->statut = $request->statut ?? 'active';
            $offre->save();
            
            // Ajouter les compétences
            if ($request->has('competences_requises') && !empty($request->competences_requises)) {
                $offre->competences()->sync($request->competences_requises);
            }
            
            // Créer le test si requis
            if ($offre->test_requis && $request->has('questions') && !empty($request->questions)) {
                $test = $offre->test()->create([
                    'titre' => $request->test_titre ?? "Test pour {$offre->titre}",
                    'description' => $request->test_description ?? "Test de compétences pour l'offre {$offre->titre}",
                    'duree_minutes' => $request->test_duree_minutes ?? 60
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
            
            DB::commit();
            
            return response()->json([
                'message' => 'Offre créée avec succès',
                'offre' => $offre->load(['competences', 'test.questions.reponses'])
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de l\'offre',
                'error' => $e->getMessage()
            ], 500);
        }
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
        
        // Validation des données de base pour l'offre
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
        
        // Validation supplémentaire si un test est requis
        if ($request->test_requis) {
            $testValidator = Validator::make($request->all(), [
                'test_titre' => 'required_with:test_requis|string|max:255',
                'test_description' => 'required_with:test_requis|string',
                'test_duree_minutes' => 'required_with:test_requis|integer|min:1',
                'questions' => 'required_with:test_requis|array|min:1',
                'questions.*.contenu' => 'required_with:test_requis|string',
                'questions.*.reponses' => 'required_with:test_requis|array|min:2',
                'questions.*.reponses.*.contenu' => 'required_with:test_requis|string',
                'questions.*.reponses.*.est_correcte' => 'required_with:test_requis|boolean'
            ]);
            
            if ($testValidator->fails()) {
                return response()->json([
                    'message' => 'Données du test invalides',
                    'errors' => $testValidator->errors()
                ], 422);
            }
        }
        
        // Démarrer une transaction pour assurer l'intégrité des données
        DB::beginTransaction();
        
        try {
            // Mettre à jour les champs de l'offre
            if ($request->has('titre')) $offre->titre = $request->titre;
            if ($request->has('description')) $offre->description = $request->description;
            if ($request->has('type')) $offre->type = $request->type;
            if ($request->has('niveau_requis')) $offre->niveau_requis = $request->niveau_requis;
            if ($request->has('localisation')) $offre->localisation = $request->localisation;
            if ($request->has('remuneration')) $offre->remuneration = $request->remuneration;
            if ($request->has('date_debut')) $offre->date_debut = $request->date_debut;
            if ($request->has('duree')) $offre->duree = $request->duree;
            if ($request->has('test_requis')) $offre->test_requis = $request->test_requis;
            if ($request->has('statut')) $offre->statut = $request->statut;
            
            $offre->save();
            
            // Mettre à jour les compétences
            if ($request->has('competences_requises')) {
                $offre->competences()->sync($request->competences_requises);
            }
            
            // Gérer le test
            if ($offre->test_requis && $request->has('questions')) {
                // Vérifier si un test existe déjà
                $test = $offre->test;
                
                if ($test) {
                    // Mettre à jour le test existant
                    $test->titre = $request->test_titre ?? "Test pour {$offre->titre}";
                    $test->description = $request->test_description ?? "Test de compétences pour l'offre {$offre->titre}";
                    $test->duree_minutes = $request->test_duree_minutes ?? 60;
                    $test->save();
                    
                    // Supprimer les anciennes questions et réponses pour éviter les conflits
                    // Cela pourrait être optimisé pour ne mettre à jour que ce qui a changé
                    foreach ($test->questions as $question) {
                        $question->reponses()->delete();
                    }
                    $test->questions()->delete();
                } else {
                    // Créer un nouveau test
                    $test = $offre->test()->create([
                        'titre' => $request->test_titre ?? "Test pour {$offre->titre}",
                        'description' => $request->test_description ?? "Test de compétences pour l'offre {$offre->titre}",
                        'duree_minutes' => $request->test_duree_minutes ?? 60
                    ]);
                }
                
                // Ajouter les nouvelles questions et réponses
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
            } elseif (!$offre->test_requis && $offre->test) {
                // Si le test n'est plus requis mais qu'il existait, le supprimer
                $test = $offre->test;
                
                foreach ($test->questions as $question) {
                    $question->reponses()->delete();
                }
                $test->questions()->delete();
                $test->delete();
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Offre mise à jour avec succès',
                'offre' => $offre->load(['competences', 'test.questions.reponses'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'offre',
                'error' => $e->getMessage()
            ], 500);
        }
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

    // Les autres méthodes restent inchangées...
    public function postuler(Request $request, $id)
{
    $user = Auth::user();
    
    if ($user->role !== 'etudiant') {
        return response()->json([
            'message' => 'Seuls les étudiants peuvent postuler aux offres'
        ], 403);
    }
    
    $validator = Validator::make($request->all(), [
        'lettre_motivation' => 'required|string|min:100',
    ]);
    
    if ($validator->fails()) {
        return response()->json([
            'message' => 'Données invalides',
            'errors' => $validator->errors()
        ], 422);
    }
    
    $etudiant = $user->etudiant;
    
    // Vérifier si l'offre existe et est active
    $offre = Offre::where('statut', 'active')->find($id);
    
    if (!$offre) {
        return response()->json([
            'message' => 'Offre non trouvée ou inactive'
        ], 404);
    }
    
    // Vérifier que l'étudiant n'a pas déjà postulé
    if ($etudiant->candidatures()->where('offre_id', $id)->exists()) {
        return response()->json([
            'message' => 'Vous avez déjà postulé à cette offre'
        ], 422);
    }
    
    // Vérifier que le CV de l'étudiant est bien renseigné
    if (empty($etudiant->cv_file)) {
        return response()->json([
            'message' => 'Vous devez télécharger votre CV avant de postuler',
            'code' => 'CV_REQUIRED'
        ], 422);
    }
    
    try {
        DB::beginTransaction();
        
        // Créer la candidature
        $candidature = Candidature::create([
            'etudiant_id' => $etudiant->id,
            'offre_id' => $id,
            'lettre_motivation' => $request->lettre_motivation,
            'statut' => 'en_attente',
            'test_complete' => false,
            'date_candidature' => now()
        ]);
        
        // Créer une notification pour l'entreprise
        $notification = new Notification([
            'user_id' => $offre->entreprise->user_id,
            'titre' => 'Nouvelle candidature',
            'contenu' => "L'étudiant {$etudiant->user->prenom} {$etudiant->user->nom} a postulé à votre offre : {$offre->titre}",
            'type' => 'candidature',
            'lu' => false
        ]);
        $notification->save();
        
        DB::commit();
        
        return response()->json([
            'message' => 'Candidature envoyée avec succès',
            'candidature' => $candidature,
            'test_required' => $offre->test_requis
        ], 201);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        return response()->json([
            'message' => 'Une erreur est survenue lors de l\'envoi de la candidature',
            'error' => $e->getMessage()
        ], 500);
    }
}
}