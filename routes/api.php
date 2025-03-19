<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EtudiantDashboardController;
use App\Http\Controllers\API\EtudiantCandidatureController;
use App\Http\Controllers\API\OffreController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Routes d'authentification
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('verification.verify');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    
    // Routes protégées qui nécessitent une authentification
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('resend-verification-email', [AuthController::class, 'resendVerificationEmail']);
    });
});

// Routes publiques pour les offres
Route::prefix('offres')->group(function () {
    Route::get('/', [OffreController::class, 'index']);
    Route::get('/{id}', [OffreController::class, 'show']);
    Route::get('/competences/list', [OffreController::class, 'getCompetences']);
    Route::get('/statistics/general', [OffreController::class, 'getStats']);
});

// Routes pour le dashboard étudiant (protégées par authentification et vérification d'email)
Route::middleware(['auth:sanctum', 'verified.api'])->group(function () {
    // Dashboard étudiant
    Route::prefix('etudiant/dashboard')->group(function () {
        // Résumé du dashboard
        Route::get('/summary', [EtudiantDashboardController::class, 'getSummary']);
        
        // Gestion du profil
        Route::get('/profile', [EtudiantDashboardController::class, 'getProfile']);
        Route::put('/profile', [EtudiantDashboardController::class, 'updateProfile']);
        Route::post('/profile/cv', [EtudiantDashboardController::class, 'uploadCV']);
        
        // Gestion des compétences
        Route::get('/competences', [EtudiantDashboardController::class, 'getCompetences']);
        Route::post('/competences', [EtudiantDashboardController::class, 'addCompetence']);
        Route::put('/competences/{competence_id}', [EtudiantDashboardController::class, 'updateCompetenceLevel']);
        Route::delete('/competences/{competence_id}', [EtudiantDashboardController::class, 'removeCompetence']);
        
        // Gestion des candidatures
        Route::get('/candidatures', [EtudiantDashboardController::class, 'getCandidatures']);
        Route::get('/candidatures/{candidature_id}', [EtudiantDashboardController::class, 'getCandidatureDetails']);
        
        // Recommandations d'offres
        Route::get('/recommended-offers', [EtudiantDashboardController::class, 'getRecommendedOffers']);
        
        // Notifications
        Route::get('/notifications', [EtudiantDashboardController::class, 'getNotifications']);
        Route::put('/notifications/{notification_id}/read', [EtudiantDashboardController::class, 'markNotificationAsRead']);
    });
    
    // Fonctionnalités de candidature
    Route::prefix('etudiant/candidatures')->group(function () {
        // Parcourir les offres disponibles
        Route::get('/available-offers', [EtudiantCandidatureController::class, 'getAvailableOffers']);
        Route::get('/available-offers/{offre_id}', [EtudiantCandidatureController::class, 'getOfferDetails']);
        
        // Postuler et gérer les candidatures
        Route::post('/apply/{offre_id}', [EtudiantCandidatureController::class, 'applyToOffer']);
        Route::delete('/{candidature_id}', [EtudiantCandidatureController::class, 'cancelApplication']);
        
        // Tests associés aux candidatures
        Route::get('/{candidature_id}/test', [EtudiantCandidatureController::class, 'getTest']);
        Route::post('/{candidature_id}/test/submit', [EtudiantCandidatureController::class, 'submitTest']);
    });
});