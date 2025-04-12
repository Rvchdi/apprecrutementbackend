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
use Illuminate\Support\Facades\Log;

class EntretienController extends Controller
{
    
    public function planifierEntretien(Request $request, $candidatureId)
    {
        // Logs de débogage détaillés
        Log::channel('daily')->info('Début de planifierEntretien', [
            'user_id' => Auth::id(),
            'user_role' => Auth::user()->role,
            'candidature_id' => $candidatureId,
            'request_data' => $request->all()
        ]);

        $user = Auth::user();
        
        // Validation des données avec log des erreurs
        $validator = Validator::make($request->all(), [
            'date_entretien' => 'required|date|after:now',
            'type_entretien' => 'required|in:présentiel,visio',
            'lieu_entretien' => 'required_if:type_entretien,présentiel|nullable|string',
            'lien_visio' => 'required_if:type_entretien,visio|nullable|string',
            'note' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            Log::channel('daily')->error('Erreur de validation', [
                'errors' => $validator->errors()->toArray()
            ]);
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            // Récupération de la candidature avec logs
            $candidature = Candidature::with(['etudiant.user', 'offre.entreprise'])->findOrFail($candidatureId);
            
            Log::channel('daily')->info('Candidature récupérée', [
                'candidature_id' => $candidature->id,
                'etudiant_id' => $candidature->etudiant->id,
                'offre_id' => $candidature->offre->id
            ]);

            // Vérification des relations avec logs
            if (!$candidature->etudiant || !$candidature->etudiant->user) {
                Log::channel('daily')->error('Relation étudiant manquante', [
                    'candidature_id' => $candidature->id
                ]);
                throw new \Exception('Relation étudiant invalide');
            }

            DB::beginTransaction();
            
            // Mise à jour de la candidature
            $candidature->statut = 'entretien';
            $candidature->date_entretien = $request->date_entretien;
            $candidature->type_entretien = $request->type_entretien;
            $candidature->lieu_entretien = $request->lieu_entretien;
            $candidature->lien_visio = $request->lien_visio;
            $candidature->note_entretien = $request->note;
            $candidature->save();
            
            $etudiantUser = $candidature->etudiant->user;
            
            // Log détaillé avant l'envoi de la notification
            Log::channel('daily')->info('Préparation à l\'envoi de la notification', [
                'etudiant_email' => $etudiantUser->email,
                'etudiant_id' => $etudiantUser->id,
                'candidature_id' => $candidature->id
            ]);

            // Configuration de logging spécifique pour les notifications
            config(['logging.channels.notification_log' => [
                'driver' => 'single',
                'path' => storage_path('logs/notification.log'),
                'level' => 'debug',
            ]]);

            // Tentative d'envoi de notification avec logging détaillé
            try {
                Log::channel('notification_log')->info('Tentative d\'envoi de notification', [
                    'etudiant_email' => $etudiantUser->email,
                    'candidature_id' => $candidature->id
                ]);

                $etudiantUser->notify(new EntretienProgramme($candidature));
                
                Log::channel('notification_log')->info('Notification envoyée avec succès', [
                    'etudiant_email' => $etudiantUser->email
                ]);
            } catch (\Exception $notificationException) {
                // Log détaillé de l'erreur de notification
                Log::channel('notification_log')->error('Échec de l\'envoi de notification', [
                    'message' => $notificationException->getMessage(),
                    'trace' => $notificationException->getTraceAsString(),
                    'etudiant_email' => $etudiantUser->email
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Entretien planifié avec succès',
                'candidature' => $candidature
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log de l'exception principale
            Log::channel('daily')->error('Erreur lors de la planification d\'entretien', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'candidature_id' => $candidatureId
            ]);
            
            return response()->json([
                'message' => 'Une erreur est survenue lors de la planification de l\'entretien',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getEntretiens(Request $request)
    {
        $user = Auth::user();
        $limit = $request->input('limit', 10);

        // Si l'utilisateur est un étudiant
        if ($user->role === 'etudiant') {
            $etudiant = $user->etudiant;
            
            if (!$etudiant) {
                return response()->json([
                    'message' => 'Profil étudiant non trouvé'
                ], 404);
            }
            
            // Récupérer les candidatures avec entretien
            $entretiens = Candidature::with(['offre.entreprise'])
                ->where('etudiant_id', $etudiant->id)
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->orderBy('date_entretien', 'asc')
                ->limit($limit)
                ->get();
                
            return response()->json([
                'entretiens' => $entretiens
            ]);
        }
        
        // Si l'utilisateur est une entreprise
        else if ($user->role === 'entreprise') {
            $entreprise = $user->entreprise;
            
            if (!$entreprise) {
                return response()->json([
                    'message' => 'Profil entreprise non trouvé'
                ], 404);
            }
            
            // Récupérer les candidatures avec entretien pour les offres de cette entreprise
            $entretiens = Candidature::with(['etudiant.user', 'offre'])
                ->whereHas('offre', function($query) use ($entreprise) {
                    $query->where('entreprise_id', $entreprise->id);
                })
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->orderBy('date_entretien', 'asc')
                ->limit($limit)
                ->get();
                
            return response()->json([
                'entretiens' => $entretiens
            ]);
        }
        
        // Si l'utilisateur est un admin (ou autre rôle)
        else {
            return response()->json([
                'message' => 'Fonctionnalité non disponible pour ce type d\'utilisateur'
            ], 403);
        }
    }
    
    /**
     * Récupère un résumé des entretiens à venir
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEntretiensSummary()
    {
        $user = Auth::user();
        
        // Si l'utilisateur est un étudiant
        if ($user->role === 'etudiant') {
            $etudiant = $user->etudiant;
            
            if (!$etudiant) {
                return response()->json([
                    'message' => 'Profil étudiant non trouvé'
                ], 404);
            }
            
            // Récupérer les candidatures avec entretien
            $entretiens = Candidature::with(['offre.entreprise'])
                ->where('etudiant_id', $etudiant->id)
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->where('date_entretien', '>=', now())
                ->orderBy('date_entretien', 'asc')
                ->limit(5)
                ->get();
                
            // Compter les entretiens aujourd'hui
            $entretienAujourdhui = Candidature::where('etudiant_id', $etudiant->id)
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->whereDate('date_entretien', now()->toDateString())
                ->count();
                
            // Compter les entretiens cette semaine
            $entretienCetteSemaine = Candidature::where('etudiant_id', $etudiant->id)
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->whereBetween('date_entretien', [
                    now()->startOfWeek()->toDateString(),
                    now()->endOfWeek()->toDateString()
                ])
                ->count();
                
            return response()->json([
                'entretiens' => $entretiens,
                'stats' => [
                    'aujourdhui' => $entretienAujourdhui,
                    'cette_semaine' => $entretienCetteSemaine,
                    'total' => $entretiens->count()
                ]
            ]);
        }
        
        // Si l'utilisateur est une entreprise
        else if ($user->role === 'entreprise') {
            $entreprise = $user->entreprise;
            
            if (!$entreprise) {
                return response()->json([
                    'message' => 'Profil entreprise non trouvé'
                ], 404);
            }
            
            // Récupérer les candidatures avec entretien pour les offres de cette entreprise
            $entretiens = Candidature::with(['etudiant.user', 'offre'])
                ->whereHas('offre', function($query) use ($entreprise) {
                    $query->where('entreprise_id', $entreprise->id);
                })
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->where('date_entretien', '>=', now())
                ->orderBy('date_entretien', 'asc')
                ->limit(5)
                ->get();
                
            // Compter les entretiens aujourd'hui
            $entretienAujourdhui = Candidature::whereHas('offre', function($query) use ($entreprise) {
                    $query->where('entreprise_id', $entreprise->id);
                })
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->whereDate('date_entretien', now()->toDateString())
                ->count();
                
            // Compter les entretiens cette semaine
            $entretienCetteSemaine = Candidature::whereHas('offre', function($query) use ($entreprise) {
                    $query->where('entreprise_id', $entreprise->id);
                })
                ->where('statut', 'entretien')
                ->whereNotNull('date_entretien')
                ->whereBetween('date_entretien', [
                    now()->startOfWeek()->toDateString(),
                    now()->endOfWeek()->toDateString()
                ])
                ->count();
                
            return response()->json([
                'entretiens' => $entretiens,
                'stats' => [
                    'aujourdhui' => $entretienAujourdhui,
                    'cette_semaine' => $entretienCetteSemaine,
                    'total' => $entretiens->count()
                ]
            ]);
        }
        
        // Si l'utilisateur est un admin (ou autre rôle)
        else {
            return response()->json([
                'message' => 'Fonctionnalité non disponible pour ce type d\'utilisateur'
            ], 403);
        }
    }

   
}