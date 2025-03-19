<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Offre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    /**
     * Récupérer les conversations de l'utilisateur
     */
    public function getConversations()
    {
        $user = Auth::user();
        
        $conversations = $user->conversations()
            ->with(['participants.etudiant', 'participants.entreprise', 'dernier_message', 'offre'])
            ->get();
        
        // Ajouter des infos supplémentaires à chaque conversation
        $conversations->each(function($conversation) use ($user) {
            // Trouver l'autre participant
            $otherParticipant = $conversation->participants->where('id', '!=', $user->id)->first();
            
            if ($otherParticipant) {
                $conversation->other_user = [
                    'id' => $otherParticipant->id,
                    'nom' => $otherParticipant->nom,
                    'prenom' => $otherParticipant->prenom,
                    'role' => $otherParticipant->role,
                    'photo' => $otherParticipant->photo,
                    'entreprise_nom' => $otherParticipant->entreprise->nom_entreprise ?? null
                ];
            }
            
            // Nombre de messages non lus
            $conversation->unread_count = $conversation->messages()
                ->where('sender_id', '!=', $user->id)
                ->where('lu', false)
                ->count();
        });
        
        return response()->json([
            'conversations' => $conversations
        ]);
    }
    
    /**
     * Récupérer les messages d'une conversation
     */
    public function getMessages($id)
    {
        $user = Auth::user();
        
        $conversation = Conversation::findOrFail($id);
        
        // Vérifier que l'utilisateur est un participant
        if (!$conversation->participants->contains($user->id)) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $messages = $conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->get();
        
        // Marquer tous les messages comme lus
        $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('lu', false)
            ->update(['lu' => true]);
        
        return response()->json([
            'messages' => $messages,
            'conversation' => $conversation->load(['participants', 'offre'])
        ]);
    }
    
    /**
     * Envoyer un message
     */
    public function sendMessage(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'contenu' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        
        $conversation = Conversation::findOrFail($id);
        
        // Vérifier que l'utilisateur est un participant
        if (!$conversation->participants->contains($user->id)) {
            return response()->json([
                'message' => 'Action non autorisée'
            ], 403);
        }
        
        $message = new Message([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'contenu' => $request->contenu,
            'lu' => false
        ]);
        
        $message->save();
        
        // Notifier les autres participants
        $otherParticipants = $conversation->participants->where('id', '!=', $user->id);
        foreach ($otherParticipants as $participant) {
            $participant->notifications()->create([
                'titre' => 'Nouveau message',
                'contenu' => "{$user->prenom} {$user->nom} vous a envoyé un message",
                'type' => 'message',
                'lien' => "/messages/{$conversation->id}"
            ]);
        }
        
        return response()->json([
            'message' => 'Message envoyé avec succès',
            'data' => $message->load('sender')
        ]);
    }
    
    /**
     * Créer une nouvelle conversation
     */
    public function createConversation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'participant_id' => 'required|exists:users,id',
            'offre_id' => 'nullable|exists:offres,id',
            'message_initial' => 'required|string'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $participantId = $request->participant_id;
        
        // Vérifier que l'utilisateur ne crée pas une conversation avec lui-même
        if ($user->id === (int)$participantId) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même'
            ], 422);
        }
        
        $participant = User::findOrFail($participantId);
        
        // Vérifier si une conversation existe déjà
        $existingConversation = Conversation::whereHas('participants', function($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->whereHas('participants', function($query) use ($participantId) {
                $query->where('users.id', $participantId);
            })
            ->first();
        
        if ($existingConversation) {
            // Ajouter un nouveau message à la conversation existante
            $message = new Message([
                'conversation_id' => $existingConversation->id,
                'sender_id' => $user->id,
                'contenu' => $request->message_initial,
                'lu' => false
            ]);
            
            $message->save();
            
            return response()->json([
                'message' => 'Message envoyé à une conversation existante',
                'conversation_id' => $existingConversation->id
            ]);
        }
        
        // Créer une nouvelle conversation
        $conversation = new Conversation([
            'offre_id' => $request->offre_id
        ]);
        
        $conversation->save();
        
        // Ajouter les participants
        $conversation->participants()->attach([$user->id, $participantId]);
        
        // Ajouter le premier message
        $message = new Message([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'contenu' => $request->message_initial,
            'lu' => false
        ]);
        
        $message->save();
        
        // Notifier l'autre participant
        $participant->notifications()->create([
            'titre' => 'Nouvelle conversation',
            'contenu' => "{$user->prenom} {$user->nom} a démarré une conversation avec vous",
            'type' => 'message',
            'lien' => "/messages/{$conversation->id}"
        ]);
        
        return response()->json([
            'message' => 'Conversation créée avec succès',
            'conversation_id' => $conversation->id
        ], 201);
    }
    
    /**
     * Récupérer le nombre de messages non lus
     */
    public function getUnreadCount()
    {
        $user = Auth::user();
        
        $count = Message::whereHas('conversation.participants', function($query) use ($user) {
                $query->where('users.id', $user->id);
            })
            ->where('sender_id', '!=', $user->id)
            ->where('lu', false)
            ->count();
        
        return response()->json([
            'count' => $count
        ]);
    }
}