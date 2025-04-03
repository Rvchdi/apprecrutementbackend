<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Vérifier si l'email est vérifié
 *
 * @param  \Illuminate\Http\Request  $request
 * @return \Illuminate\Http\JsonResponse
 */
function checkEmailVerified(Request $request)
{
    $user = $request->user();
    
    // Ajout de logs pour déboguer
    if ($user) {
        Log::debug('Vérification d\'email pour l\'utilisateur ' . $user->id, [
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'email_verified_at_type' => gettype($user->email_verified_at),
            'is_null' => is_null($user->email_verified_at),
            'is_empty_string' => $user->email_verified_at === '',
        ]);
    }
    
    // Assurez-vous que la conversion à boolean est explicite
    $isVerified = $user && !is_null($user->email_verified_at) && $user->email_verified_at !== '';
    
    return response()->json([
        'email_verified' => $isVerified,
        'debug_info' => [
            'email_verified_at' => $user ? $user->email_verified_at : null,
            'is_null' => $user ? is_null($user->email_verified_at) : null,
            'user_id' => $user ? $user->id : null,
        ],
        'user' => $user ? [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at
        ] : null
    ]);
}