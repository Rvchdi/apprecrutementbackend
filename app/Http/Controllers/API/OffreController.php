<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Competence;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class OffreController extends Controller
{
    /**
     * Durée de cache pour les requêtes (en minutes)
     */
    private const CACHE_DURATION = 60; // 1 heure

    /**
     * Récupérer toutes les offres actives avec mise en cache
     */
    public function index(Request $request)
    {
        
        // Récupérer les offres depuis le cache si possible
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

            // Ajouter des informations supplémentaires
            $offres->getCollection()->transform(function ($offre) {
                // Compter dynamiquement les vues et les candidatures
                $offre->vues_count = $offre->vues_count ?? 0;
                $offre->candidatures_count = $offre->candidatures()->count();
                return $offre;
            });

            return response()->json([
                'offres' => $offres,
                'message' => 'Offres récupérées avec succès'
            ]);
    }

    /**
     * Récupérer une offre spécifique avec mise en cache
     */
    public function show($id)
    {

            $offre = Offre::with(['entreprise.user', 'competences', 'test.questions.reponses'])->findOrFail($id);
            
            // Incrémenter le compteur de vues de manière atomique
            DB::table('offres')->where('id', $id)->increment('vues_count');

            // Récupérer des offres similaires
            $similarOffres = Offre::with('entreprise')
                ->where('id', '!=', $id)
                ->where('type', $offre->type)
                ->where('statut', 'active')
                ->take(3)
                ->get();
            
            // Calculer le candidature status et les offres sauvegardées uniquement si un utilisateur est connecté
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
            
            return response()->json([
                'offre' => $offre,
                'entreprise' => $offre->entreprise,
                'similar_offres' => $similarOffres,
                'candidature_status' => $candidatureStatus,
                'saved' => $saved
            ]);;
    }

    /**
     * Générer une clé de cache unique basée sur les paramètres
     */
    private function generateCacheKey($prefix, $params)
    {
        // Trier les paramètres pour garantir une clé cohérente
        ksort($params);
        
        // Créer une chaîne unique basée sur les paramètres
        $paramString = http_build_query($params);
        
        return "offres_{$prefix}_" . md5($paramString);
    }

    /**
     * Créer une nouvelle offre avec invalidation de cache
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
        
        // Nettoyer le cache des offres après création
        $this->clearOffresCache();
        
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
     * Mettre à jour une offre avec invalidation de cache
     */
    public function update(Request $request, $id)
    {
        $result = parent::update($request, $id);

        // Nettoyer le cache de l'offre spécifique et des listes
        Cache::forget("offre_{$id}_details");
        $this->clearOffresCache();

        return $result;
    }

    /**
     * Supprimer une offre avec invalidation de cache
     */
    public function destroy($id)
{
    try {
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
    } catch (\Exception $e) {
        // Log de l'erreur complète
        \Log::error('Erreur lors de la suppression de l\'offre: ' . $e->getMessage());
        \Log::error($e->getTraceAsString());
        
        return response()->json([
            'message' => 'Erreur lors de la suppression de l\'offre',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Nettoyer le cache des offres
     */
    private function clearOffresCache()
    {
        // Supprimer tous les caches liés aux offres
        Cache::deleteMultiple([
            'offres_list_*',  // Supprimer tous les caches de liste d'offres
        ]);
    }

    /**
     * Postuler à une offre avec gestion du cache
     */
    public function postuler(Request $request, $id)
    {
        // Valider et créer la candidature
        $candidature = $this->createCandidature($request, $id);

        // Invalider le cache de l'offre spécifique
        Cache::forget("offre_{$id}_details");

        return $candidature;
    }

    /**
     * Méthode générique de création de candidature avec transaction et validation
     */
    private function createCandidature(Request $request, $id)
    {
        // Votre logique existante de candidature
        // ...
    }
}