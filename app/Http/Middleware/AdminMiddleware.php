<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Vérifier si l'utilisateur est authentifié
        if (!Auth::check()) {
            return response()->json([
                'message' => 'Non authentifié'
            ], 401);
        }

        // Vérifier si l'utilisateur est un administrateur
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'message' => 'Accès refusé. Cette action nécessite des droits d\'administrateur.'
            ], 403);
        }

        // Utilisateur authentifié et administrateur, continuer
        return $next($request);
    }
}