<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EtudiantController;
use App\Http\Controllers\API\EntrepriseController;
use App\Http\Controllers\API\OffreController;
use App\Http\Controllers\API\CandidatureController;
use App\Http\Controllers\API\CompetenceController;
use App\Http\Controllers\API\TestController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\MessageController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Voici où vous pouvez enregistrer les routes API pour votre application.
| Ces routes sont chargées par RouteServiceProvider et toutes sont 
| affectées au groupe de middleware "api".
|
*/

// Routes publiques
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Routes pour les compétences (accessibles publiquement)
Route::get('competences', [CompetenceController::class, 'index']);
Route::get('competences/{id}', [CompetenceController::class, 'show']);

// Routes pour les offres (accessibles publiquement)
Route::get('offres', [OffreController::class, 'index']);
Route::get('offres/{id}', [OffreController::class, 'show']);

// Routes qui nécessitent une authentification
Route::middleware('auth:sanctum')->group(function () {
    // Routes d'authentification authentifiées
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail']);
    });

    // Vérification du statut de candidature
    Route::get('offres/{id}/candidature-status', [OffreController::class, 'getCandidatureStatus']);

    // Routes pour les notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::patch('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::delete('/', [NotificationController::class, 'bulkDelete']);
    });

    // Routes pour les messages
    Route::prefix('messages')->group(function () {
        Route::get('/conversations', [MessageController::class, 'getConversations']);
        Route::get('/unread-count', [MessageController::class, 'getUnreadCount']);
        Route::post('/conversations', [MessageController::class, 'createConversation']);
        Route::get('/conversations/{id}', [MessageController::class, 'getMessages']);
        Route::post('/conversations/{id}', [MessageController::class, 'sendMessage']);
    });
    Route::middleware('auth:sanctum')->get('/check-email-verified', function (Request $request) {
        $user = $request->user();
        
        return response()->json([
            'user_id' => $user->id,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'email_verified_at_type' => gettype($user->email_verified_at),
            'email_verified_at_class' => $user->email_verified_at ? get_class($user->email_verified_at) : null,
            'is_null' => is_null($user->email_verified_at),
            'cast_to_bool' => (bool)$user->email_verified_at,
            'db_value_raw' => DB::table('users')->where('id', $user->id)->first()->email_verified_at,
            'has_verified_email_method' => $user->hasVerifiedEmail(),
            'is_string' => is_string($user->email_verified_at),
            'email_verified_at_to_string' => $user->email_verified_at ? (string)$user->email_verified_at : null,
        ]);
    });
    // Routes qui nécessitent une vérification d'email
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('check-email-verified', function (Request $request) {
            $user = $request->user();
            return response()->json([
                'email_verified' => $user && $user->email_verified_at !== null
            ]);
        });
        // Routes pour les étudiants
        Route::prefix('etudiant')->group(function () {
            Route::get('/profile', [EtudiantController::class, 'getProfile']);
            Route::put('/profile', [EtudiantController::class, 'updateProfile']);
            
            // Gestion des compétences
            Route::get('/competences', [EtudiantController::class, 'getCompetences']);
            Route::post('/competences', [EtudiantController::class, 'addCompetence']);
            Route::put('/competences/{id}', [EtudiantController::class, 'updateCompetence']);
            Route::delete('/competences/{id}', [EtudiantController::class, 'removeCompetence']);
            Route::get('/competences/recommandees', [EtudiantController::class, 'getRecommendedSkills']);
            
            // Gestion des candidatures et tests
            Route::get('/candidatures', [EtudiantController::class, 'getCandidatures']);
            Route::get('/tests', [EtudiantController::class, 'getTests']);
            // Offres recommandées
            /*   */
            Route::post('offres/{id}/postuler', [OffreController::class, 'postuler']);
        });

        // Routes pour les entreprises
        Route::prefix('entreprise')->group(function () {
            Route::get('/profile', [EntrepriseController::class, 'getProfile']);
            Route::put('/profile', [EntrepriseController::class, 'updateProfile']);
            
            // Gestion des offres et candidatures
            Route::get('/offres', [EntrepriseController::class, 'getOffres']);
            Route::get('/candidatures', [EntrepriseController::class, 'getCandidatures']);
            Route::put('/candidatures/{id}/status', [EntrepriseController::class, 'updateCandidatureStatus']);
            
            // Statistiques
            Route::get('/statistiques', [EntrepriseController::class, 'getStatistiques']);
        });

        // Gestion des offres
        Route::post('offres', [OffreController::class, 'store']); // Création d'offre (entreprise)
        Route::put('offres/{id}', [OffreController::class, 'update']); // Mise à jour d'offre (entreprise)
        Route::delete('offres/{id}', [OffreController::class, 'destroy']); // Suppression d'offre (entreprise)
        
        // Actions sur les offres
        Route::post('offres/{id}/postuler', [OffreController::class, 'postuler']); // Postuler (étudiant)
        Route::post('offres/{id}/save', [OffreController::class, 'saveOffre']); // Sauvegarder (étudiant)
        Route::delete('offres/{id}/save', [OffreController::class, 'unsaveOffre']); // Retirer des favoris (étudiant)

        // Gestion des candidatures
        Route::get('candidatures/{id}', [CandidatureController::class, 'show']);
        Route::put('candidatures/{id}', [CandidatureController::class, 'update']);
        Route::delete('candidatures/{id}', [CandidatureController::class, 'cancel']);
        Route::post('candidatures/{id}/entretien', [EntretienController::class, 'planifierEntretien']);
        Route::delete('candidatures/{id}/entretien', [EntretienController::class, 'annulerEntretien']);
        Route::post('candidatures/{id}/confirmer-presence', [EntretienController::class, 'confirmerPresence']);

        // Gestion des tests
        Route::get('tests/{id}', [TestController::class, 'show']);
        Route::post('tests', [TestController::class, 'store']);
        Route::put('tests/{id}', [TestController::class, 'update']);
        Route::delete('tests/{id}', [TestController::class, 'destroy']);
        Route::post('tests/{id}/submit', [TestController::class, 'submit']);
        Route::get('offres/{offre_id}/tests', [TestController::class, 'getTestsByOffre']);
        Route::get('tests/{id}/candidatures/{candidature_id}/results', [TestController::class, 'getResults']);

        // Gestion des compétences (admin)
        Route::middleware('admin')->group(function () {
            Route::post('competences', [CompetenceController::class, 'store']);
            Route::put('competences/{id}', [CompetenceController::class, 'update']);
            Route::delete('competences/{id}', [CompetenceController::class, 'destroy']);
        });
    });
});