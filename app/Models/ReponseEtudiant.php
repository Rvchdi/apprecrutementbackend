<?php

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