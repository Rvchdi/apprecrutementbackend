<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offre extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'entreprise_id',
        'titre',
        'description',
        'type',
        'niveau_requis',
        'competences_requises',
        'localisation',
        'remuneration',
        'date_debut',
        'duree',
        'test_requis',
        'statut',
        'vues_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'remuneration' => 'decimal:2',
        'date_debut' => 'date',
        'duree' => 'integer',
        'test_requis' => 'boolean',
    ];

    /**
     * Get the entreprise that owns the offre.
     */
    public function entreprise()
    {
        return $this->belongsTo(Entreprise::class);
    }

    /**
     * Get the candidatures for the offre.
     */
    public function candidatures()
    {
        return $this->hasMany(Candidature::class);
    }

    /**
     * Get the test associated with the offre.
     */
    public function test()
    {
        return $this->hasOne(Test::class);
    }

    /**
     * The competences that belong to the offre.
     */
    public function competences()
    {
        return $this->belongsToMany(Competence::class, 'offre_competences')
            ->withTimestamps();
    }
}