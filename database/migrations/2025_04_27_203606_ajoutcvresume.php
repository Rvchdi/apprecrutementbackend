<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cv_resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained()->onDelete('cascade');
            $table->text('cv_text')->nullable(); // Texte brut extrait du CV
            $table->text('resume')->nullable(); // Résumé généré par l'IA
            $table->text('competences_detectees')->nullable(); // Compétences détectées par l'IA
            $table->boolean('is_processed')->default(false); // Indique si le CV a été traité
            $table->timestamp('processed_at')->nullable(); // Date de traitement
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_resumes');
    }
};