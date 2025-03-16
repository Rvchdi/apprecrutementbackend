<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Competence extends Model
{
    use HasFactory;

    /**
     * Le nom de la table associée au modèle.
     *
     * @var string
     */
    protected $table = 'competences';

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'categorie',
    ];

    /**
     * Les attributs qui doivent être convertis en types natifs.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Obtenir les étudiants qui possèdent cette compétence.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function etudiants(): BelongsToMany
    {
        return $this->belongsToMany(Etudiant::class, 'etudiant_competences')
                    ->withPivot('niveau')
                    ->withTimestamps();
    }

    /**
     * Obtenir les offres qui requièrent cette compétence.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function offres(): BelongsToMany
    {
        return $this->belongsToMany(Offre::class, 'offre_competences')
                    ->withTimestamps();
    }
    
    /**
     * Scope pour filtrer les compétences par catégorie.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $categorie
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategorie($query, $categorie)
    {
        return $query->where('categorie', $categorie);
    }

    /**
     * Scope pour rechercher des compétences par leur nom.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $terme
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecherche($query, $terme)
    {
        return $query->where('nom', 'like', "%{$terme}%");
    }
}