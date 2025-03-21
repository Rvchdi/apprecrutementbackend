<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CandidatureController;
use App\Http\Controllers\API\OffreController;
use App\Http\Controllers\API\TestController;
use App\Http\Controllers\API\CompetenceController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\MessageController;
use App\Http\Controllers\API\EtudiantController;
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

// Routes publiques
Route::get('offres', [OffreController::class, 'index']);
Route::get('offres/{id}', [OffreController::class, 'show']);
Route::get('competences', [CompetenceController::class, 'index']);

// Routes protégées qui nécessitent une authentification
Route::middleware('auth:sanctum')->group(function () {
    // Profil étudiant
    Route::get('etudiant/profile', [EtudiantController::class, 'getProfile']);
    Route::put('etudiant/profile', [EtudiantController::class, 'updateProfile']);
    Route::post('etudiant/profile/cv', [EtudiantController::class, 'uploadCV']);
    Route::get('etudiant/dashboard/summary', [EtudiantController::class, 'getSummary']);
    Route::get('candidatures/etudiant', [EtudiantController::class, 'getEtudiantCandidatures']);
   
    
    // Offres
    Route::post('offres', [OffreController::class, 'store']);
    Route::put('offres/{id}', [OffreController::class, 'update']);
    Route::delete('offres/{id}', [OffreController::class, 'destroy']);
    Route::get('entreprise/offres', [OffreController::class, 'getEntrepriseOffres']);
    Route::get('entreprise/statistiques', [OffreController::class, 'getEntrepriseStatistiques']);
    
    // Candidatures
    Route::get('etudiant/candidatures', [CandidatureController::class, 'getEtudiantCandidatures']);
    Route::get('entreprise/candidatures', [CandidatureController::class, 'getEntrepriseCandidatures']);
    Route::post('offres/{id}/postuler', [CandidatureController::class, 'postuler']);
    Route::get('offres/{id}/candidature-status', [CandidatureController::class, 'getCandidatureStatus']);
    Route::put('candidatures/{id}/status', [CandidatureController::class, 'updateStatus']);
    Route::post('offres/{id}/save', [CandidatureController::class, 'saveOffre']);
    Route::delete('offres/{id}/save', [CandidatureController::class, 'unsaveOffre']);
    
    // Tests
    Route::get('tests/{id}', [TestController::class, 'show']);
    Route::post('tests/{id}/submit', [TestController::class, 'submitTest']);
    Route::get('etudiant/tests', [TestController::class, 'getStudentTests']);
    Route::get('entreprise/tests/results', [TestController::class, 'getEntrepriseTestResults']);
    
    // Compétences
    Route::get('etudiant/competences', [CompetenceController::class, 'getEtudiantCompetences']);
    Route::post('etudiant/competences', [CompetenceController::class, 'addCompetence']);
    Route::put('etudiant/competences/{id}', [CompetenceController::class, 'updateCompetence']);
    Route::delete('etudiant/competences/{id}', [CompetenceController::class, 'removeCompetence']);
    Route::get('etudiant/competences/recommandees', [CompetenceController::class, 'getRecommendedCompetences']);
    
    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('notifications/bulk', [NotificationController::class, 'bulkDestroy']);
    Route::get('notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
    
    // Messagerie
    Route::get('conversations', [MessageController::class, 'getConversations']);
    Route::get('conversations/{id}/messages', [MessageController::class, 'getMessages']);
    Route::post('conversations/{id}/messages', [MessageController::class, 'sendMessage']);
    Route::post('conversations', [MessageController::class, 'createConversation']);
    Route::patch('conversations/{id}/read', [MessageController::class, 'markAllAsRead']);
    Route::get('messages/unread-count', [MessageController::class, 'getUnreadCount']);
    Route::post('candidatures/{id}/conversation', [MessageController::class, 'createFromCandidature']);
});