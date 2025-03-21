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

class EtudiantController extends Controller
{
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
    public function updateProfile(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $validator = Validator::make($request->all(), [
            'nom' => 'nullable|string|max:255',
            'prenom' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:20',
            'date_naissance' => 'nullable|date',
            'niveau_etude' => 'nullable|string|max:255',
            'filiere' => 'nullable|string|max:255',
            'ecole' => 'nullable|string|max:255',
            'annee_diplome' => 'nullable|integer',
            'linkedin_url' => 'nullable|url|max:255',
            'portfolio_url' => 'nullable|url|max:255',
            'disponibilite' => 'nullable|in:immédiate,1_mois,3_mois,6_mois',
            'photo' => 'nullable|image|max:2048'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        // Mettre à jour les informations utilisateur si fournies
        if ($request->has('nom')) {
            $user->nom = $request->nom;
        }
        
        if ($request->has('prenom')) {
            $user->prenom = $request->prenom;
        }
        
        if ($request->has('telephone')) {
            $user->telephone = $request->telephone;
        }
        
        // Traiter la photo si présente
        if ($request->hasFile('photo')) {
            // Supprimer l'ancienne photo si elle existe
            if ($user->photo && Storage::disk('public')->exists($user->photo)) {
                Storage::disk('public')->delete($user->photo);
            }
            
            $photoPath = $request->file('photo')->store('photos', 'public');
            $user->photo = $photoPath;
        }
        
        $user->save();
        
        // Mettre à jour les données du profil étudiant
        $etudiantData = $request->only([
            'date_naissance',
            'niveau_etude',
            'filiere',
            'ecole',
            'annee_diplome',
            'linkedin_url',
            'portfolio_url',
            'disponibilite'
        ]);
        
        $etudiant->update(array_filter($etudiantData));
        
        return response()->json([
            'message' => 'Profil mis à jour avec succès',
            'user' => $user,
            'etudiant' => $etudiant
        ]);
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
        
        // Supprimer l'ancien CV si existant
        if ($etudiant->cv_file && Storage::disk('public')->exists($etudiant->cv_file)) {
            Storage::disk('public')->delete($etudiant->cv_file);
        }
        
        // Enregistrer le nouveau CV
        $cvPath = $request->file('cv_file')->store('cv_files', 'public');
        $etudiant->cv_file = $cvPath;
        $etudiant->save();
        
        // TODO: Analyser le CV avec l'IA si implémenté
        // $this->analyzeCV($etudiant, $cvPath);
        
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
        $competences = $etudiant->competences()->get();
        
        return response()->json([
            'competences' => $competences
        ]);
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
        
        // Vérifier si l'étudiant possède cette compétence
        if (!$etudiant->competences()->where('competence_id', $competence_id)->exists()) {
            return response()->json([
                'message' => 'Compétence non trouvée'
            ], 404);
        }
        
        // Mettre à jour le niveau
        $etudiant->competences()->updateExistingPivot($competence_id, ['niveau' => $request->niveau]);
        
        $competence = Competence::find($competence_id);
        
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
        
        // Vérifier si l'étudiant possède cette compétence
        if (!$etudiant->competences()->where('competence_id', $competence_id)->exists()) {
            return response()->json([
                'message' => 'Compétence non trouvée'
            ], 404);
        }
        
        // Détacher la compétence
        $etudiant->competences()->detach($competence_id);
        
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
        
        $query = $etudiant->candidatures()->with(['offre', 'offre.entreprise']);
        
        // Filtrer par statut si spécifié
        if ($request->has('statut') && in_array($request->statut, ['en_attente', 'vue', 'entretien', 'acceptee', 'refusee'])) {
            $query->where('statut', $request->statut);
        }
        
        // Pagination
        $perPage = $request->input('per_page', 10);
        $candidatures = $query->orderBy('date_candidature', 'desc')->paginate($perPage);
        
        return response()->json($candidatures);
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
    }
    
    /**
     * Récupérer les offres recommandées
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecommendedOffers()
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        // Récupérer les IDs des compétences de l'étudiant
        $studentCompetenceIds = $etudiant->competences()->pluck('competence_id')->toArray();
        
        // Si l'étudiant n'a pas de compétences, recommander des offres récentes
        if (empty($studentCompetenceIds)) {
            $recommendedOffers = Offre::where('statut', 'active')
                ->whereNotIn('id', $etudiant->candidatures()->pluck('offre_id')->toArray())
                ->with(['entreprise', 'competences'])
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();
        } else {
            // Recommander des offres basées sur les compétences
            $recommendedOffers = Offre::whereHas('competences', function($query) use ($studentCompetenceIds) {
                    $query->whereIn('competence_id', $studentCompetenceIds);
                })
                ->where('statut', 'active')
                ->whereNotIn('id', $etudiant->candidatures()->pluck('offre_id')->toArray())
                ->with(['entreprise', 'competences'])
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();
        }
        
        return response()->json([
            'recommended_offers' => $recommendedOffers
        ]);
    }
    
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
        
        $query = $user->notifications();
        
        // Filtrer par statut de lecture si spécifié
        if ($request->has('read') && in_array($request->read, ['0', '1'])) {
            $query->where('lu', $request->read);
        }
        
        // Pagination
        $perPage = $request->input('per_page', 10);
        $notifications = $query->orderBy('created_at', 'desc')->paginate($perPage);
        
        return response()->json($notifications);
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
        
        $notification = $user->notifications()->find($notification_id);
        
        if (!$notification) {
            return response()->json([
                'message' => 'Notification non trouvée'
            ], 404);
        }
        
        $notification->lu = true;
        $notification->save();
        
        return response()->json([
            'message' => 'Notification marquée comme lue'
        ]);
    }
}