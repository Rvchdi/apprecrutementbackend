<?php

use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\OffreController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    
    // Routes protégées qui nécessitent une authentification
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/offres', [OffreController::class, 'index']); // Liste des offres
    Route::post('/offres', [OffreController::class, 'store']); // Créer une offre
    Route::put('/offres/{id}', [OffreController::class, 'update']); // Modifier une offre
    Route::delete('/offres/{id}', [OffreController::class, 'destroy']); // Supprimer une offre
});
