<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $role
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $role)
    {
        // Ajouter un log détaillé
    \Log::info('CheckRole middleware', [
        'user_id' => $request->user() ? $request->user()->id : null,
        'user_role' => $request->user() ? $request->user()->role : null,
        'required_role' => $role,
        'route' => $request->path(),
        'bearer_token_present' => $request->bearerToken() ? 'Oui' : 'Non'
    ]);

    if (!$request->user()) {
        return response()->json([
            'message' => 'Utilisateur non authentifié'
        ], 401);
    }

    if ($request->user()->role !== $role) {
        return response()->json([
            'message' => 'Accès non autorisé. Rôle requis: ' . $role . ', Rôle actuel: ' . $request->user()->role
        ], 403);
    }

    return $next($request);
        if (!$request->user() || $request->user()->role !== $role) {
            return response()->json([
                'message' => 'Accès non autorisé'
            ], 403);
        }

        return $next($request);
    }
}