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
        
        return response()->json($entreprise);
    }
    
    /**
     * Mettre à jour le profil entreprise
     */
    public function update(Request $request)
    {
        // Vérifier que l'utilisateur connecté est bien une entreprise
        $user = Auth::user();
        if (!$user->isEntreprise()) {
            return response()->json([
                'message' => 'Accès non autorisé. Seules les entreprises peuvent modifier ce profil.'
            ], 403);
        }

        // Récupérer l'entreprise associée à l'utilisateur
        $entreprise = $user->entreprise;
        if (!$entreprise) {
            return response()->json([
                'message' => 'Profil entreprise non trouvé'
            ], 404);
        }

        // Validation des données
        $validator = Validator::make($request->all(), [
            'nom' => 'nullable|string|max:255',
            'prenom' => 'nullable|string|max:255',
            'telephone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|max:2048',
            'nom_entreprise' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'secteur_activite' => 'nullable|string|max:255',
            'taille' => 'nullable|string|max:50',
            'site_web' => 'nullable|url|max:255',
            'logo' => 'nullable|image|max:2048',
            'adresse' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:20',
            'pays' => 'nullable|string|max:100'
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

            // Mettre à jour les informations de l'entreprise
            $entrepriseFields = [
                'nom_entreprise', 'description', 'secteur_activite', 'taille', 
                'site_web', 'adresse', 'ville', 'code_postal', 'pays'
            ];

            foreach ($entrepriseFields as $field) {
                if ($request->filled($field)) {
                    $entreprise->$field = $request->$field;
                }
            }

            // Traiter le logo
            if ($request->hasFile('logo')) {
                // Supprimer l'ancien logo s'il existe
                if ($entreprise->logo && Storage::disk('public')->exists($entreprise->logo)) {
                    Storage::disk('public')->delete($entreprise->logo);
                }
                
                // Stocker le nouveau logo
                $logoPath = $request->file('logo')->store('logos', 'public');
                $entreprise->logo = $logoPath;
            }

            // Sauvegarder les modifications de l'entreprise
            $entreprise->save();

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
                'entreprise' => $entreprise->toArray()
            ];

            // Ajouter l'URL du logo s'il existe
            if ($entreprise->logo) {
                $responseData['entreprise']['logo_url'] = url('storage/'.$entreprise->logo);
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            // Annuler la transaction en cas d'erreur
            DB::rollBack();
            
            Log::error('Erreur lors de la mise à jour du profil entreprise', [
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