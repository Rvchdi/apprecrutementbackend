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
        Schema::create('etudiants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date_naissance')->nullable();
            $table->string('niveau_etude')->nullable();
            $table->string('filiere')->nullable();
            $table->string('ecole')->nullable();
            $table->integer('annee_diplome')->nullable();
            $table->string('cv_file')->nullable();
            $table->text('cv_resume')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->enum('disponibilite', ['immédiate', '1_mois', '3_mois', '6_mois'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etudiants');
    }
};
