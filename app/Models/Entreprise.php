<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Entreprise extends Model
{
    use HasFactory;

    protected $table = 'entreprises'; // Si le nom de la table est différent du modèle

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
        'est_verifie'
    ];

    // Relation avec l'utilisateur (si un user peut créer une entreprise)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
