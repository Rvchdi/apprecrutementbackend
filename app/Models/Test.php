<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Test extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'offre_id',
        'titre',
        'description',
        'duree_minutes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'duree_minutes' => 'integer',
    ];

    /**
     * Get the offre that owns the test.
     */
    public function offre()
    {
        return $this->belongsTo(Offre::class);
    }

    /**
     * Get the questions for the test.
     */
    public function questions()
    {
        return $this->hasMany(Question::class);
    }
}

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'test_id',
        'contenu',
    ];

    /**
     * Get the test that owns the question.
     */
    public function test()
    {
        return $this->belongsTo(Test::class);
    }

    /**
     * Get the reponses for the question.
     */
    public function reponses()
    {
        return $this->hasMany(Reponse::class);
    }

    /**
     * Get the reponses_etudiants for the question.
     */
    public function reponsesEtudiants()
    {
        return $this->hasMany(ReponseEtudiant::class);
    }
}

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reponse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'question_id',
        'contenu',
        'est_correcte',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'est_correcte' => 'boolean',
    ];

    /**
     * Get the question that owns the reponse.
     */
    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the reponses_etudiants for the reponse.
     */
    public function reponsesEtudiants()
    {
        return $this->hasMany(ReponseEtudiant::class);
    }
}

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReponseEtudiant extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'reponses_etudiants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'candidature_id',
        'question_id',
        'reponse_id',
    ];

    /**
     * Get the candidature that owns the reponse_etudiant.
     */
    public function candidature()
    {
        return $this->belongsTo(Candidature::class);
    }

    /**
     * Get the question that owns the reponse_etudiant.
     */
    public function question()
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the reponse that owns the reponse_etudiant.
     */
    public function reponse()
    {
        return $this->belongsTo(Reponse::class);
    }
}

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Competence extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'categorie',
    ];

    /**
     * The etudiants that belong to the competence.
     */
    public function etudiants()
    {
        return $this->belongsToMany(Etudiant::class, 'etudiant_competences')
            ->withPivot('niveau')
            ->withTimestamps();
    }

    /**
     * The offres that belong to the competence.
     */
    public function offres()
    {
        return $this->belongsToMany(Offre::class, 'offre_competences')
            ->withTimestamps();
    }
}