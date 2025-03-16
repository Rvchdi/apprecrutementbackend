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
        'type', // 'stage', 'emploi', 'alternance'
        'niveau_requis',
        'competences_requises',
        'localisation',
        'remuneration',
        'date_debut',
        'duree',
        'test_requis',
        'statut', // 'active', 'inactive', 'cloturee'
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
        'statut', // 'en_attente', 'vue', 'entretien', 'acceptee', 'refusee'
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