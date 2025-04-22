<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Competence;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Etudiant;
use App\Models\Entreprise;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    /**
     * Récupérer les statistiques pour le tableau de bord
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardStats()
    {
        // Utilisation du cache pour optimiser les performances
        $stats = Cache::remember('admin.dashboard.stats', now()->addMinutes(15), function () {
            $usersCount = User::count();
            $studentsCount = User::where('role', 'etudiant')->count();
            $companiesCount = User::where('role', 'entreprise')->count();
            $offersCount = Offre::count();
            $applicationsCount = Candidature::count();
            $competencesCount = Competence::count();
            // Récupérer les utilisateurs récemment inscrits
           
           
            // Statistiques plus avancées
            $activeOffers = Offre::where('statut', 'active')->count();
            $pendingApplications = Candidature::where('statut', 'en_attente')->count();
            $popularCompetences = Competence::withCount('etudiants')->orderBy('etudiants_count', 'desc')->take(5)->get();
            $offersByType = Offre::select('type', DB::raw('count(*) as total'))
                                ->groupBy('type')
                                ->get();
            return [
                'users' => [
                    'total' => $usersCount,
                    'students' => $studentsCount,
                    'companies' => $companiesCount,
            
                ],
                'offers' => [
                    'total' => $offersCount,
                    'active' => $activeOffers,
                    'by_type' => $offersByType
                ],
                'applications' => [
                    'total' => $applicationsCount,
                    'pending' => $pendingApplications
                ],
                'competences' => [
                    'total' => $competencesCount,
                    'popular' => $popularCompetences
                ],
            ];
        });
    
        return response()->json($stats);
    }

    /**
     * Récupérer la liste des utilisateurs
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsers(Request $request)
    {
        $query = User::query();
        
        // Filtres
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }
        
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('prenom', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        if ($request->has('verified') && $request->verified !== 'all') {
            if ($request->verified === 'yes') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }
        
        // Tri et pagination
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_dir', 'desc');
        $perPage = $request->input('per_page', 15);
        
        $allowedSortFields = ['id', 'nom', 'prenom', 'email', 'role', 'created_at', 'last_login_at'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }
        
        // Chargement des relations selon le rôle
        $query->with(['etudiant' => function($q) {
            $q->select('id', 'user_id', 'ecole', 'filiere', 'niveau_etude');
        }, 'entreprise' => function($q) {
            $q->select('id', 'user_id', 'nom_entreprise', 'secteur_activite');
        }]);
        
        $users = $query->paginate($perPage);
        
        return response()->json([
            'users' => $users
        ]);
    }

    /**
     * Récupérer un utilisateur spécifique
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUser($id)
    {
        $user = User::with(['etudiant', 'entreprise'])->findOrFail($id);
        
        // Statistiques additionnelles selon le rôle
        $stats = [];
        
        if ($user->role === 'etudiant') {
            $stats = [
                'candidatures_count' => $user->etudiant->candidatures()->count(),
                'competences_count' => $user->etudiant->competences()->count(),
                'entretiens_count' => $user->etudiant->candidatures()->where('statut', 'entretien')->count()
            ];
        } elseif ($user->role === 'entreprise') {
            $stats = [
                'offres_count' => $user->entreprise->offres()->count(),
                'candidatures_recues' => Candidature::whereHas('offre', function($q) use ($user) {
                    $q->where('entreprise_id', $user->entreprise->id);
                })->count(),
                'offres_actives' => $user->entreprise->offres()->where('statut', 'active')->count()
            ];
        }
        
        return response()->json([
            'user' => $user,
            'stats' => $stats
        ]);
    }

    /**
     * Créer un nouvel utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,etudiant,entreprise',
            'telephone' => 'nullable|string|max:20',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Créer l'utilisateur
            $user = User::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'telephone' => $request->telephone,
                'role' => $request->role,
                'email_verified_at' => now(), // Auto-vérification pour les utilisateurs créés par l'admin
            ]);
            
            // Créer un profil selon le rôle
            if ($request->role === 'etudiant') {
                Etudiant::create([
                    'user_id' => $user->id
                ]);
            } elseif ($request->role === 'entreprise') {
                Entreprise::create([
                    'user_id' => $user->id,
                    'nom_entreprise' => $request->input('nom_entreprise', $request->nom),
                    'est_verifie' => true // Entreprise vérifiée car créée par l'admin
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Utilisateur créé avec succès',
                'user' => $user
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la création d\'un utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création de l\'utilisateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mettre à jour un utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|string|max:255',
            'prenom' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'role' => 'sometimes|in:admin,etudiant,entreprise',
            'telephone' => 'nullable|string|max:20',
            'email_verified_at' => 'nullable|boolean',
            'password' => 'nullable|string|min:8',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Mise à jour des informations de base
            if ($request->has('nom')) $user->nom = $request->nom;
            if ($request->has('prenom')) $user->prenom = $request->prenom;
            if ($request->has('email')) $user->email = $request->email;
            if ($request->has('telephone')) $user->telephone = $request->telephone;
            
            // Mise à jour du mot de passe si fourni
            if ($request->has('password') && !empty($request->password)) {
                $user->password = Hash::make($request->password);
            }
            
            // Gestion de l'état de vérification de l'email
            if ($request->has('email_verified_at')) {
                $user->email_verified_at = $request->email_verified_at ? now() : null;
            }
            
            // Gérer le changement de rôle (plus complexe)
            if ($request->has('role') && $request->role !== $user->role) {
                // Si l'utilisateur devient étudiant
                if ($request->role === 'etudiant' && !$user->etudiant) {
                    Etudiant::create(['user_id' => $user->id]);
                }
                // Si l'utilisateur devient entreprise
                elseif ($request->role === 'entreprise' && !$user->entreprise) {
                    Entreprise::create([
                        'user_id' => $user->id,
                        'nom_entreprise' => $request->input('nom_entreprise', $user->nom)
                    ]);
                }
                
                $user->role = $request->role;
            }
            
            $user->save();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Utilisateur mis à jour avec succès',
                'user' => $user
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour d\'un utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'utilisateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer un utilisateur
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        
        // Empêcher la suppression du compte admin courant
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte'
            ], 403);
        }
        
        DB::beginTransaction();
        
        try {
            // Supprimer les données associées selon le rôle
            if ($user->role === 'entreprise' && $user->entreprise) {
                // Supprimer les offres liées à cette entreprise
                foreach ($user->entreprise->offres as $offre) {
                    // Supprimer les tests associés à l'offre
                    if ($offre->test) {
                        foreach ($offre->test->questions as $question) {
                            $question->reponses()->delete();
                        }
                        $offre->test->questions()->delete();
                        $offre->test->delete();
                    }
                    
                    // Supprimer les candidatures liées à l'offre
                    foreach ($offre->candidatures as $candidature) {
                        $candidature->reponsesEtudiants()->delete();
                        $candidature->delete();
                    }
                    
                    // Détacher les compétences
                    $offre->competences()->detach();
                    
                    $offre->delete();
                }
                
                // Supprimer le profil entreprise
                $user->entreprise->delete();
            } elseif ($user->role === 'etudiant' && $user->etudiant) {
                // Supprimer les candidatures liées à cet étudiant
                foreach ($user->etudiant->candidatures as $candidature) {
                    $candidature->reponsesEtudiants()->delete();
                    $candidature->delete();
                }
                
                // Détacher les compétences
                $user->etudiant->competences()->detach();
                
                // Détacher les offres sauvegardées
                $user->etudiant->offres_sauvegardees()->detach();
                
                // Supprimer le CV si présent
                if ($user->etudiant->cv_file && Storage::disk('public')->exists($user->etudiant->cv_file)) {
                    Storage::disk('public')->delete($user->etudiant->cv_file);
                }
                
                // Supprimer le profil étudiant
                $user->etudiant->delete();
            }
            
            // Supprimer la photo de profil si présente
            if ($user->photo && Storage::disk('public')->exists($user->photo)) {
                Storage::disk('public')->delete($user->photo);
            }
            
            // Supprimer les notifications
            $user->notifications()->delete();
            
            // Supprimer les tokens API
            $user->tokens()->delete();
            
            // Supprimer l'utilisateur
            $user->delete();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Utilisateur supprimé avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la suppression d\'un utilisateur: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression de l\'utilisateur',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer la liste des compétences
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCompetences(Request $request)
    {
        $query = Competence::query();
        
        // Filtres
        if ($request->has('categorie') && $request->categorie !== 'all') {
            $query->where('categorie', $request->categorie);
        }
        
        if ($request->has('q') && !empty($request->q)) {
            $query->where('nom', 'like', "%{$request->q}%");
        }
        
        // Statistiques d'utilisation
        $withStats = $request->input('with_stats', false);
        if ($withStats) {
            $query->withCount(['etudiants', 'offres']);
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'nom');
        $sortDirection = $request->input('sort_dir', 'asc');
        $allowedSortFields = ['id', 'nom', 'categorie', 'created_at', 'etudiants_count', 'offres_count'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('nom', 'asc');
        }
        
        // Pagination
        $perPage = $request->input('per_page', 0);
        
        if ($perPage > 0) {
            $competences = $query->paginate($perPage);
        } else {
            $competences = $query->get();
        }
        
        // Récupérer les catégories uniques pour les filtres
        $categories = Competence::select('categorie')
            ->distinct()
            ->whereNotNull('categorie')
            ->pluck('categorie');
        
        return response()->json([
            'competences' => $competences,
            'categories' => $categories
        ]);
    }

    /**
     * Récupérer une compétence spécifique
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCompetence($id)
    {
        $competence = Competence::with([
            'etudiants' => function($q) {
                $q->select('etudiant_id', 'competence_id', 'niveau');
            }, 
            'offres' => function($q) {
                $q->select('offres.id', 'offres.titre', 'offres.entreprise_id', 'offres.type');
            }
        ])->findOrFail($id);
        
        return response()->json([
            'competence' => $competence
        ]);
    }

    /**
     * Créer une nouvelle compétence
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createCompetence(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255|unique:competences',
            'categorie' => 'nullable|string|max:255',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $competence = Competence::create([
            'nom' => $request->nom,
            'categorie' => $request->categorie ?? null
        ]);
        
        // Invalider le cache des compétences
        Cache::forget('competences_list');
        
        return response()->json([
            'message' => 'Compétence créée avec succès',
            'competence' => $competence
        ], 201);
    }

    /**
     * Mettre à jour une compétence
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCompetence(Request $request, $id)
    {
        $competence = Competence::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'nom' => 'sometimes|string|max:255|unique:competences,nom,'.$id,
            'categorie' => 'nullable|string|max:255',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $competence->update($request->all());
        
        // Invalider le cache des compétences
        Cache::forget('competences_list');
        
        return response()->json([
            'message' => 'Compétence mise à jour avec succès',
            'competence' => $competence
        ]);
    }

    /**
     * Supprimer une compétence
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteCompetence($id)
    {
        $competence = Competence::findOrFail($id);
        
        try {
            DB::beginTransaction();
            
            // Détacher la compétence des offres
            $competence->offres()->detach();
            
            // Détacher la compétence des étudiants
            $competence->etudiants()->detach();
            
            // Supprimer la compétence
            $competence->delete();
            
            DB::commit();
            
            // Invalider le cache des compétences
            Cache::forget('competences_list');
            
            return response()->json([
                'message' => 'Compétence supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la suppression d\'une compétence: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression de la compétence',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer la liste des offres
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOffres(Request $request)
    {
        $query = Offre::with(['entreprise', 'competences']);
        
        // Filtres
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        
        if ($request->has('statut') && $request->statut !== 'all') {
            $query->where('statut', $request->statut);
        }
        
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
        
        if ($request->has('competence_id')) {
            $query->whereHas('competences', function($q) use ($request) {
                $q->where('competences.id', $request->competence_id);
            });
        }
        
        // Compteurs
        $withCounts = $request->input('with_counts', false);
        if ($withCounts) {
            $query->withCount('candidatures');
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_dir', 'desc');
        
        // Gestion du tri par entreprise (champ relationnel)
        if ($sortBy === 'entreprise') {
            $query->join('entreprises', 'offres.entreprise_id', '=', 'entreprises.id')
                  ->orderBy('entreprises.nom_entreprise', $sortDirection)
                  ->select('offres.*');
        } else {
            $allowedSortFields = [
                'id', 'titre', 'type', 'statut', 'created_at', 
                'date_debut', 'remuneration', 'vues_count'
            ];
            
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->orderBy('created_at', 'desc');
            }
        }
        
        // Pagination
        $perPage = $request->input('per_page', 15);
        $offres = $query->paginate($perPage);
        
        return response()->json([
            'offres' => $offres
        ]);
    }

    /**
     * Récupérer une offre spécifique
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOffre($id)
    {
        $offre = Offre::with([
            'entreprise', 
            'competences', 
            'test.questions.reponses',
            'candidatures' => function($q) {
                $q->with('etudiant.user');
            }
        ])->findOrFail($id);
        
        return response()->json([
            'offre' => $offre
        ]);
    }

    /**
     * Mettre à jour une offre
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateOffre(Request $request, $id)
    {
        $offre = Offre::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'titre' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'type' => 'sometimes|in:stage,emploi,alternance',
            'statut' => 'sometimes|in:active,inactive,cloturee',
            'localisation' => 'sometimes|string|max:255',
            'remuneration' => 'nullable|numeric',
            'date_debut' => 'sometimes|date',
            'duree' => 'nullable|integer',
            'competences' => 'nullable|array',
            'competences.*' => 'exists:competences,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        DB::beginTransaction();
        
        try {
            // Mise à jour des champs de base
            $fieldsToUpdate = [
                'titre', 'description', 'type', 'statut', 'localisation',
                'remuneration', 'date_debut', 'duree', 'niveau_requis'
            ];
            
            foreach ($fieldsToUpdate as $field) {
                if ($request->has($field)) {
                    $offre->$field = $request->$field;
                }
            }
            
            $offre->save();
            
            // Mise à jour des compétences si fournies
            if ($request->has('competences')) {
                $offre->competences()->sync($request->competences);
            }
            
            DB::commit();
            
            // Invalider les caches associés
            Cache::forget("offre_{$id}_details");
            
            return response()->json([
                'message' => 'Offre mise à jour avec succès',
                'offre' => $offre->load(['competences', 'entreprise'])
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour d\'une offre: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour de l\'offre',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprimer une offre
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteOffre($id)
    {
        $offre = Offre::findOrFail($id);
        
        DB::beginTransaction();
        
        try {
            // Supprimer le test associé et ses questions/réponses
            if ($offre->test) {
                foreach ($offre->test->questions as $question) {
                    $question->reponses()->delete();
                }
                $offre->test->questions()->delete();
                $offre->test->delete();
            }
            
            // Supprimer les candidatures associées
            foreach ($offre->candidatures as $candidature) {
                $candidature->reponsesEtudiants()->delete();
                $candidature->delete();
            }
            
            // Détacher les compétences
            $offre->competences()->detach();
            
            // Supprimer l'offre
            $offre->delete();
            
            DB::commit();
            
            // Invalider les caches associés
            Cache::forget("offre_{$id}_details");
            
            return response()->json([
                'message' => 'Offre supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la suppression d\'une offre: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression de l\'offre',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer la liste des candidatures
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCandidatures(Request $request)
    {
        $query = Candidature::with(['etudiant.user', 'offre.entreprise']);
        
        // Filtres
        if ($request->has('statut') && $request->statut !== 'all') {
            $query->where('statut', $request->statut);
        }
        
        if ($request->has('offre_id')) {
            $query->where('offre_id', $request->offre_id);
        }
        
        if ($request->has('etudiant_id')) {
            $query->where('etudiant_id', $request->etudiant_id);
        }
        
        if ($request->has('entreprise_id')) {
            $query->whereHas('offre', function($q) use ($request) {
                $q->where('entreprise_id', $request->entreprise_id);
            });
        }
        
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('etudiant.user', function($q2) use ($search) {
                    $q2->where('nom', 'like', "%{$search}%")
                       ->orWhere('prenom', 'like', "%{$search}%");
                })->orWhereHas('offre', function($q2) use ($search) {
                    $q2->where('titre', 'like', "%{$search}%");
                });
            });
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'date_candidature');
        $sortDirection = $request->input('sort_dir', 'desc');
        
        $allowedSortFields = ['id', 'date_candidature', 'statut', 'score_test', 'date_entretien'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('date_candidature', 'desc');
        }
        
        // Pagination
        $perPage = $request->input('per_page', 15);
        $candidatures = $query->paginate($perPage);
        
        return response()->json([
            'candidatures' => $candidatures
        ]);
    }

    /**
     * Récupérer une candidature spécifique
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCandidature($id)
    {
        $candidature = Candidature::with([
            'etudiant.user', 
            'etudiant.competences', 
            'offre.entreprise',
            'offre.test',
            'reponsesEtudiants.question',
            'reponsesEtudiants.reponse'
        ])->findOrFail($id);
        
        return response()->json([
            'candidature' => $candidature
        ]);
    }

    /**
     * Récupérer les paramètres de l'application
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        $settings = Cache::remember('app.settings', now()->addHour(), function() {
            return Setting::pluck('value', 'key')->toArray();
        });
        
        // Organiser les paramètres en structure
        $formattedSettings = [
            'maintenance_mode' => (bool) ($settings['maintenance_mode'] ?? false),
            'allow_registrations' => (bool) ($settings['allow_registrations'] ?? true),
            'auto_approve_companies' => (bool) ($settings['auto_approve_companies'] ?? false),
            'email_notifications' => [
                'new_user' => (bool) ($settings['email_notifications_new_user'] ?? true),
                'new_offer' => (bool) ($settings['email_notifications_new_offer'] ?? true),
                'new_application' => (bool) ($settings['email_notifications_new_application'] ?? true),
            ],
            'max_file_size' => (int) ($settings['max_file_size'] ?? 5),
            'max_offers_per_company' => (int) ($settings['max_offers_per_company'] ?? 20),
        ];
        
        return response()->json([
            'settings' => $formattedSettings
        ]);
    }

    /**
     * Mettre à jour les paramètres de l'application
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'maintenance_mode' => 'boolean',
            'allow_registrations' => 'boolean',
            'auto_approve_companies' => 'boolean',
            'email_notifications.new_user' => 'boolean',
            'email_notifications.new_offer' => 'boolean',
            'email_notifications.new_application' => 'boolean',
            'max_file_size' => 'integer|min:1|max:20',
            'max_offers_per_company' => 'integer|min:1',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Aplatir et sauvegarder les paramètres
        $settings = [
            'maintenance_mode' => $request->input('maintenance_mode', false),
            'allow_registrations' => $request->input('allow_registrations', true),
            'auto_approve_companies' => $request->input('auto_approve_companies', false),
            'email_notifications_new_user' => $request->input('email_notifications.new_user', true),
            'email_notifications_new_offer' => $request->input('email_notifications.new_offer', true),
            'email_notifications_new_application' => $request->input('email_notifications.new_application', true),
            'max_file_size' => $request->input('max_file_size', 5),
            'max_offers_per_company' => $request->input('max_offers_per_company', 20),
        ];
        
        try {
            DB::beginTransaction();
            
            foreach ($settings as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $value]
                );
            }
            
            DB::commit();
            
            // Invalider le cache des paramètres
            Cache::forget('app.settings');
            
            // Activer/désactiver le mode maintenance si nécessaire
            if (array_key_exists('maintenance_mode', $settings)) {
                if ($settings['maintenance_mode']) {
                    Artisan::call('down');
                } else {
                    Artisan::call('up');
                }
            }
            
            return response()->json([
                'message' => 'Paramètres mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la mise à jour des paramètres: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour des paramètres',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les logs du système
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLogs(Request $request)
    {
        $logType = $request->input('type', 'laravel');
        $limit = $request->input('limit', 100);
        
        try {
            $logs = [];
            
            if ($logType === 'laravel') {
                // Récupérer le fichier de log Laravel le plus récent
                $logPath = storage_path('logs/laravel-' . date('Y-m-d') . '.log');
                
                if (file_exists($logPath)) {
                    $logContent = file_get_contents($logPath);
                    
                    // Parser le contenu pour extraire les entrées de log
                    preg_match_all('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*?)(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]|$)/s', $logContent, $matches, PREG_SET_ORDER);
                    
                    foreach (array_slice($matches, -$limit) as $match) {
                        $logs[] = [
                            'date' => $match[1],
                            'level' => $match[3],
                            'message' => trim($match[4])
                        ];
                    }
                    
                    // Trier par date décroissante
                    usort($logs, function($a, $b) {
                        return strtotime($b['date']) - strtotime($a['date']);
                    });
                }
            }
            
            return response()->json([
                'logs' => $logs
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des logs: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la récupération des logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activer/désactiver le mode maintenance
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function toggleMaintenanceMode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'enable' => 'required|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            // Mettre à jour le paramètre
            Setting::updateOrCreate(
                ['key' => 'maintenance_mode'],
                ['value' => $request->enable]
            );
            
            // Invalider le cache des paramètres
            Cache::forget('app.settings');
            
            // Activer/désactiver le mode maintenance
            if ($request->enable) {
                $message = $request->input('message', 'L\'application est en maintenance. Veuillez réessayer plus tard.');
                Artisan::call('down', ['--message' => $message]);
                
                return response()->json([
                    'message' => 'Mode maintenance activé'
                ]);
            } else {
                Artisan::call('up');
                
                return response()->json([
                    'message' => 'Mode maintenance désactivé'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Erreur lors de la modification du mode maintenance: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vider le cache de l'application
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache()
    {
        try {
            // Vider différents types de cache
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            
            return response()->json([
                'message' => 'Cache vidé avec succès',
                'details' => [
                    'cache' => 'Vidé',
                    'config' => 'Vidé',
                    'views' => 'Vidés',
                    'routes' => 'Vidés'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la purge du cache: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la purge du cache',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}