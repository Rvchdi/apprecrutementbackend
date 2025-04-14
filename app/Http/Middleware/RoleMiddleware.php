<?php


namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $role
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $role)
    {
        // Log détaillé
        \Log::info('Vérification du rôle administrateur', [
            'user_id' => $request->user() ? $request->user()->id : null,
            'user_role' => $request->user() ? $request->user()->role : null,
            'role_requis' => $role,
            'route' => $request->path()
        ]);

        // Vérifier si l'utilisateur est authentifié
        if (!$request->user()) {
            return response()->json([
                'message' => 'Authentification requise'
            ], 401);
        }

        // Vérifier si le rôle correspond
        if ($request->user()->role !== $role) {
            return response()->json([
                'message' => 'Accès non autorisé',
                'details' => [
                    'role_actuel' => $request->user()->role,
                    'role_requis' => $role
                ]
            ], 403);
        }

        return $next($request);
    }
}