<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EtudiantController;
use App\Http\Controllers\API\EntrepriseController;
use App\Http\Controllers\API\EntretienController;
use App\Http\Controllers\API\OffreController;
use App\Http\Controllers\API\CandidatureController;
use App\Http\Controllers\API\CompetenceController;
use App\Http\Controllers\API\EtudiantCandidatureController;
use App\Http\Controllers\API\TestController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\MessageController;
use App\Http\Middleware\AdminMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Voici où vous pouvez enregistrer les routes API pour votre application.
| Ces routes sont chargées par RouteServiceProvider.
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
        Route::patch('/conversations/{id}/read', [MessageController::class, 'markAllAsRead']);
    });

    // Vérification de l'email
    Route::get('/check-email-verified', function (Request $request) {
        return response()->json([
            'email_verified' => !is_null($request->user()->email_verified_at)
        ]);
    });

    // Routes qui nécessitent une vérification d'email
    
        // Routes pour les étudiants
        Route::prefix('etudiant')->group(function () {
            Route::get('/profile', [EtudiantController::class, 'getProfile']);
            Route::post('/profile', [EtudiantController::class, 'update']);
            Route::post('/cv', [EtudiantController::class, 'uploadCV']);
            
            // Gestion des compétences
            Route::get('/competences', [EtudiantController::class, 'getCompetences']);
            Route::post('/competences', [EtudiantController::class, 'addCompetence']);
            Route::put('/competences/{id}', [EtudiantController::class, 'updateCompetence']);
            Route::delete('/competences/{id}', [EtudiantController::class, 'removeCompetence']);
            Route::get('/competences/recommandees', [EtudiantController::class, 'getRecommendedCompetences']);
            
            // Gestion des candidatures et tests
            Route::get('/candidatures', [EtudiantController::class, 'getCandidatures']);
            Route::get('/candidatures/{id}', [EtudiantController::class, 'getCandidatureDetails']);
            Route::get('/tests', [EtudiantController::class, 'getTests']);
            
            // Dashboard et statistiques
            Route::get('/dashboard', [EtudiantController::class, 'getSummary']);
            Route::get('/offres/recommandees', [EtudiantController::class, 'getRecommendedOffers']);
        });

        // Routes pour les entreprises
        Route::prefix('entreprise')->group(function () {
            Route::get('/profile', [EntrepriseController::class, 'getProfile']);
            Route::post('/profile', [EntrepriseController::class, 'updateProfile']);
            
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
        Route::post('offres/{offre_id}/postuler', [EtudiantCandidatureController::class, 'applyToOffer'])->middleware('auth:sanctum');
         // Postuler (étudiant)
        Route::post('offres/{id}/save', [OffreController::class, 'saveOffre']); // Sauvegarder (étudiant)
        Route::delete('offres/{id}/save', [OffreController::class, 'unsaveOffre']); // Retirer des favoris (étudiant)

        // Gestion des candidatures
        Route::get('candidatures/{id}', [CandidatureController::class, 'show']);
        Route::put('candidatures/{id}', [CandidatureController::class, 'update']);
        Route::delete('candidatures/{id}', [CandidatureController::class, 'cancel']);
        
        // Gestion des entretiens
        Route::post('candidatures/{id}/entretien', [EntretienController::class, 'planifierEntretien']);
        Route::delete('candidatures/{id}/entretien', [EntretienController::class, 'annulerEntretien']);
        Route::post('candidatures/{id}/confirmer-presence', [EntretienController::class, 'confirmerPresence']);
        Route::prefix('entretiens')->group(function () {
            Route::get('/', [EntretienController::class, 'getEntretiens']);
            Route::get('/summary', [EntretienController::class, 'getEntretiensSummary']);
        });

        // Gestion des tests
        Route::get('tests/{id}', [TestController::class, 'show']);
        Route::post('tests', [TestController::class, 'store']);
        Route::put('tests/{id}', [TestController::class, 'update']);
        Route::delete('tests/{id}', [TestController::class, 'destroy']);
        Route::post('tests/{id}/submit', [TestController::class, 'submit']);
        Route::get('offres/{offre_id}/tests', [TestController::class, 'getTestsByOffre']);
        Route::get('tests/{id}/candidatures/{candidature_id}/results', [TestController::class, 'getResults']);
    });


// Routes Admin - Utilisation directe du middleware AdminMiddleware
Route::middleware(['auth:sanctum', AdminMiddleware::class])->prefix('/admin')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\API\AdminController::class, 'getDashboardStats']);
    
    // Gestion des utilisateurs
    Route::get('/users', [\App\Http\Controllers\API\AdminController::class, 'getUsers']);
    Route::get('/users/{id}', [\App\Http\Controllers\API\AdminController::class, 'getUser']);
    Route::post('/users', [\App\Http\Controllers\API\AdminController::class, 'createUser']);
    Route::put('/users/{id}', [\App\Http\Controllers\API\AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [\App\Http\Controllers\API\AdminController::class, 'deleteUser']);
    
    // Gestion des compétences
    Route::get('/competences', [\App\Http\Controllers\API\AdminController::class, 'getCompetences']);
    Route::get('/competences/{id}', [\App\Http\Controllers\API\AdminController::class, 'getCompetence']);
    Route::post('/competences', [\App\Http\Controllers\API\AdminController::class, 'createCompetence']);
    Route::put('/competences/{id}', [\App\Http\Controllers\API\AdminController::class, 'updateCompetence']);
    Route::delete('/competences/{id}', [\App\Http\Controllers\API\AdminController::class, 'deleteCompetence']);
    
    // Gestion des offres
    Route::get('/offres', [\App\Http\Controllers\API\AdminController::class, 'getOffres']);
    Route::get('/offres/{id}', [\App\Http\Controllers\API\AdminController::class, 'getOffre']);
    Route::put('/offres/{id}', [\App\Http\Controllers\API\AdminController::class, 'updateOffre']);
    Route::delete('/offres/{id}', [\App\Http\Controllers\API\AdminController::class, 'deleteOffre']);
    
    // Gestion des candidatures
    Route::get('/candidatures', [\App\Http\Controllers\API\AdminController::class, 'getCandidatures']);
    Route::get('/candidatures/{id}', [\App\Http\Controllers\API\AdminController::class, 'getCandidature']);
    
    // Paramètres du système
    Route::get('/settings', [\App\Http\Controllers\API\AdminController::class, 'getSettings']);
    Route::put('/settings', [\App\Http\Controllers\API\AdminController::class, 'updateSettings']);
    
    // Gestion des logs et activités
    Route::get('/logs', [\App\Http\Controllers\API\AdminController::class, 'getLogs']);
    
    // Fonctions de maintenance
    Route::post('/maintenance-mode', [\App\Http\Controllers\API\AdminController::class, 'toggleMaintenanceMode']);
    Route::post('/clear-cache', [\App\Http\Controllers\API\AdminController::class, 'clearCache']);
});