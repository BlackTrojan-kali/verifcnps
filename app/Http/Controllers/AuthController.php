<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    //
    public function login(Request $request){
        
        $request->validate([
            "email"=>"email|required ",
            "password"=>"required| string|min:4",
        ]);
        $credentials = [
            "email" => $request->email,
            "password" => $request->password
        ];
        if(!Auth::attempt($credentials)){
            return response()->json("identifiants invalides",401);
        }
        $user = User::where("email",$request->email)->first();

        if($user->role === "company"){
            return response()->json("les entreprises doivent se connecter via l'api");
        }

        if($user->role === "bank"){
            $user->load("bank");
        
        }
        if($user->role === "cnps"){
            $user->load("cnps");
        }        
        $token = $user->createToken("verif_cnps_token")->plainTextToken ;

        return  response()->json([
            "user"=>$user,
            "access_token"=>$token,
            "token_type"=>"Bearer",
            "message"=>"authentification reussie"
        ]);
        
    }

    public function loginCompany(Request $request){
        $request->validate([
            "niu"=>"string",
            "name"=>"string|nullable",
        ]);

        $company  =  Company::where("niu",$request->nui)->first();
        if(!$company){
            $user = User::create([
                "role"=>"company",
            ]);
            
           $company =  Company::create([
                "user_id"=>$user->id,
                "niu"=>$request->niu,
                "raison_sociale"=>$request->name ?? "a definir"
            ]);
            
        }
        dd($user->load("cnps"));
        
        $token = Auth::createToken("verif_cnps_token")->plainTextToken();
        
        return response()->json([
            "message"=>"authentification reussie",
            "token"=>$token,
            "token_type"=>"Bearer",
            "user"=>$user,
        ]);

    }

    public function  me($id){
        $user =  User::findOrFail($id);
        if($user->role === "company"){
            $user->load("company");
        }
        if($user->role === "bank"){
            $user->load("bank");
        }
        if($user->role === "cnps"){
            $user->load("cnps");
        }
        return response()->json($user);
    }
    public function logout(Request $request){
            Auth::user()->currentAccessToken()->delete;
            return response()->json([
                "message"=>"vous êtes déconnecté"
            ]);
    }


}
