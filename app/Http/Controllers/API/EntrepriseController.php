<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Entreprise;
use App\Models\Offre;
use App\Models\Candidature;
use App\Models\Test;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 

class EntrepriseController extends Controller
{
    /**
     * Récupérer le profil de l'entreprise authentifiée
     */
    
    public function getProfile()
    {
        $user = Auth::user();
    $entreprise = $user->entreprise;
    
    if (!$entreprise) {
        return response()->json([
            'message' => 'Profil entreprise non trouvé'
        ], 404);
    }
    
    return response()->json([
        'user' => [
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'email' => $user->email,
            'telephone' => $user->telephone
        ],
        'entreprise' => $entreprise->toArray()
    ]);
    }
    
    /**
     * Mettre à jour le profil entreprise
     */
    public function updateProfile(Request $request)
    {
        Log::info('Contenu brut de la requête:', ['content' => $request->getContent()]);
        
        // Récupérer les données du corps de la requête directement
        $input = $request->all();
        
        // Si $request->all() est vide mais que le contenu est présent
        if (empty($input) && strpos($request->header('Content-Type'), 'multipart/form-data') !== false) {
            // Solution alternative pour récupérer les données
            $input = [];
            foreach ($_POST as $key => $value) {
                $input[$key] = $value;
            }
            Log::info('Données extraites manuellement:', $input);
        }
        
        $user = Auth::user();
        $entreprise = $user->entreprise;
        
        if (!$entreprise) {
            return response()->json(['message' => 'Profil non trouvé'], 404);
        }
        
        Log::info('Données extraites de la requête:', $input);
        
        try {
            // Mise à jour utilisateur
            if (!empty($input['nom'])) $user->nom = $input['nom'];
            if (!empty($input['prenom'])) $user->prenom = $input['prenom'];
            if (!empty($input['telephone'])) $user->telephone = $input['telephone'];
            $user->save();
            
            // Mise à jour entreprise
            if (!empty($input['nom_entreprise'])) $entreprise->nom_entreprise = $input['nom_entreprise'];
            if (!empty($input['description'])) $entreprise->description = $input['description'];
            if (!empty($input['secteur_activite'])) $entreprise->secteur_activite = $input['secteur_activite'];
            if (!empty($input['taille'])) $entreprise->taille = $input['taille'];
            if (!empty($input['site_web'])) $entreprise->site_web = $input['site_web'];
            if (!empty($input['adresse'])) $entreprise->adresse = $input['adresse'];
            if (!empty($input['ville'])) $entreprise->ville = $input['ville'];
            if (!empty($input['code_postal'])) $entreprise->code_postal = $input['code_postal'];
            if (!empty($input['pays'])) $entreprise->pays = $input['pays'];
            
            $entrepriseSaved = $entreprise->save();
            
            // Vérifiez l'état final
            $refreshed = $entreprise->fresh();
            Log::info('État après sauvegarde:', [
                'saved' => $entrepriseSaved,
                'new_sector' => $refreshed->secteur_activite,
                'new_taille' => $refreshed->taille,
                'new_website' => $refreshed->site_web
            ]);
            
            return response()->json([
                'message' => 'Profil mis à jour avec succès',
                'user' => $user->toArray(),
                'entreprise' => $refreshed->toArray(),
                'data_received' => $input
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Erreur lors de la mise à jour',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Récupérer les offres de l'entreprise
     */
    public function getOffres()
    {
        $user = Auth::user();
        $entreprise = $user->entreprise;
        
        if (!$entreprise) {
            return response()->json([
                'message' => 'Profil entreprise non trouvé'
            ], 404);
        }
        
        $offres = $entreprise->offres()
            ->withCount('candidatures')
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Ajouter des informations supplémentaires à chaque offre
        $offres->each(function($offre) {
            // Nombre de jours depuis la publication
            $offre->jours_actifs = now()->diffInDays($offre->created_at);
            
            // Nombre de vues (à partir d'un compteur vues_count sur le modèle Offre)
            $offre->vues_count = $offre->vues_count ?? 0;
        });
        
        return response()->json([
            'offres' => $offres
        ]);
    }
    
    /**
     * Récupérer les candidatures reçues
     */
    public function getCandidatures()
    {
        $user = Auth::user();
        $entreprise = $user->entreprise;
        
        if (!$entreprise) {
            return response()->json([
                'message' => 'Profil entreprise non trouvé'
            ], 404);
        }
        
        $offresIds = $entreprise->offres()->pluck('id')->toArray();
        
        if (empty($offresIds)) {
            return response()->json([
                'candidatures' => []
            ]);
        }
        
        $candidatures = Candidature::whereIn('offre_id', $offresIds)
            ->with(['etudiant.user', 'offre'])
            ->orderBy('date_candidature', 'desc')
            ->get();
        
        return response()->json([
            'candidatures' => $candidatures
        ]);
    }
    
    /**
     * Mettre à jour le statut d'une candidature
     */
    public function updateCandidatureStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'statut' => 'required|in:en_attente,vue,entretien,acceptee,refusee'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $entreprise = $user->entreprise;
        
        if (!$entreprise) {
            return response()->json([
                'message' => 'Profil entreprise non trouvé'
            ], 404);
        }
        
        $candidature = Candidature::with('offre')->findOrFail($id);
        
        // Vérifier que la candidature concerne une offre de cette entreprise
        if ($candidature->offre->entreprise_id !== $entreprise->id) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        // Mettre à jour le statut
        $candidature->statut = $request->statut;
        $candidature->save();
        
        // Créer une notification pour l'étudiant
        $etudiant = $candidature->etudiant;
        $statusMessages = [
            'en_attente' => 'Votre candidature a été remise en attente',
            'vue' => 'Votre candidature a été consultée',
            'entretien' => 'Vous êtes invité(e) à un entretien',
            'acceptee' => 'Votre candidature a été acceptée',
            'refusee' => 'Votre candidature n\'a pas été retenue'
        ];
        
        $etudiant->user->notifications()->create([
            'titre' => 'Mise à jour de votre candidature',
            'contenu' => $statusMessages[$request->statut] . " pour l'offre : {$candidature->offre->titre}",
            'type' => 'candidature',
            'lien' => "/candidatures/{$candidature->id}"
        ]);
        
        return response()->json([
            'message' => 'Statut de la candidature mis à jour avec succès'
        ]);
    }
    
    /**
     * Obtenir les statistiques de recrutement
     */
    public function downloadCV($filename)
    {
        $user = auth()->user();
        Log::info("Tentative de téléchargement du CV : {$filename} par l'utilisateur {$user->id}");

        $filePath = "{$filename}";
        if (!$user->cv_file || !str_contains($user->cv_file, $filename) || !Storage::disk('public')->exists($filePath)) {
            Log::warning("CV non trouvé : {$filePath}");
            return response()->json(['message' => 'CV non trouvé'], 404);
        }

        Log::info("CV trouvé : {$filePath}");
        return response()->download(storage_path("app/public/storage/{$filePath}"));
    }
    public function getStatistiques()
    {
        $user = Auth::user();
        $entreprise = $user->entreprise;
        
        if (!$entreprise) {
            return response()->json([
                'message' => 'Profil entreprise non trouvé'
            ], 404);
        }
        
        // Nombre d'offres publiées
        $offresCount = $entreprise->offres()->count();
        
        // Nombre de candidatures reçues
        $offresIds = $entreprise->offres()->pluck('id')->toArray();
        $candidaturesCount = Candidature::whereIn('offre_id', $offresIds)->count();
        
        // Nombre d'entretiens
        $entretiensCount = Candidature::whereIn('offre_id', $offresIds)
            ->where('statut', 'entretien')
            ->count();
        
        // Nombre de candidatures acceptées
        $accepteesCount = Candidature::whereIn('offre_id', $offresIds)
            ->where('statut', 'acceptee')
            ->count();
        
        // Taux de conversion (candidatures acceptées / candidatures reçues)
        $tauxConversion = $candidaturesCount > 0 
            ? round(($accepteesCount / $candidaturesCount) * 100) 
            : 0;
        
        return response()->json([
            'offres_count' => $offresCount,
            'candidatures_count' => $candidaturesCount,
            'entretiens_count' => $entretiensCount,
            'acceptees_count' => $accepteesCount,
            'taux_conversion' => $tauxConversion
        ]);
    }
    
}