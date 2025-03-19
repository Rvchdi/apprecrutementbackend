<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CandidatureController extends Controller
{
    /**
     * Récupérer les détails d'une candidature
     */
    public function show($id)
    {
        $user = Auth::user();
        $candidature = Candidature::with(['etudiant.user', 'offre.entreprise', 'offre.competences'])->findOrFail($id);
        
        // Vérifier que l'utilisateur a le droit de voir cette candidature
        if ($user->role === 'etudiant') {
            if ($candidature->etudiant->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
        } elseif ($user->role === 'entreprise') {
            if ($candidature->offre->entreprise->user_id !== $user->id) {
                return response()->json([
                    'message' => 'Action non autorisée'
                ], 403);
            }
            
            // Marquer comme vue si c'est une entreprise qui consulte
            if ($candidature->statut === 'en_attente') {
                $candidature->statut = 'vue';
                $candidature->save();
                
                // Notifier l'étudiant
                $candidature->etudiant->user->notifications()->create([
                    'titre' => 'Candidature consultée',
                    'contenu' => "Votre candidature pour l'offre {$candidature->offre->titre} a été consultée par l'entreprise.",
                    'type' => 'candidature',
                    'lien' => "/candidatures/{$candidature->id}"
                ]);
            }
        }
        
        return response()->json($candidature);
    }
    
    /**
     * Mettre à jour une candidature (lettre de motivation)
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'etudiant') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'lettre_motivation' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $candidature = Candidature::findOrFail($id);
        
        // Vérifier que la candidature appartient à cet étudiant
        if ($candidature->etudiant->user_id !== $user->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $candidature->lettre_motivation = $request->lettre_motivation;
        $candidature->save();
        
        return response()->json([
            'message' => 'Candidature mise à jour avec succès',
            'candidature' => $candidature
        ]);
    }
    
    /**
     * Annuler une candidature
     */
    public function cancel($id)
    {
        $user = Auth::user();
        
        if ($user->role !== 'etudiant') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $candidature = Candidature::findOrFail($id);
        
        // Vérifier que la candidature appartient à cet étudiant
        if ($candidature->etudiant->user_id !== $user->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier si on peut annuler la candidature
        if (in_array($candidature->statut, ['acceptee', 'refusee'])) {
            return response()->json([
                'message' => 'Impossible d\'annuler une candidature acceptée ou refusée'
            ], 400);
        }
        
        // Supprimer la candidature
        $candidature->delete();
        
        return response()->json([
            'message' => 'Candidature annulée avec succès'
        ]);
    }
}