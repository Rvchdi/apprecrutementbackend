<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Récupérer les notifications de l'utilisateur
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $limit = $request->input('limit', 10);
        
        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
        
        return response()->json([
            'notifications' => $notifications
        ]);
    }
    
    /**
     * Récupérer le nombre de notifications non lues
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnreadCount()
    {
        $user = Auth::user();
        $count = $user->notifications()->where('lu', false)->count();
        
        return response()->json(['count' => $count]);
    }
    
    /**
     * Marquer une notification comme lue
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->find($id);
        
        if (!$notification) {
            return response()->json([
                'message' => 'Notification non trouvée'
            ], 404);
        }
        
        $notification->lu = true;
        $notification->save();
        
        return response()->json([
            'message' => 'Notification marquée comme lue'
        ]);
    }
    
    /**
     * Marquer toutes les notifications comme lues
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        $user->notifications()->update(['lu' => true]);
        
        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues'
        ]);
    }
}