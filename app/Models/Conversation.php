<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont assignables en masse.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'offre_id',
    ];

    /**
     * Obtenir les messages de la conversation.
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Obtenir les participants de la conversation.
     */
    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
                    ->withTimestamps();
    }

    /**
     * Obtenir l'offre associée à la conversation.
     */
    public function offre()
    {
        return $this->belongsTo(Offre::class);
    }

    /**
     * Obtenir le dernier message de la conversation.
     */
    public function dernier_message()
    {
        return $this->hasOne(Message::class)->latest();
    }
}