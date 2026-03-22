<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    //
    
    #[OA\Get(
        path: '/api/notifications/unread',
        operationId: 'getUnreadNotifications',
        summary: 'Récupérer les notifications non lues',
        description: 'Retourne la liste complète des notifications non lues de l\'utilisateur connecté.',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des notifications non lues')]
    public function unread(){
        $notifications = Auth::user()->unreadNotifications;
        return response()->json($notifications);
    }

    #[OA\Get(
        path: '/api/notifications/all',
        operationId: 'getAllNotifications',
        summary: 'Récupérer toutes les notifications',
        description: 'Retourne la liste paginée de toutes les notifications (lues et non lues) de l\'utilisateur connecté.',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste paginée des notifications')]
    public function all(){
        $notifications = Auth::user()->notifications()->paginate(50);
        return response()->json($notifications);
    }
    
    #[OA\Put(
        path: '/api/notifications/mark-as-read/{id}',
        operationId: 'markNotificationAsRead',
        summary: 'Marquer une notification comme lue',
        description: 'Change le statut d\'une notification spécifique en "lue".',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la notification (UUID)', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 201, description: 'Notification marquée comme lue')]
    public function markAsRead($id){
        // 1. On cherche la notification par son ID
        $notification = Auth::user()->notifications()->find($id);
        
        // 2. Si on la trouve, on la marque comme lue
        if ($notification) {
            $notification->markAsRead();
        }
        
        return response()->json(["message" => "notification read"], 201);
    }
    
    #[OA\Post(
        path: '/api/notifications/mark-all-as-read',
        operationId: 'markAllNotificationsAsRead',
        summary: 'Marquer toutes les notifications comme lues',
        description: 'Passe toutes les notifications non lues de l\'utilisateur au statut "lue" en une seule action.',
        tags: ['Notifications'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Toutes les notifications ont été marquées comme lues')]
    public function markAllAsRead(){
      Auth::user()->unreadNotifications->markAsRead(); // <--- avec les parenthèses ()
        return response()->json(["message"=>"all notifications as been read"]);
    }
}