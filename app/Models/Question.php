<?php

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