<?php

namespace App\Jobs;

use App\Models\Etudiant;
use App\Services\CvAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAllStudentCvs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Le nombre de tentatives pour ce job.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Le temps d'attente entre les tentatives en secondes.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @param  \App\Services\CvAnalysisService  $cvService
     * @return void
     */
    public function handle(CvAnalysisService $cvService)
    {
        Log::info('Démarrage du traitement de tous les CV des étudiants');
        
        // Récupérer tous les étudiants qui ont un CV
        $etudiants = Etudiant::whereNotNull('cv_file')
                        ->whereDoesntHave('cvResume', function($query) {
                            $query->where('is_processed', true);
                        })
                        ->orWhereHas('cvResume', function($query) {
                            $query->where('is_processed', false);
                        })
                        ->get();
        
        $totalEtudiants = $etudiants->count();
        Log::info("Nombre d'étudiants à traiter: {$totalEtudiants}");
        
        $processed = 0;
        $failed = 0;
        
        foreach ($etudiants as $etudiant) {
            try {
                Log::info("Traitement du CV de l'étudiant #{$etudiant->id}");
                
                // Traiter le CV de l'étudiant
                $result = $cvService->processCv($etudiant);
                
                if ($result) {
                    $processed++;
                    Log::info("CV traité avec succès pour l'étudiant #{$etudiant->id}");
                } else {
                    $failed++;
                    Log::warning("Échec du traitement du CV pour l'étudiant #{$etudiant->id}");
                }
                
                // Pause pour éviter de surcharger l'API
                if ($processed % 10 === 0) {
                    sleep(5);
                }
            } catch (\Exception $e) {
                $failed++;
                Log::error("Erreur lors du traitement du CV pour l'étudiant #{$etudiant->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        Log::info("Traitement des CV terminé", [
            'total' => $totalEtudiants,
            'processed' => $processed,
            'failed' => $failed
        ]);
    }
}