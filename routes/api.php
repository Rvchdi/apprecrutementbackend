<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CandidatureController;
use App\Http\Controllers\API\OffreController;
use App\Http\Controllers\API\TestController;
use App\Http\Controllers\API\CompetenceController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\MessageController;
use App\Http\Controllers\API\EtudiantController;
use App\Http\Controllers\API\EntrepriseController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::group([], function () {
    // Authentification
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('verification.verify');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
    });

    // Offres et Compétences publiques
    Route::get('offres', [OffreController::class, 'index']);
    Route::get('offres/{id}', [OffreController::class, 'show']);
    Route::get('competences', [CompetenceController::class, 'index']);
});

// Routes protégées qui nécessitent une authentification
Route::middleware(['auth:sanctum'])->group(function () {
    // Routes d'authentification protégées
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail']);
    });

    // Routes nécessitant une vérification d'email
    Route::middleware(['verified'])->group(function () {
        // Routes Étudiant
        Route::prefix('etudiant')->group(function () {
            Route::get('profile', [EtudiantController::class, 'getProfile']);
            Route::put('profile', [EtudiantController::class, 'updateProfile']);
            Route::post('profile/cv', [EtudiantController::class, 'uploadCV']);
            Route::get('dashboard/summary', [EtudiantController::class, 'getSummary']);
            Route::get('recommended-offers', [OffreController::class, 'getRecommendedOffers']);
            
            // Candidatures
            Route::get('candidatures', [CandidatureController::class, 'getEtudiantCandidatures']);
            
            // Compétences
            Route::get('competences', [CompetenceController::class, 'getEtudiantCompetences']);
            Route::post('competences', [CompetenceController::class, 'addCompetence']);
            Route::put('competences/{id}', [CompetenceController::class, 'updateCompetence']);
            Route::delete('competences/{id}', [CompetenceController::class, 'removeCompetence']);
            Route::get('competences/recommandees', [CompetenceController::class, 'getRecommendedCompetences']);
            
            // Tests
            Route::get('tests', [EtudiantController::class, 'getTests']);
        });

        // Routes Entreprise
        Route::prefix('entreprise')->group(function () {
            Route::get('profile', [EntrepriseController::class, 'getProfile']);
            Route::put('profile', [EntrepriseController::class, 'updateProfile']);
            Route::get('offres', [OffreController::class, 'getEntrepriseOffres']);
            Route::get('statistiques', [OffreController::class, 'getEntrepriseStatistiques']);
            Route::get('candidatures', [CandidatureController::class, 'getEntrepriseCandidatures']);
            Route::get('tests/results', [TestController::class, 'getEntrepriseTestResults']);
        });

        // Offres
        Route::post('offres', [OffreController::class, 'store']);
        Route::put('offres/{id}', [OffreController::class, 'update']);
        Route::delete('offres/{id}', [OffreController::class, 'destroy']);

        // Candidatures
        Route::post('offres/{id}/postuler', [CandidatureController::class, 'postuler']);
        Route::get('offres/{id}/candidature-status', [CandidatureController::class, 'getCandidatureStatus']);
        Route::put('candidatures/{id}/status', [CandidatureController::class, 'updateStatus']);
        Route::post('offres/{id}/save', [CandidatureController::class, 'saveOffre']);
        Route::delete('offres/{id}/save', [CandidatureController::class, 'unsaveOffre']);

        // Tests
        Route::get('tests/{id}', [TestController::class, 'show']);
        Route::post('tests/{id}/submit', [TestController::class, 'submitTest']);

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::patch('{id}/read', [NotificationController::class, 'markAsRead']);
            Route::patch('mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::delete('{id}', [NotificationController::class, 'destroy']);
            Route::delete('bulk', [NotificationController::class, 'bulkDestroy']);
            Route::get('unread-count', [NotificationController::class, 'getUnreadCount']);
        });

        // Messagerie
        Route::prefix('conversations')->group(function () {
            Route::get('/', [MessageController::class, 'getConversations']);
            Route::get('{id}/messages', [MessageController::class, 'getMessages']);
            Route::post('{id}/messages', [MessageController::class, 'sendMessage']);
            Route::post('/', [MessageController::class, 'createConversation']);
            Route::patch('{id}/read', [MessageController::class, 'markAllAsRead']);
        });

        Route::get('messages/unread-count', [MessageController::class, 'getUnreadCount']);
        Route::post('candidatures/{id}/conversation', [MessageController::class, 'createFromCandidature']);
    });
});