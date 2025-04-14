<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Competence;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Récupérer les statistiques pour le dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        $stats = [
            'users' => User::count(),
            'students' => User::where('role', 'etudiant')->count(),
            'companies' => User::where('role', 'entreprise')->count(),
            'offers' => Offre::count(),
            'applications' => Candidature::count(),
            'competences' => Competence::count()
        ];

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
        
        // Filtres optionnels
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
        
        // Tri et pagination
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_dir', 'desc');
        $perPage = $request->input('per_page', 15);
        
        $allowedSortFields = ['id', 'nom', 'prenom', 'email', 'role', 'created_at'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }
        
        $users = $query->paginate($perPage);
        
        return response()->json([
            'users' => $users
        ]);
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
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user->update($request->all());
        
        return response()->json([
            'message' => 'Utilisateur mis à jour avec succès',
            'user' => $user
        ]);
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
                
                // Supprimer le profil étudiant
                $user->etudiant->delete();
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
        
        // Filtres optionnels
        if ($request->has('categorie') && $request->categorie !== 'all') {
            $query->where('categorie', $request->categorie);
        }
        
        if ($request->has('q') && !empty($request->q)) {
            $query->where('nom', 'like', "%{$request->q}%");
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'nom');
        $sortDirection = $request->input('sort_dir', 'asc');
        $allowedSortFields = ['id', 'nom', 'categorie', 'created_at'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderBy('nom', 'asc');
        }
        
        $competences = $query->get();
        
        return response()->json([
            'competences' => $competences
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
            
            return response()->json([
                'message' => 'Compétence supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
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
        
        // Filtres optionnels
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
                  ->orWhere('localisation', 'like', "%{$search}%");
            });
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
            $allowedSortFields = ['id', 'titre', 'type', 'statut', 'created_at', 'date_debut', 'remuneration'];
            
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->orderBy('created_at', 'desc');
            }
        }
        
        $offres = $query->paginate(15);
        
        return response()->json([
            'offres' => $offres
        ]);
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
            
            return response()->json([
                'message' => 'Offre supprimée avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression de l\'offre',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les paramètres de l'application
     *
     * @return \Illuminate\Http\JsonResponse
     * */
    public function getSettings()
    {
        $settings = Setting::pluck('value', 'key')->toArray();
        
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
            
            return response()->json([
                'message' => 'Paramètres mis à jour avec succès'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour des paramètres',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}