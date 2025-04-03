<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Candidature;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\EntretienProgramme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class EntretienController extends Controller
{
    /**
     * Planifier un entretien pour une candidature
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $candidatureId
     * @return \Illuminate\Http\JsonResponse
     */
    public function planifierEntretien(Request $request, $candidatureId)
    {
        $user = Auth::user();
        
        // Vérifier que l'utilisateur est bien une entreprise
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Validation des données
        $validator = Validator::make($request->all(), [
            'date_entretien' => 'required|date|after:now',
            'type_entretien' => 'required|in:présentiel,visio',
            'lieu_entretien' => 'required_if:type_entretien,présentiel|nullable|string',
            'lien_visio' => 'required_if:type_entretien,visio|nullable|string',
            'note' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Récupérer la candidature
        $candidature = Candidature::with(['etudiant.user', 'offre.entreprise'])->findOrFail($candidatureId);
        
        // Vérifier que la candidature est pour une offre de cette entreprise
        if ($candidature->offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Mettre à jour la candidature avec les informations d'entretien
            $candidature->statut = 'entretien';
            $candidature->date_entretien = $request->date_entretien;
            $candidature->type_entretien = $request->type_entretien;
            $candidature->lieu_entretien = $request->lieu_entretien;
            $candidature->lien_visio = $request->lien_visio;
            $candidature->note_entretien = $request->note;
            $candidature->save();
            
            // Récupérer l'étudiant et l'entreprise
            $etudiant = $candidature->etudiant;
            $entreprise = $candidature->offre->entreprise;
            $etudiantUser = $etudiant->user;
            
            // Créer une notification interne
            $notification = new Notification([
                'user_id' => $etudiantUser->id,
                'titre' => 'Entretien planifié',
                'contenu' => "Un entretien a été planifié le " . date('d/m/Y à H:i', strtotime($request->date_entretien)) . 
                              " pour votre candidature à l'offre : {$candidature->offre->titre}",
                'type' => 'entretien',
                'lien' => "/candidatures/{$candidature->id}",
                'lu' => false
            ]);
            $notification->save();
            
            // Envoyer un email à l'étudiant
            $etudiantUser->notify(new EntretienProgramme($candidature));
            
            DB::commit();
            
            return response()->json([
                'message' => 'Entretien planifié avec succès',
                'candidature' => $candidature
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la planification de l\'entretien',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Annuler un entretien planifié
     *
     * @param  int  $candidatureId
     * @return \Illuminate\Http\JsonResponse
     */
    public function annulerEntretien($candidatureId)
    {
        $user = Auth::user();
        
        // Vérifier que l'utilisateur est bien une entreprise
        if ($user->role !== 'entreprise') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Récupérer la candidature
        $candidature = Candidature::with(['etudiant.user', 'offre.entreprise'])->findOrFail($candidatureId);
        
        // Vérifier que la candidature est pour une offre de cette entreprise
        if ($candidature->offre->entreprise_id !== $user->entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier que la candidature a bien un entretien planifié
        if ($candidature->statut !== 'entretien' || !$candidature->date_entretien) {
            return response()->json([
                'message' => 'Aucun entretien planifié pour cette candidature'
            ], 400);
        }
        
        try {
            DB::beginTransaction();
            
            // Récupérer l'information d'entretien avant de la supprimer pour la notification
            $dateEntretien = $candidature->date_entretien;
            
            // Mettre à jour la candidature
            $candidature->statut = 'vue'; // Revenir au statut "vue"
            $candidature->date_entretien = null;
            $candidature->type_entretien = null;
            $candidature->lieu_entretien = null;
            $candidature->lien_visio = null;
            $candidature->note_entretien = null;
            $candidature->save();
            
            // Récupérer l'étudiant
            $etudiant = $candidature->etudiant;
            $etudiantUser = $etudiant->user;
            
            // Créer une notification
            $notification = new Notification([
                'user_id' => $etudiantUser->id,
                'titre' => 'Entretien annulé',
                'contenu' => "L'entretien prévu le " . date('d/m/Y à H:i', strtotime($dateEntretien)) . 
                              " pour votre candidature à l'offre : {$candidature->offre->titre} a été annulé",
                'type' => 'entretien',
                'lien' => "/candidatures/{$candidature->id}",
                'lu' => false
            ]);
            $notification->save();
            
            // Envoyer un email à l'étudiant
            // TODO: Ajouter notification d'annulation
            // $etudiantUser->notify(new EntretienAnnule($candidature));
            
            DB::commit();
            
            return response()->json([
                'message' => 'Entretien annulé avec succès',
                'candidature' => $candidature
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de l\'annulation de l\'entretien',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Confirmer la présence à un entretien (pour les étudiants)
     *
     * @param  int  $candidatureId
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmerPresence($candidatureId)
    {
        $user = Auth::user();
        
        // Vérifier que l'utilisateur est bien un étudiant
        if ($user->role !== 'etudiant') {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Récupérer la candidature
        $candidature = Candidature::with(['etudiant.user', 'offre.entreprise'])->findOrFail($candidatureId);
        
        // Vérifier que la candidature appartient à cet étudiant
        if ($candidature->etudiant->user_id !== $user->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Vérifier que la candidature a bien un entretien planifié
        if ($candidature->statut !== 'entretien' || !$candidature->date_entretien) {
            return response()->json([
                'message' => 'Aucun entretien planifié pour cette candidature'
            ], 400);
        }
        
        // Mettre à jour la candidature
        $candidature->presence_confirmee = true;
        $candidature->save();
        
        // Notifier l'entreprise
        $entrepriseUser = $candidature->offre->entreprise->user;
        
        $notification = new Notification([
            'user_id' => $entrepriseUser->id,
            'titre' => 'Présence confirmée',
            'contenu' => "L'étudiant {$user->prenom} {$user->nom} a confirmé sa présence à l'entretien du " . 
                         date('d/m/Y à H:i', strtotime($candidature->date_entretien)) . 
                         " pour l'offre : {$candidature->offre->titre}",
            'type' => 'entretien',
            'lien' => "/candidatures/{$candidature->id}",
            'lu' => false
        ]);
        $notification->save();
        
        return response()->json([
            'message' => 'Présence confirmée avec succès'
        ]);
    }
}