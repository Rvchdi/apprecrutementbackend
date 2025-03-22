<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    /**
     * Get the question that owns the reponse_etudiant.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Get the reponse that owns the reponse_etudiant.
     */
    public function reponse(): BelongsTo
    {
        return $this->belongsTo(Reponse::class);
    }
}