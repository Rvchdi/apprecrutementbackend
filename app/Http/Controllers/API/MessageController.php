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
        
        // Récupérer les conversations où l'utilisateur est participant
        $conversations = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->get();
        
        $result = [];
        
        foreach ($conversations as $conversation) {
            // Trouver l'autre participant de la conversation
            $otherUser = $conversation->participants
                ->where('id', '!=', $user->id)
                ->first();
            
            // Récupérer le dernier message de la conversation
            $lastMessage = Message::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->first();
            
            // Compter les messages non lus
            $unreadCount = Message::where('conversation_id', $conversation->id)
                ->where('sender_id', '!=', $user->id)
                ->where('lu', false)
                ->count();
            
            $result[] = [
                'id' => $conversation->id,
                'user' => $otherUser ? [
                    'id' => $otherUser->id,
                    'nom' => $otherUser->nom,
                    'prenom' => $otherUser->prenom,
                    'role' => $otherUser->role,
                    'photo' => $otherUser->photo
                ] : null,
                'last_message' => $lastMessage ? [
                    'contenu' => $lastMessage->contenu,
                    'date' => $lastMessage->created_at,
                    'is_sent' => $lastMessage->sender_id == $user->id
                ] : null,
                'unread_count' => $unreadCount,
                'offre_id' => $conversation->offre_id
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
        
        // Vérifier si la conversation existe et si l'utilisateur en est participant
        $conversation = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->find($id);
            
        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation introuvable'
            ], 404);
        }
        
        // Récupérer les messages
        $messages = Message::where('conversation_id', $id)
            ->with('sender')
            ->orderBy('created_at', 'asc')
            ->get();
        
        // Marquer les messages comme lus
        Message::where('conversation_id', $id)
            ->where('sender_id', '!=', $user->id)
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
        
        // Vérifier si la conversation existe et si l'utilisateur en est participant
        $conversation = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->find($id);
            
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
        $message->sender_id = $user->id;
        $message->contenu = $request->contenu;
        $message->lu = false;
        $message->save();
        
        // Récupérer les autres participants pour les notifier
        $otherParticipants = $conversation->participants->where('id', '!=', $user->id);
        
        // Créer une notification pour chaque destinataire
        foreach ($otherParticipants as $participant) {
            Notification::create([
                'user_id' => $participant->id,
                'titre' => 'Nouveau message',
                'contenu' => 'Vous avez reçu un nouveau message',
                'type' => 'message',
                'lu' => false
            ]);
        }
        
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
            'participant_id' => 'required|integer|exists:users,id',
            'message_initial' => 'required|string|max:5000',
            'offre_id' => 'nullable|integer|exists:offres,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Vérifier que le destinataire n'est pas l'utilisateur lui-même
        if ($user->id == $request->participant_id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas créer une conversation avec vous-même'
            ], 400);
        }
        
        // Vérifier si une conversation existe déjà entre ces deux utilisateurs
        $existingConversation = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->whereHas('participants', function($query) use ($request) {
            $query->where('users.id', $request->participant_id);
        })->first();
        
        // Si la conversation existe, renvoyer son ID
        if ($existingConversation) {
            // Ajouter un nouveau message à la conversation existante
            $message = new Message();
            $message->conversation_id = $existingConversation->id;
            $message->sender_id = $user->id;
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
        
        // Ajouter l'offre si spécifiée
        if ($request->has('offre_id')) {
            $conversation->offre_id = $request->offre_id;
        }
        
        $conversation->save();
        
        // Attacher les participants
        $conversation->participants()->attach([$user->id, $request->participant_id]);
        
        // Créer le premier message
        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->sender_id = $user->id;
        $message->contenu = $request->message_initial;
        $message->lu = false;
        $message->save();
        
        // Créer une notification pour le destinataire
        Notification::create([
            'user_id' => $request->participant_id,
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
        
        // Vérifier si la conversation existe et si l'utilisateur en est participant
        $conversation = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->find($id);
            
        if (!$conversation) {
            return response()->json([
                'message' => 'Conversation introuvable'
            ], 404);
        }
        
        // Marquer tous les messages non-lus et non-envoyés par l'utilisateur comme lus
        $updatedCount = Message::where('conversation_id', $id)
            ->where('sender_id', '!=', $user->id)
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
        $conversationIds = Conversation::whereHas('participants', function($query) use ($user) {
            $query->where('users.id', $user->id);
        })->pluck('id');
        
        // Compter les messages non lus
        $count = Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $user->id)
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
        $emetteurId = $user->id;
        $destinataireId = $isEntreprise ? $candidature->etudiant->user_id : $candidature->offre->entreprise->user_id;
        
        // Vérifier si une conversation existe déjà entre ces participants pour cette candidature
        $existingConversation = Conversation::whereHas('participants', function($query) use ($emetteurId) {
            $query->where('users.id', $emetteurId);
        })->whereHas('participants', function($query) use ($destinataireId) {
            $query->where('users.id', $destinataireId);
        })->where('offre_id', $candidature->offre_id)
          ->first();
        
        // Si la conversation existe, renvoyer son ID
        if ($existingConversation) {
            // Ajouter un nouveau message à la conversation existante
            $message = new Message();
            $message->conversation_id = $existingConversation->id;
            $message->sender_id = $user->id;
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
        $conversation->offre_id = $candidature->offre_id;
        $conversation->save();
        
        // Attacher les participants
        $conversation->participants()->attach([$emetteurId, $destinataireId]);
        
        // Créer le premier message
        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->sender_id = $user->id;
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