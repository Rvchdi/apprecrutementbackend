<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Récupérer les notifications de l'utilisateur
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = $user->notifications();
        
        // Filtrer par type de notification
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        
        // Filtrer par statut lu/non lu
        if ($request->has('lu')) {
            $query->where('lu', $request->lu == 'true');
        }
        
        $notifications = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'notifications' => $notifications
        ]);
    }
    
    /**
     * Marquer une notification comme lue
     */
    public function markAsRead($id)
    {
        $user = Auth::user();
        
        $notification = $user->notifications()->findOrFail($id);
        $notification->lu = true;
        $notification->save();
        
        return response()->json([
            'message' => 'Notification marquée comme lue'
        ]);
    }
    
    /**
     * Marquer toutes les notifications comme lues
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        
        $user->notifications()->where('lu', false)->update(['lu' => true]);
        
        return response()->json([
            'message' => 'Toutes les notifications ont été marquées comme lues'
        ]);
    }
    
    /**
     * Supprimer une notification
     */
    public function destroy($id)
    {
        $user = Auth::user();
        
        $notification = $user->notifications()->findOrFail($id);
        $notification->delete();
        
        return response()->json([
            'message' => 'Notification supprimée avec succès'
        ]);
    }
    
    /**
     * Supprimer plusieurs notifications
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'integer'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        
        $user->notifications()
            ->whereIn('id', $request->ids)
            ->delete();
        
        return response()->json([
            'message' => 'Notifications supprimées avec succès'
        ]);
    }
    
    /**
     * Récupérer le nombre de notifications non lues
     */
    public function getUnreadCount()
    {
        $user = Auth::user();
        
        $count = $user->notifications()
            ->where('lu', false)
            ->count();
        
        return response()->json([
            'count' => $count
        ]);
    }
}