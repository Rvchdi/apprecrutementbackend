<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    /**
     * Get the reponses for the question.
     */
    public function reponses(): HasMany
    {
        return $this->hasMany(Reponse::class);
    }

    /**
     * Get the reponses_etudiants for the question.
     */
    public function reponsesEtudiants(): HasMany
    {
        return $this->hasMany(ReponseEtudiant::class);
    }
}