<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function getTests()
{
    $user = Auth::user();
    $etudiant = $user->etudiant;
    
    if (!$etudiant) {
        return response()->json([
            'message' => 'Profil étudiant non trouvé'
        ], 404);
    }
    
    // Récupérer les candidatures où un test est requis mais pas complété
    $candidaturesAvecTests = $etudiant->candidatures()
        ->with(['offre.test'])
        ->whereHas('offre', function($q) {
            $q->where('test_requis', true);
        })
        ->where('test_complete', false)
        ->where('statut', '!=', 'refusee')
        ->get();
    
    $tests = [];
    
    foreach ($candidaturesAvecTests as $candidature) {
        if ($candidature->offre->test) {
            $tests[] = [
                'id' => $candidature->offre->test->id,
                'titre' => $candidature->offre->test->titre,
                'description' => $candidature->offre->test->description,
                'duree_minutes' => $candidature->offre->test->duree_minutes,
                'offre_id' => $candidature->offre->id,
                'offre_titre' => $candidature->offre->titre,
                'entreprise' => $candidature->offre->entreprise->nom_entreprise,
                'candidature_id' => $candidature->id,
                'date_candidature' => $candidature->date_candidature
            ];
        }
    }
    
    return response()->json([
        'tests' => $tests
    ]);
}
}
