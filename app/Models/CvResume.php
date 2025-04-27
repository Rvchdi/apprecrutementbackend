<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CvResume extends Model
{
    use HasFactory;

    protected $fillable = [
        'etudiant_id',
        'cv_text',
        'resume',
        'competences_detectees',
        'is_processed',
        'processed_at'
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'competences_detectees' => 'array'
    ];

    /**
     * Get the étudiant that owns the CV résumé.
     */
    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }
}