<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\Notification;
use App\Models\Offre;
use App\Models\Question;
use App\Models\Reponse;
use App\Models\ReponseEtudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EtudiantCandidatureController extends Controller
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
     * Récupérer les offres disponibles
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableOffers(Request $request)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        $query = Offre::where('statut', 'active')
            ->with(['entreprise', 'competences']);
        
        // Exclusion des offres déjà postulées
        $query->whereNotIn('id', $etudiant->candidatures()->pluck('offre_id'));
        
        // Filtres
        if ($request->has('type') && in_array($request->type, ['stage', 'emploi', 'alternance'])) {
            $query->where('type', $request->type);
        }
        
        if ($request->has('localisation')) {
            $query->where('localisation', 'like', '%' . $request->localisation . '%');
        }
        
        if ($request->has('competence')) {
            $query->whereHas('competences', function($q) use ($request) {
                $q->where('nom', 'like', '%' . $request->competence . '%');
            });
        }
        
        // Tri
        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_dir', 'desc');
        
        if (in_array($sortField, ['titre', 'created_at', 'remuneration', 'date_debut'])) {
            $query->orderBy($sortField, $sortDirection);
        }
        
        // Pagination
        $perPage = $request->input('per_page', 10);
        $offres = $query->paginate($perPage);
        
        return response()->json($offres);
    }
    
    /**
     * Récupérer les détails d'une offre
     *
     * @param  int  $offre_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOfferDetails($offre_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        $offre = Offre::with(['entreprise', 'competences'])
            ->where('statut', 'active')
            ->find($offre_id);
        
        if (!$offre) {
            return response()->json([
                'message' => 'Offre non trouvée ou inactive'
            ], 404);
        }
        
        // Vérifier si l'étudiant a déjà postulé
        $alreadyApplied = $etudiant->candidatures()->where('offre_id', $offre_id)->exists();
        
        // Calculer le matching de compétences
        $offreCompetences = $offre->competences()->pluck('competence_id')->toArray();
        $etudiantCompetences = $etudiant->competences()->pluck('competence_id')->toArray();
        
        $matchingCompetences = array_intersect($offreCompetences, $etudiantCompetences);
        $matchingPercentage = 0;
        
        if (count($offreCompetences) > 0) {
            $matchingPercentage = round((count($matchingCompetences) / count($offreCompetences)) * 100);
        }
        
        return response()->json([
            'offre' => $offre,
            'already_applied' => $alreadyApplied,
            'matching' => [
                'percentage' => $matchingPercentage,
                'matching_competences' => Competence::whereIn('id', $matchingCompetences)->get(),
                'missing_competences' => Competence::whereIn('id', array_diff($offreCompetences, $etudiantCompetences))->get()
            ]
        ]);
    }
    
    /**
     * Postuler à une offre
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $offre_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyToOffer(Request $request, $offre_id)
    {
     //Vérification de la réception des données     
    if (!$request->has('lettre_motivation')) {
        return response()->json([
            'message' => 'Données manquantes'
        ], 422);
    }
    // Ajouter des logs
    \Log::info('Tentative de candidature', [
        'user_id' => Auth::id(),
        'offre_id' => $offre_id,
        'request_data' => $request->all()
    ]);
    
    // Vérifier l'accès
    $accessCheck = $this->checkEtudiantAccess();
    if ($accessCheck) {
        \Log::error('Échec de vérification d\'accès', [
            'response' => $accessCheck->getContent()
        ]);
        return $accessCheck;
    }
    
    $validator = Validator::make($request->all(), [
        'lettre_motivation' => 'required|string|min:100',
    ]);
    
    if ($validator->fails()) {
        \Log::error('Échec de validation', [
            'errors' => $validator->errors()->toArray()
        ]);
        return response()->json([
            'message' => 'Données invalides',
            'errors' => $validator->errors()
        ], 422);
    }
    
    $etudiant = $this->getAuthEtudiant();
    
    // Vérifier si l'offre existe et est active
    $offre = Offre::where('statut', 'active')->find($offre_id);
    
    if (!$offre) {
        \Log::error('Offre non trouvée ou inactive', [
            'offre_id' => $offre_id
        ]);
        return response()->json([
            'message' => 'Offre non trouvée ou inactive'
        ], 404);
    }
    
    // Vérifier que l'étudiant n'a pas déjà postulé
    if ($etudiant->candidatures()->where('offre_id', $offre_id)->exists()) {
        \Log::error('Candidature déjà existante', [
            'etudiant_id' => $etudiant->id,
            'offre_id' => $offre_id
        ]);
        return response()->json([
            'message' => 'Vous avez déjà postulé à cette offre'
        ], 422);
    }
    
    // Vérifier que le CV de l'étudiant est bien renseigné
    if (empty($etudiant->cv_file)) {
        \Log::error('CV manquant', [
            'etudiant_id' => $etudiant->id
        ]);
        return response()->json([
            'message' => 'Vous devez télécharger votre CV avant de postuler',
            'code' => 'CV_REQUIRED'
        ], 422);
    }
    
    try {
        \Log::info('Début de la transaction DB pour candidature');
        DB::beginTransaction();
        $testId = null;
        if ($offre->test_requis) {
            $test = $offre->test()->first();
            $testId = $test ? $test->id : null;
        }
        // Créer la candidature
        $candidature = Candidature::create([
            'etudiant_id' => $etudiant->id,
            'offre_id' => $offre_id,
            'lettre_motivation' => $request->lettre_motivation,
            'statut' => 'en_attente',
            'test_complete' => false,
            'date_candidature' => now()
        ]);
        
        \Log::info('Candidature créée', [
            'candidature_id' => $candidature->id
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
        
        \Log::info('Notification créée pour l\'entreprise');
        
        DB::commit();
        \Log::info('Transaction DB réussie pour la candidature');
        
        return response()->json([
            'message' => 'Candidature envoyée avec succès',
            'candidature' => $candidature,
            'test_required' => $offre->test_requis,
            'test_id' => $testId
        ], 201);
        
    } catch (\Exception $e) {
        DB::rollBack();
        
        \Log::error('Erreur lors de l\'envoi de la candidature', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'message' => 'Une erreur est survenue lors de l\'envoi de la candidature',
            'error' => $e->getMessage()
        ], 500);
    }
    }
    
    /**
     * Récupérer le test associé à une candidature
     *
     * @param  int  $candidature_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTest($candidature_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        // Vérifier que la candidature existe et appartient à l'étudiant
        $candidature = $etudiant->candidatures()->find($candidature_id);
        
        if (!$candidature) {
            return response()->json([
                'message' => 'Candidature non trouvée'
            ], 404);
        }
        
        // Vérifier que l'offre associée nécessite un test
        $offre = $candidature->offre;
        
        if (!$offre->test_requis) {
            return response()->json([
                'message' => 'Cette offre ne nécessite pas de test'
            ], 422);
        }
        
        // Vérifier si le test a déjà été complété
        if ($candidature->test_complete) {
            return response()->json([
                'message' => 'Vous avez déjà complété ce test',
                'score' => $candidature->score_test
            ], 422);
        }
        
        // Récupérer le test avec ses questions et réponses
        $test = $offre->test()->with(['questions' => function($q) {
            // Pour chaque question, récupérer les réponses possibles sans indiquer laquelle est correcte
            $q->with(['reponses' => function($r) {
                $r->select('id', 'question_id', 'contenu');
            }]);
        }])->first();
        
        if (!$test) {
            return response()->json([
                'message' => 'Test non trouvé pour cette offre'
            ], 404);
        }
        
        return response()->json([
            'test' => $test,
            'duree_minutes' => $test->duree_minutes,
            'candidature_id' => $candidature_id
        ]);
    }
    
    /**
     * Soumettre les réponses au test
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $candidature_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitTest(Request $request, $candidature_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $validator = Validator::make($request->all(), [
            'reponses' => 'required|array',
            'reponses.*.question_id' => 'required|exists:questions,id',
            'reponses.*.reponse_id' => 'required|exists:reponses,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $this->getAuthEtudiant();
        
        // Vérifier que la candidature existe et appartient à l'étudiant
        $candidature = $etudiant->candidatures()->find($candidature_id);
        
        if (!$candidature) {
            return response()->json([
                'message' => 'Candidature non trouvée'
            ], 404);
        }
        
        // Vérifier si le test a déjà été complété
        if ($candidature->test_complete) {
            return response()->json([
                'message' => 'Vous avez déjà complété ce test',
                'score' => $candidature->score_test
            ], 422);
        }
        
        try {
            DB::beginTransaction();
            
            // Calculer le score
            $offre = $candidature->offre;
            $test = $offre->test;
            
            if (!$test) {
                return response()->json([
                    'message' => 'Test non trouvé pour cette offre'
                ], 404);
            }
            
            $totalQuestions = $test->questions()->count();
            $correctAnswers = 0;
            
            // Enregistrer les réponses et vérifier si elles sont correctes
            foreach ($request->reponses as $reponseData) {
                // Vérifier que la question appartient bien au test
                $question = Question::where('id', $reponseData['question_id'])
                    ->where('test_id', $test->id)
                    ->first();
                
                if (!$question) {
                    continue; // Ignorer cette réponse si la question n'appartient pas au test
                }
                
                // Vérifier si la réponse est correcte
                $reponse = Reponse::where('id', $reponseData['reponse_id'])
                    ->where('question_id', $reponseData['question_id'])
                    ->first();
                
                if ($reponse && $reponse->est_correcte) {
                    $correctAnswers++;
                }
                
                // Enregistrer la réponse de l'étudiant
                ReponseEtudiant::create([
                    'candidature_id' => $candidature_id,
                    'question_id' => $reponseData['question_id'],
                    'reponse_id' => $reponseData['reponse_id']
                ]);
            }
            
            // Calculer le score en pourcentage
            $score = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0;
            
            // Mettre à jour la candidature
            $candidature->score_test = $score;
            $candidature->test_complete = true;
            $candidature->save();
            
            // Créer une notification pour l'entreprise
            $notification = new Notification([
                'user_id' => $offre->entreprise->user_id,
                'titre' => 'Test complété',
                'contenu' => "L'étudiant {$etudiant->user->prenom} {$etudiant->user->nom} a complété le test pour l'offre : {$offre->titre} avec un score de {$score}%",
                'type' => 'test',
                'lu' => false
            ]);
            $notification->save();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Test soumis avec succès',
                'score' => $score,
                'correct_answers' => $correctAnswers,
                'total_questions' => $totalQuestions
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la soumission du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Annuler une candidature
     *
     * @param  int  $candidature_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelApplication($candidature_id)
    {
        // Vérifier l'accès
        $accessCheck = $this->checkEtudiantAccess();
        if ($accessCheck) return $accessCheck;
        
        $etudiant = $this->getAuthEtudiant();
        
        // Vérifier que la candidature existe et appartient à l'étudiant
        $candidature = $etudiant->candidatures()->find($candidature_id);
        
        if (!$candidature) {
            return response()->json([
                'message' => 'Candidature non trouvée'
            ], 404);
        }
        
        // Vérifier que la candidature peut être annulée (uniquement si elle est en attente ou vue)
        if (!in_array($candidature->statut, ['en_attente', 'vue'])) {
            return response()->json([
                'message' => 'Cette candidature ne peut plus être annulée',
                'statut' => $candidature->statut
            ], 422);
        }
        
        try {
            DB::beginTransaction();
            
            // Récupérer les informations nécessaires avant suppression
            $offre = $candidature->offre;
            $entrepriseUserId = $offre->entreprise->user_id;
            
            // Supprimer les réponses aux tests associées si elles existent
            ReponseEtudiant::where('candidature_id', $candidature_id)->delete();
            
            // Supprimer la candidature
            $candidature->delete();
            
            // Créer une notification pour l'entreprise
            $notification = new Notification([
                'user_id' => $entrepriseUserId,
                'titre' => 'Candidature annulée',
                'contenu' => "L'étudiant {$etudiant->user->prenom} {$etudiant->user->nom} a annulé sa candidature pour l'offre : {$offre->titre}",
                'type' => 'candidature',
                'lu' => false
            ]);
            $notification->save();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Candidature annulée avec succès'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'annulation de la candidature',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}