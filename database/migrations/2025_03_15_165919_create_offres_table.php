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
        Schema::create('offres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entreprise_id')->constrained()->onDelete('cascade');
            $table->string('titre');
            $table->text('description');
            $table->enum('type', ['stage', 'emploi', 'alternance']);
            $table->string('niveau_requis')->nullable();
            $table->text('competences_requises')->nullable();
            $table->string('localisation');
            $table->decimal('remuneration', 10, 2)->nullable();
            $table->date('date_debut');
            $table->integer('duree')->nullable();
            $table->boolean('test_requis')->default(false);
            $table->enum('statut', ['active', 'inactive', 'cloturee'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offres');
    }
};
