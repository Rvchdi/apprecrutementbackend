<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AdminController;
use App\Http\Middleware\AdminMiddleware;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Routes d'API pour le panneau d'administration
|
*/

// Option 1: Utiliser le middleware directement avec sa classe complète
Route::middleware(['auth:sanctum', \App\Http\Middleware\AdminMiddleware::class])->prefix('api/admin')->group(function () {
    // Tableau de bord et statistiques
    Route::get('/dashboard', [AdminController::class, 'getDashboardStats']);
    
    // Gestion des utilisateurs
    Route::get('/users', [AdminController::class, 'getUsers']);
    Route::get('/users/{id}', [AdminController::class, 'getUser']);
    Route::post('/users', [AdminController::class, 'createUser']);
    Route::put('/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    
    // Gestion des compétences
    Route::get('/competences', [AdminController::class, 'getCompetences']);
    Route::get('/competences/{id}', [AdminController::class, 'getCompetence']);
    Route::post('/competences', [AdminController::class, 'createCompetence']);
    Route::put('/competences/{id}', [AdminController::class, 'updateCompetence']);
    Route::delete('/competences/{id}', [AdminController::class, 'deleteCompetence']);
    
    // Gestion des offres
    Route::get('/offres', [AdminController::class, 'getOffres']);
    Route::get('/offres/{id}', [AdminController::class, 'getOffre']);
    Route::put('/offres/{id}', [AdminController::class, 'updateOffre']);
    Route::delete('/offres/{id}', [AdminController::class, 'deleteOffre']);
    
    // Gestion des candidatures
    Route::get('/candidatures', [AdminController::class, 'getCandidatures']);
    Route::get('/candidatures/{id}', [AdminController::class, 'getCandidature']);
    
    // Paramètres du système
    Route::get('/settings', [AdminController::class, 'getSettings']);
    Route::put('/settings', [AdminController::class, 'updateSettings']);
    
    // Gestion des logs et activités
    Route::get('/logs', [AdminController::class, 'getLogs']);
    
    // Fonctions de maintenance
    Route::post('/maintenance-mode', [AdminController::class, 'toggleMaintenanceMode']);
    Route::post('/clear-cache', [AdminController::class, 'clearCache']);
});

/* 
// Option 2: Utiliser l'alias du middleware (si défini dans Kernel.php)
Route::middleware(['auth:sanctum', 'admin'])->prefix('api/admin')->group(function () {
    // Routes...
});

// Option 3: Utiliser le middleware de rôle avec le paramètre
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('api/admin')->group(function () {
    // Routes...
});
*/