<?php

namespace App\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

trait DashboardCacheable
{
    /**
     * Récupère des données avec mise en cache
     * 
     * @param string $key Clé de cache
     * @param int $minutes Durée de mise en cache en minutes
     * @param callable $callback Fonction qui génère les données
     * @return mixed
     */
    protected function getWithCache($key, $minutes, callable $callback)
    {
        $userId = Auth::id();
        $userSpecificKey = "user_{$userId}_{$key}";
        
        return Cache::remember($userSpecificKey, now()->addMinutes($minutes), $callback);
    }
    
    /**
     * Invalide le cache pour une clé spécifique
     * 
     * @param string $key Clé de cache
     * @return void
     */
    protected function invalidateCache($key)
    {
        $userId = Auth::id();
        $userSpecificKey = "user_{$userId}_{$key}";
        
        Cache::forget($userSpecificKey);
    }
    
    /**
     * Invalide tout le cache du dashboard pour l'utilisateur
     * 
     * @return void
     */
    protected function invalidateAllDashboardCache()
    {
        $userId = Auth::id();
        $keys = [
            'dashboard_summary',
            'candidatures',
            'offres',
            'tests',
            'profile',
            'notifications',
            'competences'
        ];
        
        foreach ($keys as $key) {
            Cache::forget("user_{$userId}_{$key}");
        }
    }
}