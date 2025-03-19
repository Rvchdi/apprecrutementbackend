<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Competence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CompetenceController extends Controller
{
    /**
     * Récupérer toutes les compétences
     */
    public function index(Request $request)
    {
        $query = Competence::query();
        
        // Recherche par nom
        if ($request->has('search') && !empty($request->search)) {
            $query->where('nom', 'like', "%{$request->search}%");
        }
        
        // Filtrer par catégorie
        if ($request->has('categorie') && !empty($request->categorie)) {
            $query->where('categorie', $request->categorie);
        }
        
        $competences = $query->orderBy('nom')->get();
        
        return response()->json([
            'competences' => $competences
        ]);
    }
    
    /**
     * Créer une nouvelle compétence
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255|unique:competences',
            'categorie' => 'nullable|string|max:255'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $competence = Competence::create([
            'nom' => $request->nom,
            'categorie' => $request->categorie
        ]);
        
        return response()->json([
            'message' => 'Compétence créée avec succès',
            'competence' => $competence
        ], 201);
    }
    
    /**
     * Récupérer une compétence spécifique
     */
    public function show($id)
    {
        $competence = Competence::findOrFail($id);
        
        return response()->json($competence);
    }
    
    /**
     * Mettre à jour une compétence
     */
    public function update(Request $request, $id)
    {
        $competence = Competence::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'nom' => 'required|string|max:255|unique:competences,nom,' . $id,
            'categorie' => 'nullable|string|max:255'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $competence->nom = $request->nom;
        $competence->categorie = $request->categorie;
        $competence->save();
        
        return response()->json([
            'message' => 'Compétence mise à jour avec succès',
            'competence' => $competence
        ]);
    }
    
    /**
     * Supprimer une compétence
     */
    public function destroy($id)
    {
        $competence = Competence::findOrFail($id);
        $competence->delete();
        
        return response()->json([
            'message' => 'Compétence supprimée avec succès'
        ]);
    }
}