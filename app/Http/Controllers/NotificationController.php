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
        Auth::user()->notifications()->where("id",$id)->markAsRead();
        return response()->json(["message"=>"notification read"],201);
    }
    public function markAllAsRead(){
        Auth::user()->unreadNotifications->markAsRead;
        return response()->json(["message"=>"all notifications as been read"]);
    }
}
