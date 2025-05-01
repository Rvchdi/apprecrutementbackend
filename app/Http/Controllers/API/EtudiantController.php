<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\Competence;
use App\Models\Etudiant;
use App\Models\Notification;
use App\Models\Offre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;

class EtudiantController extends Controller
{
    /**
     * Durée du cache en minutes
     */
    protected const CACHE_DURATION = 60; // 1 heure

    /**
     * Vérifier que l'utilisateur connecté est bien un étudiant
     */
    private function checkEtudiantAccess()
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Accès non autorisé. Seuls les étudiants peuvent accéder à cette ressource.'
            ], 403);
        }
        
        return null;
    }
    
    /**
     * Récupérer l'étudiant associé à l'utilisateur connecté
     */
    private function getAuthEtudiant()
    {
        $user = Auth::user();
        return $user->etudiant;
    }

    /**
     * Obtenir un résumé des données du dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSummary()
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        $userId = Auth::id();

        // Utiliser le cache pour le résumé du dashboard
        return Cache::remember("etudiant.{$etudiant->id}.summary", self::CACHE_DURATION, function () use ($etudiant, $userId) {
            // Récupérer les données nécessaires pour le résumé
            $totalCandidatures = $etudiant->candidatures()->count();
            $pendingCandidatures = $etudiant->candidatures()->where('statut', 'en_attente')->count();
            $interviewCandidatures = $etudiant->candidatures()->where('statut', 'entretien')->count();
            $acceptedCandidatures = $etudiant->candidatures()->where('statut', 'acceptee')->count();
            
            $recentCandidatures = $etudiant->candidatures()
                ->with('offre.entreprise')
                ->orderBy('date_candidature', 'desc')
                ->take(5)
                ->get();
                
            $unreadNotifications = $etudiant->user->notifications()
                ->where('lu', false)
                ->count();
                
            $competencesCount = $etudiant->competences()->count();
            
            // Calculer le taux de complétion du profil
            $profileFields = [
                $etudiant->date_naissance,
                $etudiant->niveau_etude,
                $etudiant->filiere,
                $etudiant->ecole,
                $etudiant->cv_file,
                $etudiant->linkedin_url,
                $etudiant->disponibilite
            ];
            
            $completedFields = 0;
            foreach ($profileFields as $field) {
                if (!empty($field)) {
                    $completedFields++;
                }
            }
            
            $profileCompletionRate = round(($completedFields / count($profileFields)) * 100);
            
            // Récupérer les offres recommandées en fonction des compétences de l'étudiant
            $studentCompetenceIds = $etudiant->competences()->pluck('competence_id')->toArray();
            
            $recommendedOffers = Offre::whereHas('competences', function($query) use ($studentCompetenceIds) {
                    $query->whereIn('competence_id', $studentCompetenceIds);
                })
                ->where('statut', 'active')
                ->whereNotIn('id', $etudiant->candidatures()->pluck('offre_id')->toArray())
                ->with('entreprise')
                ->take(3)
                ->get();
            
            return response()->json([
                'candidatures' => [
                    'total' => $totalCandidatures,
                    'pending' => $pendingCandidatures,
                    'interview' => $interviewCandidatures,
                    'accepted' => $acceptedCandidatures,
                    'recent' => $recentCandidatures
                ],
                'notifications' => [
                    'unread' => $unreadNotifications
                ],
                'profile' => [
                    'completion_rate' => $profileCompletionRate,
                    'competences_count' => $competencesCount
                ],
                'recommended_offers' => $recommendedOffers
            ]);
        });
    }
    
    /**
     * Récupérer le profil de l'étudiant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile()
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $user = Auth::user();
        $etudiantId = $user->etudiant->id;
        
        // Utiliser le cache pour le profil
            $etudiant = $user->etudiant()->with('competences')->first();
            
            // Préparer l'URL pour le CV si présent
            if (!empty($etudiant->cv_file)) {
                $etudiant->cv_url = url('storage/'.$etudiant->cv_file);
            }
            
            return response()->json([
                'user' => [
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'photo' => $user->photo ? url('storage/'.$user->photo) : null
                ],
                'etudiant' => $etudiant
            ]);
        
    }
    
    /**
     * Mettre à jour le profil de l'étudiant
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        // Vérifier que l'utilisateur connecté est bien un étudiant
        $user = Auth::user();
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Accès non autorisé. Seuls les étudiants peuvent modifier ce profil.'
            ], 403);
        }

        // Récupérer l'étudiant associé à l'utilisateur
        $etudiant = $user->etudiant;
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }

        // Validation des données
        $validator = Validator::make($request->all(), [
            'nom' => 'nullable|string|max:255',
            'prenom' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|max:2048',
            'date_naissance' => 'nullable|date',
            'adresse' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:20',
            'pays' => 'nullable|string|max:100',
            'niveau_etude' => 'nullable|string|max:50',
            'filiere' => 'nullable|string|max:255',
            'ecole' => 'nullable|string|max:255',
            'annee_diplome' => 'nullable|integer',
            'disponibilite' => 'nullable|in:immédiate,1_mois,3_mois,6_mois',
            'linkedin_url' => 'nullable|url|max:255',
            'portfolio_url' => 'nullable|url|max:255',
            'cv_file' => 'nullable|file|mimes:pdf|max:5120',
            'competences' => 'nullable|json'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }

        // Démarrer une transaction pour assurer l'intégrité des données
        DB::beginTransaction();

        try {
            // Mettre à jour les informations de l'utilisateur
            if ($request->filled('nom')) {
                $user->nom = $request->nom;
            }
            
            if ($request->filled('prenom')) {
                $user->prenom = $request->prenom;
            }
            
            if ($request->filled('telephone')) {
                $user->telephone = $request->telephone;
            }

            // Traiter la photo de profil
            if ($request->hasFile('photo')) {
                // Supprimer l'ancienne photo si elle existe
                if ($user->photo && Storage::disk('public')->exists($user->photo)) {
                    Storage::disk('public')->delete($user->photo);
                }
                
                // Stocker la nouvelle photo
                $photoPath = $request->file('photo')->store('photos', 'public');
                $user->photo = $photoPath;
            }

            // Sauvegarder les modifications de l'utilisateur
            $user->save();

            // Mettre à jour les informations de l'étudiant
            $etudiantFields = [
                'date_naissance', 'adresse', 'ville', 'code_postal', 'pays',
                'niveau_etude', 'filiere', 'ecole', 'annee_diplome',
                'disponibilite', 'linkedin_url', 'portfolio_url'
            ];

            foreach ($etudiantFields as $field) {
                if ($request->filled($field)) {
                    $etudiant->$field = $request->$field;
                }
            }

            // Traiter le CV
            if ($request->hasFile('cv_file')) {
                // Supprimer l'ancien CV s'il existe
                if ($etudiant->cv_file && Storage::disk('public')->exists($etudiant->cv_file)) {
                    Storage::disk('public')->delete($etudiant->cv_file);
                }
                
                // Stocker le nouveau CV
                $cvPath = $request->file('cv_file')->store('cv_files', 'public');
                $etudiant->cv_file = $cvPath;
            }

            // Sauvegarder les modifications de l'étudiant
            $etudiant->save();

            // Gérer les compétences si elles sont fournies
            if ($request->filled('competences')) {
                $competencesData = json_decode($request->competences, true);
                
                if (is_array($competencesData)) {
                    // Supprimer toutes les compétences actuelles
                    $etudiant->competences()->detach();
                    
                    // Ajouter les nouvelles compétences
                    foreach ($competencesData as $competenceData) {
                        if (isset($competenceData['id']) && isset($competenceData['niveau'])) {
                            $etudiant->competences()->attach($competenceData['id'], [
                                'niveau' => $competenceData['niveau']
                            ]);
                        }
                    }
                }
            }

            // Valider la transaction
            DB::commit();

            // Construire la réponse
            $responseData = [
                'message' => 'Profil mis à jour avec succès',
                'user' => [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'email' => $user->email,
                    'telephone' => $user->telephone,
                    'photo' => $user->photo ? url('storage/'.$user->photo) : null
                ],
                'etudiant' => $etudiant->toArray()
            ];

            // Ajouter l'URL du CV s'il existe
            if ($etudiant->cv_file) {
                $responseData['etudiant']['cv_url'] = url('storage/'.$etudiant->cv_file);
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            // Annuler la transaction en cas d'erreur
            DB::rollBack();
            
            Log::error('Erreur lors de la mise à jour du profil étudiant', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour du profil',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Télécharger un nouveau CV
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadCV(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $validator = Validator::make($request->all(), [
            'cv_file' => 'required|file|mimes:pdf|max:5120',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Fichier invalide',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Supprimer l'ancien CV si existant
        if ($etudiant->cv_file && Storage::disk('public')->exists($etudiant->cv_file)) {
            Storage::disk('public')->delete($etudiant->cv_file);
        }
        
        // Enregistrer le nouveau CV
        $cvPath = $request->file('cv_file')->store('cv_files', 'public');
        $etudiant->cv_file = $cvPath;
        $etudiant->save();
        
        // Invalider les caches liés à cet étudiant
        Cache::forget("etudiant.{$etudiantId}.profile");
        Cache::forget("etudiant.{$etudiantId}.summary");
        
        return response()->json([
            'message' => 'CV téléchargé avec succès',
            'cv_url' => url('storage/'.$cvPath)
        ]);
    }
    
    /**
     * Récupérer les compétences de l'étudiant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCompetences()
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Utiliser le cache pour les compétences
        return Cache::remember("etudiant.{$etudiantId}.competences", self::CACHE_DURATION, function () use ($etudiant) {
            $competences = $etudiant->competences()->get();
            
            return response()->json([
                'competences' => $competences
            ]);
        });
    }
    
    /**
     * Ajouter une compétence à l'étudiant
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addCompetence(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255',
            'niveau' => 'required|in:débutant,intermédiaire,avancé,expert',
            'categorie' => 'nullable|string|max:255'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Vérifier si la compétence existe déjà
        $competence = Competence::firstOrCreate(
            ['nom' => $request->nom],
            ['categorie' => $request->categorie ?? null]
        );
        
        // Vérifier si l'étudiant possède déjà cette compétence
        if ($etudiant->competences()->where('competence_id', $competence->id)->exists()) {
            return response()->json([
                'message' => 'Vous possédez déjà cette compétence'
            ], 422);
        }
        
        // Associer la compétence à l'étudiant avec le niveau spécifié
        $etudiant->competences()->attach($competence->id, ['niveau' => $request->niveau]);
        
        // Invalider les caches liés aux compétences et au résumé
        Cache::forget("etudiant.{$etudiantId}.competences");
        Cache::forget("etudiant.{$etudiantId}.summary");
        Cache::forget("etudiant.{$etudiantId}.recommended_offers");
        
        return response()->json([
            'message' => 'Compétence ajoutée avec succès',
            'competence' => $competence
        ], 201);
    }
    
    /**
     * Mettre à jour le niveau d'une compétence
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $competence_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCompetenceLevel(Request $request, $competence_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $validator = Validator::make($request->all(), [
            'niveau' => 'required|in:débutant,intermédiaire,avancé,expert',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Niveau invalide',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Vérifier si l'étudiant possède cette compétence
        if (!$etudiant->competences()->where('competence_id', $competence_id)->exists()) {
            return response()->json([
                'message' => 'Compétence non trouvée'
            ], 404);
        }
        
        // Mettre à jour le niveau
        $etudiant->competences()->updateExistingPivot($competence_id, ['niveau' => $request->niveau]);
        
        $competence = Competence::find($competence_id);
        
        // Invalider les caches liés aux compétences
        Cache::forget("etudiant.{$etudiantId}.competences");
        
        return response()->json([
            'message' => 'Niveau de compétence mis à jour',
            'competence' => $competence,
            'niveau' => $request->niveau
        ]);
    }
    
    /**
     * Supprimer une compétence
     *
     * @param  int  $competence_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeCompetence($competence_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Vérifier si l'étudiant possède cette compétence
        if (!$etudiant->competences()->where('competence_id', $competence_id)->exists()) {
            return response()->json([
                'message' => 'Compétence non trouvée'
            ], 404);
        }
        
        // Détacher la compétence
        $etudiant->competences()->detach($competence_id);
        
        // Invalider les caches liés aux compétences et au résumé
        Cache::forget("etudiant.{$etudiantId}.competences");
        Cache::forget("etudiant.{$etudiantId}.summary");
        Cache::forget("etudiant.{$etudiantId}.recommended_offers");
        
        return response()->json([
            'message' => 'Compétence supprimée avec succès'
        ]);
    }
    
    /**
     * Récupérer les candidatures de l'étudiant
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCandidatures(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        // Construire la clé de cache en tenant compte des filtres
        $statut = $request->has('statut') ? $request->statut : 'all';
        $perPage = $request->input('per_page', 10);
        $cacheKey = "etudiant.{$etudiantId}.candidatures.{$statut}.{$perPage}.{$request->page}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($etudiant, $request) {
            $query = $etudiant->candidatures()->with(['offre', 'offre.entreprise']);
            
            // Filtrer par statut si spécifié
            if ($request->has('statut') && in_array($request->statut, ['en_attente', 'vue', 'entretien', 'acceptee', 'refusee'])) {
                $query->where('statut', $request->statut);
            }
            
            // Pagination
            $perPage = $request->input('per_page', 10);
            $candidatures = $query->orderBy('date_candidature', 'desc')->paginate($perPage);
            
            return response()->json($candidatures);
        });
    }
    
    /**
     * Récupérer les détails d'une candidature
     *
     * @param  int  $candidature_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCandidatureDetails($candidature_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        $etudiantId = $etudiant->id;
        
        return Cache::remember("etudiant.{$etudiantId}.candidature.{$candidature_id}", self::CACHE_DURATION, function () use ($etudiant, $candidature_id) {
            $candidature = $etudiant->candidatures()
                ->with(['offre', 'offre.entreprise', 'offre.competences'])
                ->find($candidature_id);
            
            if (!$candidature) {
                return response()->json([
                    'message' => 'Candidature non trouvée'
                ], 404);
            }
            
            // Si la candidature a un test, vérifier le statut
            if ($candidature->offre->test_requis) {
                $candidature->test = $candidature->offre->test;
                $candidature->test_status = $candidature->test_complete ? 'complété' : 'à faire';
            }
            
            return response()->json([
                'candidature' => $candidature
            ]);
        });
    }
    
    public function getEtudiantCandidatures()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        $etudiantId = $etudiant->id;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        return Cache::remember("etudiant.{$etudiantId}.all_candidatures", self::CACHE_DURATION, function () use ($etudiant) {
            $candidatures = $etudiant->candidatures()
                ->with(['offre.entreprise', 'offre.competences'])
                ->orderBy('date_candidature', 'desc')
                ->get();
            
            return response()->json([
                'candidatures' => $candidatures
            ]);
        });
    }
    
    /**
     * Récupérer les offres recommandées
     *
     * @return \Illuminate\Http\JsonResponse
     */
    
    
    /**
     * Récupérer les notifications de l'étudiant
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getNotifications(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $user = Auth::user();
        $userId = $user->id;
        
        // Pour les notifications, le cache a une durée plus courte
        $read = $request->has('read') ? $request->read : 'all';
        $perPage = $request->input('per_page', 10);
        $cacheKey = "user.{$userId}.notifications.{$read}.{$perPage}.{$request->page}";
        
        return Cache::remember($cacheKey, 5, function () use ($user, $request) { // 5 minutes seulement
            $query = $user->notifications();
            
            // Filtrer par statut de lecture si spécifié
            if ($request->has('read') && in_array($request->read, ['0', '1'])) {
                $query->where('lu', $request->read);
            }
            
            // Pagination
            $perPage = $request->input('per_page', 10);
            $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);
            
            return response()->json($notifications);
        });
    }
    
    /**
     * Marquer une notification comme lue
     *
     * @param  int  $notification_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markNotificationAsRead($notification_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $user = Auth::user();
        $userId = $user->id;
        
        $notification = $user->notifications()->find($notification_id);
        
        if (!$notification) {
            return response()->json([
                'message' => 'Notification non trouvée'
            ], 404);
        }
        
        $notification->lu = true;
        $notification->save();
        
        // Invalider les caches de notifications
        Cache::forget("user.{$userId}.notifications.all");
        Cache::forget("user.{$userId}.notifications.0");
        Cache::forget("user.{$userId}.notifications.1");
        Cache::forget("etudiant.{$user->etudiant->id}.summary");
        
        return response()->json([
            'message' => 'Notification marquée comme lue'
        ]);
    }
    
    public function getTests()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        $etudiantId = $etudiant->id;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        return Cache::remember("etudiant.{$etudiantId}.tests", self::CACHE_DURATION, function () use ($etudiant) {
            // Récupérer les candidatures où un test est requis mais pas complété
            $candidaturesAvecTests = Candidature::with(['offre.test', 'offre.entreprise'])
                ->where('etudiant_id', $etudiant->id)
                ->whereHas('offre', function($query) {
                    $query->where('test_requis', true);
                })
                ->where('test_complete', false)
                ->where('statut', '!=', 'refusee')
                ->get();
            
            $tests = $candidaturesAvecTests->map(function($candidature) {
                $test = $candidature->offre->test;
                return [
                    'id' => $test->id,
                    'titre' => $test->titre,
                    'description' => $test->description,
                    'duree_minutes' => $test->duree_minutes,
                    'offre_id' => $candidature->offre->id,
                    'offre_titre' => $candidature->offre->titre,
                    'entreprise' => $candidature->offre->entreprise->nom_entreprise,
                    'candidature_id' => $candidature->id,
                    'date_candidature' => $candidature->date_candidature
                ];
            });
            
            return response()->json([
                'tests' => $tests
            ]);
        });
    }
    public function getRecommendedOffers(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Récupérer les paramètres optionnels de filtrage
        $limit = $request->input('limit', 10);
        $includeApplied = $request->input('include_applied', false);
        
        // Construire la requête de base pour les offres actives
        $query = Offre::where('statut', 'active')
            ->with(['entreprise', 'competences']);
        
        // Exclure les offres auxquelles l'étudiant a déjà postulé, sauf si explicitement demandé
        if (!$includeApplied) {
            $appliedOfferIds = $etudiant->candidatures()->pluck('offre_id')->toArray();
            if (!empty($appliedOfferIds)) {
                $query->whereNotIn('id', $appliedOfferIds);
            }
        }
        
        // Facteurs de recommandation et leurs poids relatifs
        $scoreFactors = [
            'competences' => 0.5,     // 50% du score basé sur les compétences
            'localisation' => 0.2,    // 20% du score basé sur la localisation
            'disponibilite' => 0.2,   // 20% du score basé sur la disponibilité
            'dateFraicheur' => 0.1    // 10% du score basé sur la fraîcheur de l'offre
        ];
        
        // Récupérer toutes les offres potentielles
        $offres = $query->get();
        
        // Récupérer les compétences de l'étudiant et les convertir en tableau associatif nom => niveau
        $etudiantCompetences = $etudiant->competences->pluck('pivot.niveau', 'nom')->toArray();
        $etudiantCompetenceIds = $etudiant->competences->pluck('id')->toArray();
        
        // Récupérer la disponibilité de l'étudiant (convertir en nombre de mois)
        $disponibiliteEtudiant = $this->convertDisponibiliteToMonths($etudiant->disponibilite);
        
        // Récupérer la ville et le pays de l'étudiant
        $villeEtudiant = strtolower($etudiant->ville);
        $paysEtudiant = strtolower($etudiant->pays);
        
        // Calculer les scores pour chaque offre
        $scoredOffers = [];
        
        foreach ($offres as $offre) {
            $score = 0;
            
            // 1. Score basé sur les compétences
            $competenceScore = $this->calculateCompetenceScore($offre, $etudiantCompetenceIds);
            $score += $competenceScore * $scoreFactors['competences'];
            
            // 2. Score basé sur la localisation
            $localisationScore = $this->calculateLocationScore($offre, $villeEtudiant, $paysEtudiant);
            $score += $localisationScore * $scoreFactors['localisation'];
            
            // 3. Score basé sur la disponibilité (type d'offre et durée)
            $disponibiliteScore = $this->calculateDisponibiliteScore($offre, $disponibiliteEtudiant);
            $score += $disponibiliteScore * $scoreFactors['disponibilite'];
            
            // 4. Score basé sur la fraîcheur de l'offre
            $fraicheurScore = $this->calculateFraicheurScore($offre);
            $score += $fraicheurScore * $scoreFactors['dateFraicheur'];
            
            // Ajouter la correspondance en pourcentage arrondi à l'offre
            $offre->match_percentage = round($score * 100);
            
            // Ajouter les détails des scores individuels pour le débogage si nécessaire
            $offre->score_details = [
                'competences' => round($competenceScore * 100),
                'localisation' => round($localisationScore * 100),
                'disponibilite' => round($disponibiliteScore * 100),
                'fraicheur' => round($fraicheurScore * 100)
            ];
            
            $scoredOffers[] = $offre;
        }
        
        // Trier les offres par score décroissant
        usort($scoredOffers, function($a, $b) {
            return $b->match_percentage <=> $a->match_percentage;
        });
        
        // Limiter le nombre de résultats
        $scoredOffers = array_slice($scoredOffers, 0, $limit);
        
        return response()->json([
            'recommended_offers' => $scoredOffers,
            'etudiant_profile' => [
                'competences' => $etudiantCompetences,
                'disponibilite' => $etudiant->disponibilite,
                'ville' => $etudiant->ville
            ]
        ]);
    }

    /**
     * Calculer le score de correspondance des compétences entre une offre et l'étudiant
     *
     * @param  \App\Models\Offre  $offre
     * @param  array  $etudiantCompetenceIds
     * @return float
     */
    private function calculateCompetenceScore($offre, $etudiantCompetenceIds)
    {
        // Si l'offre n'a pas de compétences requises, score moyen
        if ($offre->competences->isEmpty()) {
            return 0.5;
        }
        
        // Récupérer les IDs des compétences requises par l'offre
        $offreCompetenceIds = $offre->competences->pluck('id')->toArray();
        
        // Calculer le nombre de compétences en commun
        $matchingCompetences = array_intersect($offreCompetenceIds, $etudiantCompetenceIds);
        $matchingCount = count($matchingCompetences);
        
        // Si l'étudiant n'a aucune compétence de l'offre, score très bas mais non nul
        if ($matchingCount === 0) {
            return 0.1;
        }
        
        // Calculer le ratio de correspondance
        $totalOffreCompetences = count($offreCompetenceIds);
        $ratio = $matchingCount / $totalOffreCompetences;
        
        // Appliquer une fonction qui favorise une correspondance élevée
        // (ex: 0.8 de correspondance donne un score > 0.8, 0.2 donne un score < 0.2)
        return pow($ratio, 0.8); // Exposant < 1 pour favoriser les correspondances partielles
    }

    /**
     * Calculer le score de correspondance de localisation entre une offre et l'étudiant
     *
     * @param  \App\Models\Offre  $offre
     * @param  string  $villeEtudiant
     * @param  string  $paysEtudiant
     * @return float
     */
    private function calculateLocationScore($offre, $villeEtudiant, $paysEtudiant)
    {
        // Normaliser les localisations pour la comparaison
        $localisationOffre = strtolower($offre->localisation);
        
        // Même ville: score parfait
        if (str_contains($localisationOffre, $villeEtudiant) || str_contains($villeEtudiant, $localisationOffre)) {
            return 1.0;
        }
        
        // Même pays mais pas même ville: score moyen
        if (str_contains($localisationOffre, $paysEtudiant) || str_contains($paysEtudiant, $localisationOffre)) {
            return 0.5;
        }
        
        // Ni même ville ni même pays: score faible
        return 0.1;
    }

    /**
     * Calculer le score de correspondance de disponibilité entre une offre et l'étudiant
     *
     * @param  \App\Models\Offre  $offre
     * @param  int  $disponibiliteEtudiantMois
     * @return float
     */
    private function calculateDisponibiliteScore($offre, $disponibiliteEtudiantMois)
    {
        // Pour les offres d'emploi, on considère que c'est un engagement à long terme
        if ($offre->type === 'emploi') {
            // Si l'étudiant est disponible immédiatement ou dans 1 mois, score élevé
            if ($disponibiliteEtudiantMois <= 1) {
                return 0.9;
            }
            // Si l'étudiant est disponible dans 3 mois, score moyen
            else if ($disponibiliteEtudiantMois <= 3) {
                return 0.6;
            }
            // Si l'étudiant est disponible dans 6 mois, score faible
            else {
                return 0.3;
            }
        }
        
        // Pour les stages et alternances, vérifier si la durée correspond à la disponibilité
        if ($offre->type === 'stage' || $offre->type === 'alternance') {
            // Si pas de durée spécifiée, score moyen
            if (!$offre->duree) {
                return 0.5;
            }
            
            // Si l'étudiant est disponible pour toute la durée du stage/alternance
            if ($disponibiliteEtudiantMois >= $offre->duree) {
                return 1.0;
            }
            
            // Si l'étudiant est disponible pour au moins 75% de la durée
            if ($disponibiliteEtudiantMois >= $offre->duree * 0.75) {
                return 0.8;
            }
            
            // Si l'étudiant est disponible pour au moins 50% de la durée
            if ($disponibiliteEtudiantMois >= $offre->duree * 0.5) {
                return 0.5;
            }
            
            // Si l'étudiant n'est pas disponible pour au moins 50% de la durée
            return 0.2;
        }
        
        // Par défaut, score moyen
        return 0.5;
    }

    /**
     * Calculer le score de fraîcheur d'une offre
     *
     * @param  \App\Models\Offre  $offre
     * @return float
     */
    private function calculateFraicheurScore($offre)
    {
        $now = now();
        $offreDate = $offre->created_at;
        $diffInDays = $now->diffInDays($offreDate);
        
        // Offre de moins de 3 jours: score parfait
        if ($diffInDays < 3) {
            return 1.0;
        }
        
        // Offre de moins de 7 jours: score élevé
        if ($diffInDays < 7) {
            return 0.9;
        }
        
        // Offre de moins de 14 jours: score bon
        if ($diffInDays < 14) {
            return 0.7;
        }
        
        // Offre de moins de 30 jours: score moyen
        if ($diffInDays < 30) {
            return 0.5;
        }
        
        // Offre de plus de 30 jours: score faible
        return 0.3;
    }

    /**
     * Convertir la disponibilité textuelle en nombre de mois
     *
     * @param  string  $disponibilite
     * @return int
     */
    private function convertDisponibiliteToMonths($disponibilite)
    {
        switch ($disponibilite) {
            case 'immédiate':
                return 0;
            case '1_mois':
                return 1;
            case '3_mois':
                return 3;
            case '6_mois':
                return 6;
            default:
                return 3; // Valeur par défaut
        }
    }
}