<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    //
  /**
     * @OA\Post(
     * path="/api/login",
     * operationId="login",
     * tags={"Authentification"},
     * summary="Connecter un utilisateur (Banque ou CNPS)",
     * description="Permet à un agent CNPS ou à une banque de se connecter et de récupérer un token Sanctum.",
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"email","password"},
     * @OA\Property(property="email", type="string", format="email", example="bank@test.com"),
     * @OA\Property(property="password", type="string", format="password", example="password123")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Authentification réussie",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="authentification reussie"),
     * @OA\Property(property="access_token", type="string", example="1|abcdef123456..."),
     * @OA\Property(property="token_type", type="string", example="Bearer"),
     * @OA\Property(property="user", type="object")
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="Identifiants invalides"
     * )
     * )
     */
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
        
        $request->validate([
            "niu" => ["required", "string"], 
            "name" => "string|nullable",
        ]);

        $company = Company::where("niu", $request->niu)->first();
        
        if (!$company) {
            $user = User::create([
                "role" => "company",
            ]);
            
            $company = Company::create([
                "user_id" => $user->id,
                "niu" => $request->niu,
                "raison_sociale" => $request->name ?? "À définir"
            ]);
        
        $user->load("company");
        
            } else {
            $user = $company->user; 
        }
        
        $token = $user->createToken("verif_cnps_token")->plainTextToken;
        
        return response()->json([
            "message" => "Authentification réussie",
            "access_token" => $token, 
            "token_type" => "Bearer",
            "user" => $user,
        ]);
    }
    /**
     * @OA\Get(
     * path="/api/me",
     * operationId="me",
     * tags={"Authentification"},
     * summary="Récupérer le profil de l'utilisateur connecté",
     * description="Retourne les informations de l'utilisateur actuel selon son rôle (Entreprise, Banque, ou Agent CNPS). Nécessite un token valide.",
     * security={{"bearerAuth":{}}},
     * @OA\Response(
     * response=200,
     * description="Profil récupéré avec succès",
     * @OA\JsonContent(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="email", type="string", example="joseph@authentica.cm"),
     * @OA\Property(property="role", type="string", example="company"),
     * @OA\Property(property="company", type="object",
     * @OA\Property(property="niu", type="string", example="M0123456789"),
     * @OA\Property(property="raison_sociale", type="string", example="Ikarootech")
     * )
     * )
     * ),
     * @OA\Response(
     * response=401,
     * description="Non authentifié (Token manquant ou invalide)"
     * )
     * )
     */
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
