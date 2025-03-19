<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Competence;
use App\Models\EtudiantCompetence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class EtudiantController extends Controller
{
    /**
     * Récupérer le profil de l'étudiant authentifié
     */
    public function getProfile()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant()->with('competences')->first();
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        return response()->json($etudiant);
    }
    
    /**
     * Mettre à jour le profil étudiant
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'date_naissance' => 'nullable|date',
            'niveau_etude' => 'nullable|string|max:255',
            'filiere' => 'nullable|string|max:255',
            'ecole' => 'nullable|string|max:255',
            'annee_diplome' => 'nullable|integer',
            'cv_file' => 'nullable|file|mimes:pdf|max:5120',
            'linkedin_url' => 'nullable|url|max:255',
            'portfolio_url' => 'nullable|url|max:255',
            'disponibilite' => 'nullable|in:immédiate,1_mois,3_mois,6_mois',
            'adresse' => 'nullable|string|max:255',
            'ville' => 'nullable|string|max:255',
            'code_postal' => 'nullable|string|max:20',
            'pays' => 'nullable|string|max:100',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Traiter le fichier CV s'il est présent
        if ($request->hasFile('cv_file')) {
            // Supprimer l'ancien CV si existant
            if ($etudiant->cv_file) {
                Storage::disk('public')->delete($etudiant->cv_file);
            }
            
            $cvPath = $request->file('cv_file')->store('cv_files', 'public');
            $etudiant->cv_file = $cvPath;
        }
        
        // Mettre à jour les autres champs
        $etudiant->fill($request->except('cv_file'));
        $etudiant->save();
        
        return response()->json([
            'message' => 'Profil mis à jour avec succès',
            'etudiant' => $etudiant
        ]);
    }
    
    /**
     * Récupérer les compétences de l'étudiant
     */
    public function getCompetences()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $competences = $etudiant->competences()->withPivot('niveau')->get();
        
        return response()->json([
            'competences' => $competences
        ]);
    }
    
    /**
     * Ajouter une compétence à l'étudiant
     */
    public function addCompetence(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'competence_id' => 'required|exists:competences,id',
            'niveau' => 'required|in:débutant,intermédiaire,avancé,expert'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Vérifier si la compétence existe déjà
        if ($etudiant->competences()->where('competence_id', $request->competence_id)->exists()) {
            return response()->json([
                'message' => 'Cette compétence existe déjà dans votre profil'
            ], 422);
        }
        
        // Ajouter la compétence
        $etudiant->competences()->attach($request->competence_id, [
            'niveau' => $request->niveau
        ]);
        
        return response()->json([
            'message' => 'Compétence ajoutée avec succès'
        ]);
    }
    
    /**
     * Mettre à jour le niveau d'une compétence
     */
    public function updateCompetence(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'niveau' => 'required|in:débutant,intermédiaire,avancé,expert'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Vérifier si la compétence existe pour cet étudiant
        if (!$etudiant->competences()->where('competence_id', $id)->exists()) {
            return response()->json([
                'message' => 'Compétence non trouvée dans votre profil'
            ], 404);
        }
        
        // Mettre à jour le niveau
        $etudiant->competences()->updateExistingPivot($id, [
            'niveau' => $request->niveau
        ]);
        
        return response()->json([
            'message' => 'Niveau de compétence mis à jour avec succès'
        ]);
    }
    
    /**
     * Supprimer une compétence de l'étudiant
     */
    public function removeCompetence($id)
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Détacher la compétence
        $etudiant->competences()->detach($id);
        
        return response()->json([
            'message' => 'Compétence supprimée avec succès'
        ]);
    }
    
    /**
     * Récupérer les compétences recommandées
     */
    public function getRecommendedSkills()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        // Récupérer les IDs des compétences déjà possédées
        $userCompetencesIds = $etudiant->competences()->pluck('competences.id')->toArray();
        
        // Récupérer les compétences les plus demandées qui ne sont pas déjà possédées
        $recommendedCompetences = Competence::whereNotIn('id', $userCompetencesIds)
            ->withCount('offres')
            ->orderBy('offres_count', 'desc')
            ->limit(10)
            ->get();
        
        return response()->json([
            'competences' => $recommendedCompetences
        ]);
    }
    
    /**
     * Récupérer les candidatures de l'étudiant
     */
    public function getCandidatures()
    {
        $user = Auth::user();
        $etudiant = $user->etudiant;
        
        if (!$etudiant) {
            return response()->json([
                'message' => 'Profil étudiant non trouvé'
            ], 404);
        }
        
        $candidatures = $etudiant->candidatures()
            ->with(['offre.entreprise', 'offre.competences'])
            ->orderBy('date_candidature', 'desc')
            ->get();
        
        return response()->json([
            'candidatures' => $candidatures
        ]);
    }
    
    /**
     * Récupérer les tests à compléter
     */
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