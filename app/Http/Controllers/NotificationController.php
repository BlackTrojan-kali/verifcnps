<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    //
    public function unread(){
        $notifications = Auth::user()->unreadNotifications;
        return response()->json($notifications);
    }

    public function all(){
        $notifications = Auth::user()->notifications()->paginate(50);
        return response()->json($notifications);
    }
   public function markAsRead($id){
        // 1. On cherche la notification par son ID
        $notification = Auth::user()->notifications()->find($id);
        
        // 2. Si on la trouve, on la marque comme lue
        if ($notification) {
            $notification->markAsRead();
        }
        
        return response()->json(["message" => "notification read"], 201);
    }
    public function markAllAsRead(){
      Auth::user()->unreadNotifications->markAsRead(); // <--- avec les parenthèses ()
        return response()->json(["message"=>"all notifications as been read"]);
    }
}
