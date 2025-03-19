<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Candidature;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * Récupérer toutes les conversations de l'utilisateur
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConversations()
    {
        $user = Auth::user();
        
        // Récupérer les IDs des conversations de l'utilisateur
        $conversationIds = Conversation::where('user1_id', $user->id)
            ->orWhere('user2_id', $user->id)
            ->pluck('id');
        
        // Récupérer les derniers messages de chaque conversation
        $lastMessages = Message::whereIn('conversation_id', $conversationIds)
            ->select('conversation_id', DB::raw('MAX(created_at) as last_message_date'))
            ->groupBy('conversation_id');
        
        // Récupérer les conversations avec leurs derniers messages
        $conversations = Conversation::whereIn('id', $conversationIds)
            ->with(['user1', 'user2'])
            ->leftJoinSub($lastMessages, 'last_messages', function($join) {
                $join->on('conversations.id', '=', 'last_messages.conversation_id');
            })
            ->orderBy('last_message_date', 'desc')
            ->get();
        
        $result = [];
        
        foreach ($conversations as $conversation) {
            $otherUser = $conversation->user1_id == $user->id ? $conversation->user2 : $conversation->user1;
            
            // Récupérer le dernier message de la conversation
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            // Compter les messages non lus
            $unreadCount = Message::where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $user->id)
                ->where('lu', false)
                ->count();
            
            $result[] = [
                'id' => $conversation->id,
                'user' => [
                    'id' => $otherUser->id,
                    'nom' => $otherUser->nom,
                    'prenom' => $otherUser->prenom,
                    'role' => $otherUser->role,
                    'photo' => $otherUser->photo
                ],
                'last_message' => $lastMessage ? [
                    'contenu' => $lastMessage->contenu,
                    'date' => $lastMessage->created_at,
                    'is_sent' => $lastMessage->user_id == $user->id
                ] : null,
                'unread_count' => $unreadCount,
                'related_to' => $conversation->related_type . ($conversation->related_id ? ':'.$conversation->related_id : '')
            ];
        }
        
        return response()->json([
            'conversations' => $result
        ]);
    }
    
    /**
     * Récupérer les messages d'une conversation
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessages($id)
    {
        $user = Auth::user();
        
        // Vérifier si la conversation existe et appartient à l'utilisateur
        $conversation = Conversation::where('id', $id)
            ->where(function($query) use ($user) {
                $query->where('user1_id', $user->id)
                      ->orWhere('user2_id', $user->id);
            })
            ->first();
            
        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation introuvable'
            ], 404);
        }
        
        // Récupérer les messages
        $messages = Message::where('conversation_id', $id)
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->get();
        
        // Marquer les messages comme lus
        Message::where('conversation_id', $id)
            ->where('user_id', '!=', $user->id)
            ->where('lu', false)
            ->update(['lu' => true]);
        
        return response()->json([
            'messages' => $messages
        ]);
    }
    
    /**
     * Envoyer un message
     *
     * @param  Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMessage(Request $request, $id)
    {
        $user = Auth::user();
        
        // Vérifier si la conversation existe et appartient à l'utilisateur
        $conversation = Conversation::where('id', $id)
            ->where(function($query) use ($user) {
                $query->where('user1_id', $user->id)
                      ->orWhere('user2_id', $user->id);
            })
            ->first();
            
        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation introuvable'
            ], 404);
        }
        
        // Validation des données
        $validator = Validator::make($request->all(), [
            'contenu' => 'required|string|max:5000'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Créer le message
        $message = new Message();
        $message->conversation_id = $id;
        $message->user_id = $user->id;
        $message->contenu = $request->contenu;
        $message->lu = false;
        $message->save();
        
        // Récupérer le destinataire
        $destinataireId = $conversation->user1_id == $user->id ? $conversation->user2_id : $conversation->user1_id;
        
        // Créer une notification pour le destinataire
        Notification::create([
            'user_id' => $destinataireId,
            'titre' => 'Nouveau message',
            'contenu' => 'Vous avez reçu un nouveau message',
            'type' => 'message',
            'lu' => false
        ]);
        
        return response()->json([
            'message' => 'Message envoyé avec succès',
            'data' => $message
        ]);
    }
    
    /**
     * Créer une nouvelle conversation
     *
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createConversation(Request $request)
    {
        $user = Auth::user();
        
        // Validation des données
        $validator = Validator::make($request->all(), [
            'destinataire_id' => 'required|integer|exists:users,id',
            'message_initial' => 'required|string|max:5000',
            'related_type' => 'nullable|string|in:candidature,offre',
            'related_id' => 'nullable|integer'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Vérifier que le destinataire n'est pas l'utilisateur lui-même
        if ($user->id == $request->destinataire_id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même'
            ], 400);
        }
        
        // Vérifier si une conversation existe déjà entre ces deux utilisateurs
        $existingConversation = Conversation::where(function($query) use ($user, $request) {
            $query->where('user1_id', $user->id)
                  ->where('user2_id', $request->destinataire_id);
        })
        ->orWhere(function($query) use ($user, $request) {
            $query->where('user1_id', $request->destinataire_id)
                  ->where('user2_id', $user->id);
        })
        ->first();
        
        // Si la conversation existe, renvoyer son ID
        if ($existingConversation) {
            // Ajouter un nouveau message à la conversation existante
            $message = new Message();
            $message->conversation_id = $existingConversation->id;
            $message->user_id = $user->id;
            $message->contenu = $request->message_initial;
            $message->lu = false;
            $message->save();
            
            return response()->json([
                'message' => 'Message envoyé à une conversation existante',
                'conversation_id' => $existingConversation->id
            ]);
        }
        
        // Créer la nouvelle conversation
        $conversation = new Conversation();
        $conversation->user1_id = $user->id;
        $conversation->user2_id = $request->destinataire_id;
        
        // Ajouter des informations sur la relation (candidature ou offre)
        if ($request->has('related_type') && $request->has('related_id')) {
            $conversation->related_type = $request->related_type;
            $conversation->related_id = $request->related_id;
        }
        
        $conversation->save();
        
        // Créer le premier message
        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->user_id = $user->id;
        $message->contenu = $request->message_initial;
        $message->lu = false;
        $message->save();
        
        // Créer une notification pour le destinataire
        Notification::create([
            'user_id' => $request->destinataire_id,
            'titre' => 'Nouvelle conversation',
            'contenu' => 'Vous avez reçu un nouveau message',
            'type' => 'message',
            'lu' => false
        ]);
        
        return response()->json([
            'message' => 'Conversation créée avec succès',
            'conversation_id' => $conversation->id
        ], 201);
    }
    
    /**
     * Marquer tous les messages d'une conversation comme lus
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead($id)
    {
        $user = Auth::user();
        
        // Vérifier si la conversation existe et appartient à l'utilisateur
        $conversation = Conversation::where('id', $id)
            ->where(function($query) use ($user) {
                $query->where('user1_id', $user->id)
                      ->orWhere('user2_id', $user->id);
            })
            ->first();
            
        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation introuvable'
            ], 404);
        }
        
        // Marquer tous les messages non-lus et non-envoyés par l'utilisateur comme lus
        $updatedCount = Message::where('conversation_id', $id)
            ->where('user_id', '!=', $user->id)
            ->where('lu', false)
            ->update(['lu' => true]);
            
        return response()->json([
            'message' => "{$updatedCount} message(s) marqué(s) comme lu(s)"
        ]);
    }
    
    /**
     * Obtenir le nombre de messages non lus
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnreadCount()
    {
        $user = Auth::user();
        
        // Récupérer les IDs des conversations de l'utilisateur
        $conversationIds = Conversation::where('user1_id', $user->id)
            ->orWhere('user2_id', $user->id)
            ->pluck('id');
        
        // Compter les messages non lus
        $count = Message::whereIn('conversation_id', $conversationIds)
            ->where('user_id', '!=', $user->id)
            ->where('lu', false)
            ->count();
            
        return response()->json([
            'count' => $count
        ]);
    }
    
    /**
     * Créer une conversation à partir d'une candidature
     *
     * @param  Request  $request
     * @param  int  $candidatureId
     * @return \Illuminate\Http\JsonResponse
     */
    public function createFromCandidature(Request $request, $candidatureId)
    {
        $user = Auth::user();
        
        // Validation du message initial
        $validator = Validator::make($request->all(), [
            'message_initial' => 'required|string|max:5000'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Récupérer la candidature
        $candidature = Candidature::find($candidatureId);
        
        if (!$candidature) {
            return response()->json([
                'message' => 'Candidature introuvable'
            ], 404);
        }
        
        // Vérifier les autorisations
        $isEntreprise = $user->isEntreprise() && $candidature->offre->entreprise_id === $user->entreprise->id;
        $isEtudiant = $user->isEtudiant() && $candidature->etudiant_id === $user->etudiant->id;
        
        if (!$isEntreprise && !$isEtudiant) {
            return response()->json([
                'message' => 'Vous n\'êtes pas autorisé à créer une conversation pour cette candidature'
            ], 403);
        }
        
        // Déterminer l'émetteur et le destinataire
        if ($isEntreprise) {
            $emetteurId = $user->id;
            $destinataireId = $candidature->etudiant->user_id;
        } else {
            $emetteurId = $user->id;
            $destinataireId = $candidature->offre->entreprise->user_id;
        }
        
        // Vérifier si une conversation existe déjà
        $existingConversation = Conversation::where(function($query) use ($emetteurId, $destinataireId) {
            $query->where('user1_id', $emetteurId)
                  ->where('user2_id', $destinataireId);
        })
        ->orWhere(function($query) use ($emetteurId, $destinataireId) {
            $query->where('user1_id', $destinataireId)
                  ->where('user2_id', $emetteurId);
        })
        ->where('related_type', 'candidature')
        ->where('related_id', $candidatureId)
        ->first();
        
        // Si la conversation existe, renvoyer son ID
        if ($existingConversation) {
            // Ajouter un nouveau message à la conversation existante
            $message = new Message();
            $message->conversation_id = $existingConversation->id;
            $message->user_id = $user->id;
            $message->contenu = $request->message_initial;
            $message->lu = false;
            $message->save();
            
            return response()->json([
                'message' => 'Message envoyé à une conversation existante',
                'conversation_id' => $existingConversation->id
            ]);
        }
        
        // Créer la nouvelle conversation
        $conversation = new Conversation();
        $conversation->user1_id = $emetteurId;
        $conversation->user2_id = $destinataireId;
        $conversation->related_type = 'candidature';
        $conversation->related_id = $candidatureId;
        $conversation->save();
        
        // Créer le premier message
        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->user_id = $user->id;
        $message->contenu = $request->message_initial;
        $message->lu = false;
        $message->save();
        
        // Créer une notification pour le destinataire
        Notification::create([
            'user_id' => $destinataireId,
            'titre' => 'Nouveau message concernant une candidature',
            'contenu' => 'Vous avez reçu un nouveau message concernant une candidature',
            'type' => 'message',
            'lu' => false
        ]);
        
        return response()->json([
            'message' => 'Conversation créée avec succès',
            'conversation_id' => $conversation->id
        ], 201);
    }
}