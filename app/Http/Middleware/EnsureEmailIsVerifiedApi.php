<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedApi
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        /* $user = $request->user();
        $cacheKey = 'email_verified_' . $user->id;
        
        $isVerified = Cache::remember($cacheKey, now()->addMinutes(60), function () use ($user) {
            return $user && 
                   $user instanceof MustVerifyEmail && 
                   $user->hasVerifiedEmail();
        });
        
        if (!$isVerified) {
            return response()->json([
                'message' => 'Votre adresse e-mail n\'est pas vérifiée.',
                'email_verified' => false,
                'verification_needed' => true
            ], 403);
        }
    
        return $next($request); */
    }
}