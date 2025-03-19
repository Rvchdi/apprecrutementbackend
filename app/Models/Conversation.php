<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user1_id',
        'user2_id',
        'related_type',
        'related_id'
    ];

    /**
     * Get the first user of the conversation.
     */
    public function user1()
    {
        return $this->belongsTo(User::class, 'user1_id');
    }

    /**
     * Get the second user of the conversation.
     */
    public function user2()
    {
        return $this->belongsTo(User::class, 'user2_id');
    }

    /**
     * Get the messages for the conversation.
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the related candidature if exists.
     */
    public function candidature()
    {
        if ($this->related_type == 'candidature') {
            return $this->belongsTo(Candidature::class, 'related_id');
        }
        return null;
    }

    /**
     * Get the related offre if exists.
     */
    public function offre()
    {
        if ($this->related_type == 'offre') {
            return $this->belongsTo(Offre::class, 'related_id');
        }
        return null;
    }
}

