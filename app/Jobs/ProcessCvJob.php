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

class ProcessCvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $etudiant;
    
    public function __construct(Etudiant $etudiant)
    {
        $this->etudiant = $etudiant;
    }

    public function handle(CvAnalysisService $cvService)
    {
        Log::info("Démarrage du traitement du CV de l'étudiant #{$this->etudiant->id}");
        
        $result = $cvService->processCv($this->etudiant);
        
        if ($result) {
            Log::info("CV traité avec succès", ['etudiant_id' => $this->etudiant->id]);
        } else {
            Log::error("Échec du traitement du CV", ['etudiant_id' => $this->etudiant->id]);
        }
    }
}