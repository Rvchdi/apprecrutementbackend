<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Etudiant extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'etudiants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'date_naissance',
        'niveau_etude',
        'filiere',
        'ecole',
        'annee_diplome',
        'cv_file',
        'cv_resume',
        'linkedin_url',
        'portfolio_url',
        'disponibilite', // 'immédiate', '1_mois', '3_mois', '6_mois'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_naissance' => 'date',
        'annee_diplome' => 'integer',
    ];

    /**
     * Get the user that owns the etudiant.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the candidatures for the etudiant.
     */
    public function candidatures()
    {
        return $this->hasMany(Candidature::class);
    }

    /**
     * The competences that belong to the etudiant.
     */
    public function competences()
    {
        return $this->belongsToMany(Competence::class, 'etudiant_competences')
            ->withPivot('niveau')
            ->withTimestamps();
    }
}

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entreprise extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'nom_entreprise',
        'description',
        'secteur_activite',
        'taille',
        'site_web',
        'logo',
        'adresse',
        'ville',
        'code_postal',
        'pays',
        'est_verifie',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'est_verifie' => 'boolean',
    ];

    /**
     * Get the user that owns the entreprise.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the offres for the entreprise.
     */
    public function offres()
    {
        return $this->hasMany(Offre::class);
    }
}