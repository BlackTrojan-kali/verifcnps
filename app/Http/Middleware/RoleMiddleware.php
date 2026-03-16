<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next,...$roles): Response
    {
        //verifions si l'utilisateur est authentifie
        if(!Auth::user()){
            return response()->json(["message","vous n'êtes pas authentifié"],401);
        }
        if(!in_array($request->user()->role, $roles)){
           return response()->json([
            "message"=>"acces refusé"
           ], 403);
        }
        return $next($request);
    }
}
