<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Competence;
use App\Models\Offre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class OffreController extends Controller
{
    /**
     * Afficher une liste d'offres filtrées et paginées
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
{
    // Validation des paramètres de requête
    $validator = Validator::make($request->all(), [
        'type' => 'nullable|in:stage,emploi,alternance',
        'localisation' => 'nullable|string|max:255',
        'niveau_requis' => 'nullable|string|max:255',
        'remuneration_min' => 'nullable|numeric|min:0',
        'entreprise_id' => 'nullable|exists:entreprises,id',
        'competence_id' => 'nullable|exists:competences,id',
        'search' => 'nullable|string|max:255',
        'sort_by' => 'nullable|in:created_at,titre,remuneration,date_debut',
        'sort_direction' => 'nullable|in:asc,desc',
        'per_page' => 'nullable|integer|between:5,100'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Paramètres de recherche invalides',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Construction de la requête de base
        $query = Offre::with([
            'entreprise', 
            'competences', 
            'entreprise.user'
        ])
        ->where('statut', 'active');
        
        // Filtres dynamiques
        $filters = [
            'type' => fn($query, $value) => $query->where('type', $value),
            'localisation' => fn($query, $value) => $query->where('localisation', 'like', "%{$value}%"),
            'niveau_requis' => fn($query, $value) => $query->where('niveau_requis', 'like', "%{$value}%"),
            'remuneration_min' => fn($query, $value) => $query->where('remuneration', '>=', $value),
            'entreprise_id' => fn($query, $value) => $query->where('entreprise_id', $value),
        ];

        // Application des filtres
        foreach ($filters as $key => $filterCallback) {
            if ($request->has($key)) {
                $query = $filterCallback($query, $request->input($key));
            }
        }

        // Filtre par compétence
        if ($request->has('competence_id')) {
            $query->whereHas('competences', function ($q) use ($request) {
                $q->where('competence_id', $request->competence_id);
            });
        }
        
        // Recherche textuelle
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('titre', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%")
                  ->orWhereHas('entreprise', function ($q2) use ($searchTerm) {
                      $q2->where('nom_entreprise', 'like', "%{$searchTerm}%");
                  });
            });
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        
        $query->orderBy($sortBy, $sortDirection);
        
        // Pagination
        $perPage = $request->input('per_page', 10);
        $offres = $query->paginate($perPage);

        // Transformation des données (optionnel)
        $offres->getCollection()->transform(function($offre) {
            return [
                'id' => $offre->id,
                'titre' => $offre->titre,
                'description' => Str::limit($offre->description, 150),
                'type' => $offre->type,
                'localisation' => $offre->localisation,
                'remuneration' => $offre->remuneration,
                'date_debut' => $offre->date_debut,
                'entreprise' => [
                    'id' => $offre->entreprise->id,
                    'nom' => $offre->entreprise->nom_entreprise,
                    'logo' => $offre->entreprise->logo
                ],
                'competences' => $offre->competences->pluck('nom'),
                'test_requis' => $offre->test_requis
            ];
        });

        // Logging
        \Log::info('Offres récupérées', [
            'total' => $offres->total(),
            'page' => $offres->currentPage(),
            'per_page' => $offres->perPage()
        ]);

        // Réponse structurée
        return response()->json([
            'offres' => $offres,
            'message' => 'Offres récupérées avec succès',
            'meta' => [
                'total' => $offres->total(),
                'per_page' => $offres->perPage(),
                'current_page' => $offres->currentPage(),
                'last_page' => $offres->lastPage()
            ]
        ]);
    } catch (\Exception $e) {
        // Gestion des erreurs
        \Log::error('Erreur lors de la récupération des offres', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'message' => 'Une erreur est survenue lors de la récupération des offres',
            'error' => $e->getMessage()
        ], 500);
    }
}
    
    /**
     * Afficher les détails d'une offre spécifique
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $offre = Offre::with(['entreprise', 'competences'])
            ->where('id', $id)
            ->first();
        
        if (!$offre) {
            return response()->json([
                'message' => 'Offre non trouvée'
            ], 404);
        }
        
        // Si l'utilisateur est connecté et est un étudiant, vérifier s'il a déjà postulé
        $alreadyApplied = false;
        $matchingPercentage = 0;
        $matchingCompetences = [];
        $missingCompetences = [];
        
        if (Auth::check() && Auth::user()->isEtudiant()) {
            $etudiant = Auth::user()->etudiant;
            
            // Vérifier si l'étudiant a déjà postulé
            $alreadyApplied = $etudiant->candidatures()->where('offre_id', $id)->exists();
            
            // Calculer le matching de compétences
            $offreCompetences = $offre->competences()->pluck('competence_id')->toArray();
            $etudiantCompetences = $etudiant->competences()->pluck('competence_id')->toArray();
            
            $matchingCompetencesIds = array_intersect($offreCompetences, $etudiantCompetences);
            
            if (count($offreCompetences) > 0) {
                $matchingPercentage = round((count($matchingCompetencesIds) / count($offreCompetences)) * 100);
            }
            
            $matchingCompetences = Competence::whereIn('id', $matchingCompetencesIds)->get();
            $missingCompetences = Competence::whereIn('id', array_diff($offreCompetences, $etudiantCompetences))->get();
        }
        
        // Ajouter des informations supplémentaires basées sur le statut d'authentification
        $offre->user_status = [
            'already_applied' => $alreadyApplied,
            'matching_percentage' => $matchingPercentage,
            'matching_competences' => $matchingCompetences,
            'missing_competences' => $missingCompetences
        ];
        
        // Récupérer des offres similaires
        $offreCompetenceIds = $offre->competences()->pluck('competence_id')->toArray();
        
        $similarOffres = Offre::where('id', '!=', $id)
            ->where('statut', 'active')
            ->where(function ($query) use ($offre, $offreCompetenceIds) {
                // Même type d'offre
                $query->where('type', $offre->type);
                
                // Ou même localisation
                $query->orWhere('localisation', $offre->localisation);
                
                // Ou compétences similaires
                if (!empty($offreCompetenceIds)) {
                    $query->orWhereHas('competences', function ($q) use ($offreCompetenceIds) {
                        $q->whereIn('competence_id', $offreCompetenceIds);
                    });
                }
            })
            ->with(['entreprise'])
            ->take(5)
            ->get();
        
        return response()->json([
            'offre' => $offre,
            'similar_offres' => $similarOffres
        ]);
    }
    
    /**
     * Récupérer la liste des compétences pour filtrer les offres
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCompetences()
    {
        $competences = Competence::orderBy('nom')->get();
        
        return response()->json([
            'competences' => $competences
        ]);
    }
    
    /**
     * Récupérer les statistiques générales des offres
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        $totalOffres = Offre::where('statut', 'active')->count();
        
        $offresByType = Offre::where('statut', 'active')
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->get();
        
        $offresByLocation = Offre::where('statut', 'active')
            ->selectRaw('localisation, count(*) as count')
            ->groupBy('localisation')
            ->orderByDesc('count')
            ->take(10)
            ->get();
        
        $topCompetences = Competence::withCount(['offres' => function ($query) {
                $query->where('statut', 'active');
            }])
            ->having('offres_count', '>', 0)
            ->orderByDesc('offres_count')
            ->take(10)
            ->get();
        
        return response()->json([
            'total_offres' => $totalOffres,
            'offres_by_type' => $offresByType,
            'offres_by_location' => $offresByLocation,
            'top_competences' => $topCompetences
        ]);
    }
}