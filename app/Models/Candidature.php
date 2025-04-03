<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidature extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'etudiant_id',
        'offre_id',
        'lettre_motivation',
        'statut', // 'en_attente', 'vue', 'entretien', 'acceptee', 'refusee'
        'score_test',
        'test_complete',
        'date_candidature',
        'date_entretien',
        'type_entretien',
        'lieu_entretien',
        'lien_visio',
        'note_entretien',
        'presence_confirmee',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'score_test' => 'integer',
        'test_complete' => 'boolean',
        'date_candidature' => 'datetime',
        'date_entretien' => 'datetime',
        'presence_confirmee' => 'boolean',
    ];

    /**
     * Get the etudiant that owns the candidature.
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * Get the offre that owns the candidature.
     */
    public function offre(): BelongsTo
    {
        return $this->belongsTo(Offre::class);
    }

    /**
     * Get the reponses_etudiants for the candidature.
     */
    public function reponsesEtudiants(): HasMany
    {
        return $this->hasMany(ReponseEtudiant::class);
    }
}