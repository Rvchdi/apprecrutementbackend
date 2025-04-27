<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAllStudentCvs;
use Illuminate\Console\Command;

class ProcessAllCvsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cvs:process-all {--queue : Whether the job should be queued}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Traite et résume tous les CV des étudiants';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Démarrage du traitement de tous les CV...');
        
        if ($this->option('queue')) {
            ProcessAllStudentCvs::dispatch()->onQueue('cv-processing');
            $this->info('Job mis en file d\'attente pour traitement.');
        } else {
            $this->info('Traitement synchrone des CV... (peut prendre un certain temps)');
            ProcessAllStudentCvs::dispatchSync();
            $this->info('Traitement terminé !');
        }
        
        return Command::SUCCESS;
    }
}