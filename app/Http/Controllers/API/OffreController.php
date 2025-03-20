<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Offre;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;


class OffreController extends Controller
{
    // Afficher toutes les offres de l'entreprise connectée
    public function index()
    {
        $entrepriseId = Auth::user()->entreprise->id;
        $offres = Offre::where('entreprise_id', $entrepriseId)->get();

        return response()->json(['offres' => $offres], 200);
        
    }

    // Créer une nouvelle offre
   
    public function store(Request $request)
    {
        // Log des données reçues
        Log::info('Données reçues dans store:', $request->all());
    
        // Validation des données
        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|string|in:stage,emploi,alternance',
            'niveau_requis' => 'nullable|string',
            'competences_requises' => 'nullable|string',
            'localisation' => 'required|string',
            'remuneration' => 'required|numeric',
            'date_debut' => 'required|date',
            'duree' => 'required|integer', // Assurer que 'duree' est un entier
            'test_requis' => 'nullable|string|in:oui,non', // Accepter 'oui' ou 'non'
            'statut' => 'required|string|in:active,inactive,cloturee',
        ]);
    
        // Récupérer l'entreprise associée à l'utilisateur authentifié
$user = auth()->user();
$entreprise = $user->entreprise;  // Utilisation de la relation définie dans le modèle User

// Vérifier si l'entreprise existe
if (!$entreprise) {
    return response()->json(['message' => 'Entreprise non trouvée pour l\'utilisateur authentifié'], 404);
}

// Créer l'offre avec l'ID de l'entreprise
try {
    $offre = Offre::create([
        'entreprise_id' => $entreprise->id, // Utilisation de l'ID de l'entreprise
        'titre' => $request->titre,
        'description' => $request->description,
        'type' => $request->type,
        'niveau_requis' => $request->niveau_requis,
        'competences_requises' => $request->competences_requises,
        'localisation' => $request->localisation,
        'remuneration' => $request->remuneration,
        'date_debut' => $request->date_debut,
        'duree' => (int) $request->duree,
        'test_requis' => $request->test_requis == 'oui' ? 1 : 0,
        'statut' => $request->statut,
    ]);

    // Log de l'offre créée
    Log::info('Offre créée avec succès:', $offre->toArray());

    return response()->json(['message' => 'Offre créée avec succès', 'offre' => $offre], 201);
} catch (\Exception $e) {
    // Log de l'erreur
    Log::error('Erreur lors de la création de l\'offre:', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'Erreur lors de la création de l\'offre', 'error' => $e->getMessage()], 500);
}
    }
    
    
    


    public function update(Request $request, $id)
    {
        // Vérification que l'utilisateur a une entreprise associée
        if (Auth::user()->entreprise === null) {
            Log::warning('Utilisateur sans entreprise associée', ['user_id' => Auth::id()]);
            return response()->json(['message' => 'Entreprise non associée à l\'utilisateur'], 403);
        }
    
        // Récupération de l'offre
        $offre = Offre::where('id', $id)
                      ->where('entreprise_id', Auth::user()->entreprise->id)
                      ->first();
    
        // Vérification si l'offre existe
        if (!$offre) {
            Log::warning('Offre non trouvée ou non autorisée', ['offre_id' => $id, 'user_id' => Auth::id()]);
            return response()->json(['message' => 'Offre non trouvée ou non autorisée'], 403);
        }
    
        // Validation des données envoyées
        $request->validate([
            'titre' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'type' => 'sometimes|string|max:50',
            'niveau_requis' => 'sometimes|string|max:100',
            'competences_requises' => 'sometimes|string',
            'localisation' => 'sometimes|string|max:255',
            'remuneration' => 'sometimes|numeric',
            'date_debut' => 'sometimes|date',
            'duree' => 'sometimes|integer',
            'test_requis' => 'sometimes|boolean',
            'statut' => 'sometimes|string|in:active,inactive,cloturee',
        ]);
    
        // Log des données envoyées par l'utilisateur
        Log::info('Données de mise à jour de l\'offre', ['offre_id' => $id, 'data' => $request->all()]);
    
        // Mise à jour de l'offre
        $allowedFields = $request->only([
            'titre',
            'description',
            'type',
            'niveau_requis',
            'competences_requises',
            'localisation',
            'remuneration',
            'date_debut',
            'duree',
            'test_requis',
            'statut'
        ]);
    
        // Log avant la mise à jour
        Log::info('Tentative de mise à jour de l\'offre', ['offre_id' => $id, 'updated_fields' => $allowedFields]);
    
        // Effectuer la mise à jour
        $offreUpdated = $offre->update($allowedFields);
    
        // Vérifier si la mise à jour a réussi
        if ($offreUpdated) {
            Log::info('Offre mise à jour avec succès', ['offre_id' => $id]);
            return response()->json(['message' => 'Offre mise à jour avec succès', 'offre' => $offre], 200);
        } else {
            Log::error('Échec de la mise à jour de l\'offre', ['offre_id' => $id]);
            return response()->json(['message' => 'Échec de la mise à jour de l\'offre'], 500);
        }
    }
    

    // Supprimer une offre
    public function destroy($id)
    {
        $offre = Offre::where('id', $id)
                      ->where('entreprise_id', Auth::user()->entreprise->id)
                      ->first();

        if (!$offre) {
            return response()->json(['message' => 'Offre non trouvée ou non autorisée'], 403);
        }

        $offre->delete();

        return response()->json(['message' => 'Offre supprimée avec succès'], 200);
    }

    public function offresDeLEntreprise()
{
    // Récupérer l'entreprise de l'utilisateur authentifié
    $user = auth()->user();
    $entreprise = $user->entreprise;  // On suppose qu'il existe une relation entre l'utilisateur et l'entreprise

    // Vérifier si l'entreprise existe
    if (!$entreprise) {
        return response()->json(['message' => 'Entreprise non trouvée pour l\'utilisateur authentifié'], 404);
    }

    // Récupérer les offres créées par cette entreprise
    $offres = Offre::where('entreprise_id', $entreprise->id)->get();

    // Retourner les offres de l'entreprise
    return response()->json(['offres' => $offres], 200);
}

 // Méthode pour afficher les offres pour les étudiants
 public function offresPourEtudiant(Request $request)
{
    // Vérifie si l'utilisateur est authentifié et a le rôle 'etudiant'
    if ($request->user()->role !== 'etudiant') {
        return response()->json(['error' => 'Accès interdit. Vous devez être un étudiant pour voir ces offres.'], 403);
    }

    // Récupérer toutes les offres (ajuste selon ta logique de filtrage)
    $offres = Offre::all();  // Récupère toutes les offres sans filtrage spécifique

    // Vérifier s'il y a des offres disponibles
    if ($offres->isEmpty()) {
        return response()->json(['message' => 'Aucune offre trouvée pour les étudiants.'], 404);
    }

    // Retourner les offres
    return response()->json($offres);
}



}
