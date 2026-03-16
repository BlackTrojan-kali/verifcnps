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

   public function loginCompany(Request $request)
    {
        
        // On peut même utiliser notre règle ValidNiu ici !
        $request->validate([
            "niu" => ["required", "string"], // Ajoutez new \App\Rules\ValidNiu() si vous le souhaitez
            "name" => "string|nullable",
        ]);

        // 1. Correction de la faute de frappe (niu au lieu de nui)
        $company = Company::where("niu", $request->niu)->first();
        
        // 2. Gestion correcte de la variable $user
        if (!$company) {
            // L'entreprise n'existe pas, on la crée
            $user = User::create([
                "role" => "company",
                // Note: Si votre table users exige un mot de passe ou un email, 
                // vous devrez générer des valeurs par défaut ici.
            ]);
            
            $company = Company::create([
                "user_id" => $user->id,
                "niu" => $request->niu,
                "raison_sociale" => $request->name ?? "À définir"
            ]);
        
        // 3. On charge la bonne relation (company, et non cnps)
        $user->load("company");
        
            } else {
            // L'entreprise existe, on DOIT récupérer l'utilisateur qui lui est lié !
            $user = $company->user; 
        }
        
        // 4. Correction de la génération du token Sanctum
        $token = $user->createToken("verif_cnps_token")->plainTextToken;
        
        return response()->json([
            "message" => "Authentification réussie",
            "access_token" => $token, // Nommé access_token pour correspondre à notre code React !
            "token_type" => "Bearer",
            "user" => $user,
        ]);
    }
      public function  me(){
        $user =  Auth::user();
        if($user->role === "company"){
        
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
