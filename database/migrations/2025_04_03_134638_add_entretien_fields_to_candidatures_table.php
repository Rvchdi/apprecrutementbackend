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
        Schema::table('candidatures', function (Blueprint $table) {
            $table->timestamp('date_entretien')->nullable()->after('date_candidature');
            $table->enum('type_entretien', ['présentiel', 'visio'])->nullable()->after('date_entretien');
            $table->string('lieu_entretien')->nullable()->after('type_entretien');
            $table->string('lien_visio')->nullable()->after('lieu_entretien');
            $table->text('note_entretien')->nullable()->after('lien_visio');
            $table->boolean('presence_confirmee')->default(false)->after('note_entretien');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('candidatures', function (Blueprint $table) {
            $table->dropColumn('date_entretien');
            $table->dropColumn('type_entretien');
            $table->dropColumn('lieu_entretien');
            $table->dropColumn('lien_visio');
            $table->dropColumn('note_entretien');
            $table->dropColumn('presence_confirmee');
        });
    }
};