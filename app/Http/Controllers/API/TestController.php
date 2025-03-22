<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\Question;
use App\Models\Reponse;
use App\Models\Candidature;
use App\Models\ReponseEtudiant;
use App\Models\Offre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    /**
     * Récupérer les détails d'un test
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user();
        
        // Récupérer le test avec ses questions et réponses
        $test = Test::with(['offre.entreprise'])->findOrFail($id);
        
        // Vérifier les permissions d'accès
        if ($user->role === 'etudiant') {
            // Pour les étudiants, vérifier qu'ils ont postulé à l'offre associée
            if (!$request->has('candidature_id')) {
                return response()->json([
                    'message' => 'ID de candidature requis'
                ], 400);
            }
            
            $candidatureId = $request->candidature_id;
            $candidature = Candidature::findOrFail($candidatureId);
            
            // Vérifier que la candidature appartient à cet étudiant
            if ($candidature->etudiant_id !== $user->etudiant->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
            
            // Vérifier que le test n'a pas déjà été complété
            if ($candidature->test_complete) {
                return response()->json([
                    'message' => 'Ce test a déjà été complété'
                ], 400);
            }
            
            // Pour les étudiants, ne pas montrer quelle réponse est correcte
            $questions = Question::where('test_id', $id)
                ->with('reponses')
                ->get()
                ->map(function($question) {
                    // Ne pas exposer quelle réponse est correcte
                    $question->reponses->each(function($reponse) {
                        unset($reponse->est_correcte);
                    });
                    return $question;
                });
        } else if ($user->role === 'entreprise') {
            // Pour les entreprises, vérifier qu'elles sont propriétaires de l'offre associée
            $offre = $test->offre;
            
            if ($offre->entreprise_id !== $user->entreprise->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
            
            // Pour les entreprises, montrer toutes les informations
            $questions = Question::where('test_id', $id)
                ->with('reponses')
                ->get();
        } else {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        return response()->json([
            'test' => $test,
            'questions' => $questions
        ]);
    }
    
    /**
     * Créer un nouveau test (pour les entreprises)
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Validation
        $validator = Validator::make($request->all(), [
            'offre_id' => 'required|exists:offres,id',
            'titre' => 'required|string|max:255',
            'description' => 'required|string',
            'duree_minutes' => 'required|integer|min:1',
            'questions' => 'required|array|min:1',
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
        
        // Vérifier que l'offre appartient à cette entreprise
        $offre = Offre::findOrFail($request->offre_id);
        
        if ($offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier qu'il n'y a pas déjà un test pour cette offre
        if ($offre->test()->exists()) {
            return response()->json([
                'message' => 'Un test existe déjà pour cette offre. Utilisez la méthode de mise à jour.'
            ], 400);
        }
        
        // Créer le test avec une transaction pour assurer l'intégrité
        DB::beginTransaction();
        
        try {
            // Créer le test
            $test = new Test();
            $test->offre_id = $offre->id;
            $test->titre = $request->titre;
            $test->description = $request->description;
            $test->duree_minutes = $request->duree_minutes;
            $test->save();
            
            // Créer les questions et réponses
            foreach ($request->questions as $questionData) {
                $question = new Question();
                $question->test_id = $test->id;
                $question->contenu = $questionData['contenu'];
                $question->save();
                
                // Vérifier qu'il y a au moins une réponse correcte
                $hasCorrectAnswer = false;
                foreach ($questionData['reponses'] as $reponseData) {
                    if ($reponseData['est_correcte']) {
                        $hasCorrectAnswer = true;
                        break;
                    }
                }
                
                if (!$hasCorrectAnswer) {
                    throw new \Exception("Chaque question doit avoir au moins une réponse correcte.");
                }
                
                // Créer les réponses
                foreach ($questionData['reponses'] as $reponseData) {
                    $reponse = new Reponse();
                    $reponse->question_id = $question->id;
                    $reponse->contenu = $reponseData['contenu'];
                    $reponse->est_correcte = $reponseData['est_correcte'];
                    $reponse->save();
                }
            }
            
            // Mettre à jour l'offre pour indiquer qu'un test est requis
            $offre->test_requis = true;
            $offre->save();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Test créé avec succès',
                'test' => $test->load('questions.reponses')
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la création du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Mettre à jour un test existant
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Récupérer le test
        $test = Test::with('offre')->findOrFail($id);
        
        // Vérifier que le test appartient à cette entreprise
        if ($test->offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Validation
        $validator = Validator::make($request->all(), [
            'titre' => 'string|max:255',
            'description' => 'string',
            'duree_minutes' => 'integer|min:1',
            'questions' => 'array|min:1',
            'questions.*.contenu' => 'required_with:questions|string',
            'questions.*.reponses' => 'required_with:questions|array|min:2',
            'questions.*.reponses.*.contenu' => 'required_with:questions.*.reponses|string',
            'questions.*.reponses.*.est_correcte' => 'required_with:questions.*.reponses|boolean'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Mettre à jour le test avec une transaction pour assurer l'intégrité
        DB::beginTransaction();
        
        try {
            // Mettre à jour les informations du test
            if ($request->has('titre')) $test->titre = $request->titre;
            if ($request->has('description')) $test->description = $request->description;
            if ($request->has('duree_minutes')) $test->duree_minutes = $request->duree_minutes;
            $test->save();
            
            // Si des questions sont fournies, mettre à jour les questions
            if ($request->has('questions')) {
                // Supprimer les anciennes questions et réponses
                // Cela pourrait être optimisé pour ne mettre à jour que ce qui a changé
                foreach ($test->questions as $question) {
                    $question->reponses()->delete();
                }
                $test->questions()->delete();
                
                // Créer les nouvelles questions et réponses
                foreach ($request->questions as $questionData) {
                    $question = new Question();
                    $question->test_id = $test->id;
                    $question->contenu = $questionData['contenu'];
                    $question->save();
                    
                    // Vérifier qu'il y a au moins une réponse correcte
                    $hasCorrectAnswer = false;
                    foreach ($questionData['reponses'] as $reponseData) {
                        if ($reponseData['est_correcte']) {
                            $hasCorrectAnswer = true;
                            break;
                        }
                    }
                    
                    if (!$hasCorrectAnswer) {
                        throw new \Exception("Chaque question doit avoir au moins une réponse correcte.");
                    }
                    
                    // Créer les réponses
                    foreach ($questionData['reponses'] as $reponseData) {
                        $reponse = new Reponse();
                        $reponse->question_id = $question->id;
                        $reponse->contenu = $reponseData['contenu'];
                        $reponse->est_correcte = $reponseData['est_correcte'];
                        $reponse->save();
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Test mis à jour avec succès',
                'test' => $test->load('questions.reponses')
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la mise à jour du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Supprimer un test
     */
    public function destroy($id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Récupérer le test
        $test = Test::with('offre')->findOrFail($id);
        
        // Vérifier que le test appartient à cette entreprise
        if ($test->offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Supprimer le test avec une transaction pour assurer l'intégrité
        DB::beginTransaction();
        
        try {
            // Mettre à jour l'offre pour indiquer qu'aucun test n'est requis
            $offre = $test->offre;
            $offre->test_requis = false;
            $offre->save();
            
            // Supprimer les réponses des étudiants
            ReponseEtudiant::whereHas('question', function($query) use ($id) {
                $query->where('test_id', $id);
            })->delete();
            
            // Supprimer les questions et réponses
            foreach ($test->questions as $question) {
                $question->reponses()->delete();
            }
            $test->questions()->delete();
            
            // Supprimer le test
            $test->delete();
            
            DB::commit();
            
            return response()->json([
                'message' => 'Test supprimé avec succès'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la suppression du test',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Soumettre les réponses à un test
     */
    public function submit(Request $request, $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'etudiant') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'candidature_id' => 'required|exists:candidatures,id',
            'reponses' => 'required|array'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $test = Test::findOrFail($id);
        $candidatureId = $request->candidature_id;
        $candidature = Candidature::findOrFail($candidatureId);
        
        // Vérifier que la candidature appartient à cet étudiant
        if ($candidature->etudiant_id !== $user->etudiant->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier que le test n'a pas déjà été complété
        if ($candidature->test_complete) {
            return response()->json([
                'message' => 'Ce test a déjà été complété'
            ], 400);
        }
        
        // Récupérer toutes les questions du test
        $questions = Question::where('test_id', $id)->with('reponses')->get();
        
        // Calculer le score
        $totalQuestions = $questions->count();
        $correctAnswers = 0;
        
        // Utiliser une transaction pour assurer l'intégrité des données
        DB::beginTransaction();
        
        try {
            foreach ($questions as $question) {
                // Récupérer la réponse de l'étudiant
                $reponseId = $request->reponses[$question->id] ?? null;
                
                if ($reponseId) {
                    // Enregistrer la réponse de l'étudiant
                    ReponseEtudiant::create([
                        'candidature_id' => $candidatureId,
                        'question_id' => $question->id,
                        'reponse_id' => $reponseId
                    ]);
                    
                    // Vérifier si la réponse est correcte
                    $reponse = Reponse::find($reponseId);
                    if ($reponse && $reponse->est_correcte) {
                        $correctAnswers++;
                    }
                }
            }
            
            // Calculer le score en pourcentage
            $score = $totalQuestions > 0 
                ? round(($correctAnswers / $totalQuestions) * 100) 
                : 0;
            
            // Mettre à jour la candidature
            $candidature->test_complete = true;
            $candidature->score_test = $score;
            $candidature->save();
            
            // Notifier l'entreprise
            $entreprise = $candidature->offre->entreprise;
            $entreprise->user->notifications()->create([
                'titre' => 'Test complété',
                'contenu' => "Un étudiant a complété le test pour l'offre: {$candidature->offre->titre} avec un score de {$score}%",
                'type' => 'test',
                'lien' => "/candidatures/{$candidature->id}"
            ]);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Test soumis avec succès',
                'score' => $score
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
     * Récupérer tous les tests d'une offre
     */
    public function getTestsByOffre($offreId)
    {
        $user = Auth::user();
        
        // Récupérer l'offre
        $offre = Offre::findOrFail($offreId);
        
        // Vérifier les permissions d'accès
        if ($user->role === 'entreprise') {
            // Vérifier que l'offre appartient à cette entreprise
            if ($offre->entreprise_id !== $user->entreprise->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
        }
        
        // Récupérer les tests associés à l'offre
        $tests = Test::where('offre_id', $offreId)
            ->with(['questions' => function($query) {
                $query->select('id', 'test_id', 'contenu');
            }])
            ->get();
        
        return response()->json([
            'tests' => $tests
        ]);
    }
    
    /**
     * Récupérer les résultats d'un test pour une candidature
     */
    public function getResults($testId, $candidatureId)
    {
        $user = Auth::user();
        
        // Récupérer la candidature
        $candidature = Candidature::with('offre.entreprise')->findOrFail($candidatureId);
        
        // Vérifier les permissions d'accès
        if ($user->role === 'etudiant') {
            // Vérifier que la candidature appartient à cet étudiant
            if ($candidature->etudiant_id !== $user->etudiant->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
        } else if ($user->role === 'entreprise') {
            // Vérifier que l'offre appartient à cette entreprise
            if ($candidature->offre->entreprise_id !== $user->entreprise->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
        } else {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier que le test a été complété
        if (!$candidature->test_complete) {
            return response()->json([
                'message' => 'Le test n\'a pas été complété'
            ], 400);
        }
        
        // Récupérer les réponses de l'étudiant
        $reponses = ReponseEtudiant::where('candidature_id', $candidatureId)
            ->whereHas('question', function($query) use ($testId) {
                $query->where('test_id', $testId);
            })
            ->with(['question.reponses', 'reponse'])
            ->get();
        
        return response()->json([
            'score' => $candidature->score_test,
            'reponses' => $reponses
        ]);
    }
}