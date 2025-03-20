<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'statut',
        'score_test',
        'test_complete',
        'date_candidature',
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
    ];

    /**
     * Get the etudiant that owns the candidature.
     */
    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * Get the offre that owns the candidature.
     */
    public function offre()
    {
        return $this->belongsTo(Offre::class);
    }

    /**
     * Get the reponses_etudiants for the candidature.
     */
    public function reponsesEtudiants()
    {
        return $this->hasMany(ReponseEtudiant::class);
    }
}