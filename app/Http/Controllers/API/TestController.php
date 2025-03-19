<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Test;
use App\Models\Question;
use App\Models\Reponse;
use App\Models\Candidature;
use App\Models\ReponseEtudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class TestController extends Controller
{
    /**
     * Récupérer les détails d'un test
     */
    public function show(Request $request, $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'etudiant') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $test = Test::with('offre.entreprise')->findOrFail($id);
        
        // Vérifier que l'étudiant a le droit de passer ce test
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
        
        // Récupérer les questions et réponses
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
        
        return response()->json([
            'test' => $test,
            'questions' => $questions
        ]);
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
        
        return response()->json([
            'message' => 'Test soumis avec succès',
            'score' => $score
        ]);
    }
}