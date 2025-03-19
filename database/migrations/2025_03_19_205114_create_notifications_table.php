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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('titre');
            $table->text('contenu');
            $table->string('type')->default('systeme'); // 'systeme', 'candidature', 'entretien', 'message', etc.
            $table->boolean('lu')->default(false);
            $table->string('lien')->nullable(); // URL pour rediriger l'utilisateur
            $table->json('data')->nullable(); // Données supplémentaires au format JSON
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};