<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;

use App\Models\Competence;
use App\Models\Etudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CompetenceController extends Controller
{
    /**
     * Récupérer toutes les compétences disponibles
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
{
    
        $query = Competence::query();
        
        // Recherche par nom
        if ($request->has('q') && !empty($request->q)) {
            $query->where('nom', 'like', '%' . $request->q . '%');
        }
        
        // Filtrage par catégorie
        if ($request->has('categorie') && !empty($request->categorie)) {
            $query->where('categorie', $request->categorie);
        }
        
        // Tri
        $sortBy = $request->input('sort_by', 'nom');
        $sortDir = $request->input('sort_dir', 'asc');
        $allowedSortFields = ['nom', 'categorie', 'created_at'];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('nom', 'asc');
        }
        
        $competences = $query->get();
        
        return response()->json([
            'competences' => $competences
        ]);
}
    
    /**
     * Récupérer les compétences d'un étudiant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEtudiantCompetences()
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Seuls les étudiants peuvent accéder à leurs compétences'
            ], 403);
        }
        
        $etudiant = $user->etudiant;
        $competences = $etudiant->competences()->get();
        
        return response()->json([
            'competences' => $competences
        ]);
    }
    
    /**
     * Ajouter une compétence à un étudiant
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addCompetence(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Seuls les étudiants peuvent ajouter des compétences'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'competence_id' => 'required|integer|exists:competences,id',
            'niveau' => 'required|string|in:débutant,intermédiaire,avancé,expert'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $user->etudiant;
        
        // Vérifier si la compétence est déjà associée à l'étudiant
        if ($etudiant->competences()->where('competence_id', $request->competence_id)->exists()) {
            return response()->json([
                'message' => 'Cette compétence est déjà associée à votre profil'
            ], 400);
        }
        
        // Associer la compétence à l'étudiant avec le niveau spécifié
        $etudiant->competences()->attach($request->competence_id, [
            'niveau' => $request->niveau
        ]);
        
        return response()->json([
            'message' => 'Compétence ajoutée avec succès'
        ]);
    }
    
    /**
     * Mettre à jour le niveau d'une compétence
     *
     * @param  Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCompetence(Request $request, $id)
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Seuls les étudiants peuvent mettre à jour leurs compétences'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'niveau' => 'required|string|in:débutant,intermédiaire,avancé,expert'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $etudiant = $user->etudiant;
        
        // Vérifier si la compétence est associée à l'étudiant
        $relation = DB::table('etudiant_competences')
            ->where('etudiant_id', $etudiant->id)
            ->where('competence_id', $id)
            ->first();
            
        if (!$relation) {
            return response()->json([
                'message' => 'Cette compétence n\'est pas associée à votre profil'
            ], 404);
        }
        
        // Mettre à jour le niveau
        DB::table('etudiant_competences')
            ->where('etudiant_id', $etudiant->id)
            ->where('competence_id', $id)
            ->update(['niveau' => $request->niveau]);
            
        return response()->json([
            'message' => 'Niveau de compétence mis à jour avec succès'
        ]);
    }
    
    /**
     * Supprimer une compétence d'un étudiant
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeCompetence($id)
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Seuls les étudiants peuvent supprimer leurs compétences'
            ], 403);
        }
        
        $etudiant = $user->etudiant;
        
        // Vérifier si la compétence est associée à l'étudiant
        if (!$etudiant->competences()->where('competence_id', $id)->exists()) {
            return response()->json([
                'message' => 'Cette compétence n\'est pas associée à votre profil'
            ], 404);
        }
        
        // Dissocier la compétence
        $etudiant->competences()->detach($id);
        
        return response()->json([
            'message' => 'Compétence supprimée avec succès'
        ]);
    }
    
    /**
     * Récupérer des compétences recommandées pour l'étudiant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRecommendedCompetences()
    {
        $user = Auth::user();
        
        if (!$user->isEtudiant()) {
            return response()->json([
                'message' => 'Seuls les étudiants peuvent accéder à cette fonctionnalité'
            ], 403);
        }
        
        $etudiant = $user->etudiant;
        
        // Récupérer les IDs des compétences de l'étudiant
        $etudiantCompetenceIds = $etudiant->competences()->pluck('competences.id')->toArray();
        
        // Récupérer les compétences les plus demandées dans les offres correspondant à la filière de l'étudiant
        $recommendedCompetences = DB::table('offre_competences')
            ->join('offres', 'offre_competences.offre_id', '=', 'offres.id')
            ->join('competences', 'offre_competences.competence_id', '=', 'competences.id')
            ->whereNotIn('competences.id', $etudiantCompetenceIds)
            ->where(function($query) use ($etudiant) {
                if ($etudiant->filiere) {
                    $query->where('offres.titre', 'like', '%'.$etudiant->filiere.'%')
                          ->orWhere('offres.description', 'like', '%'.$etudiant->filiere.'%');
                }
            })
            ->select('competences.*', DB::raw('COUNT(offre_competences.competence_id) as count'))
            ->groupBy('competences.id')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();
            
        return response()->json([
            'competences' => $recommendedCompetences
        ]);
    }
}