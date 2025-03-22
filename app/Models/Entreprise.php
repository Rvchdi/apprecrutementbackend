<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entreprise extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'entreprises';

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