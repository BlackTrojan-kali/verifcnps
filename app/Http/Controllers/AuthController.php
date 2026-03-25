<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Importation requise pour les attributs Swagger PHP 8
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/login',
        operationId: 'login',
        summary: 'Connecter un utilisateur (Banque, CNPS ou Superviseur)',
        description: 'Permet à un agent CNPS, une banque ou un superviseur de se connecter et de récupérer un token Sanctum.',
        tags: ['Authentification']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'bank@test.com'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentification réussie',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'authentification reussie'),
                new OA\Property(property: 'access_token', type: 'string', example: '1|abcdef123456...'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'user', type: 'object')
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Identifiants invalides')]
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

        // Chargement des relations selon le rôle de l'utilisateur
        if($user->role === "bank"){
            $user->load("bank");
        }
        if($user->role === "cnps"){
            $user->load("cnps");
        }        
        if($user->role === "supervisor"){
            $user->load("supervisor");
        }

        $token = $user->createToken("verif_cnps_token")->plainTextToken ;

        return response()->json([
            "user"=>$user,
            "access_token"=>$token,
            "token_type"=>"Bearer",
            "message"=>"authentification reussie"
        ]);
    }

    #[OA\Post(
        path: '/api/login-company',
        operationId: 'loginCompany',
        summary: "Connexion d'une entreprise via NIU",
        description: "Authentifie ou crée une entreprise avec son NIU et génère un token.",
        tags: ['Authentification']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['niu'],
            properties: [
                new OA\Property(property: 'niu', type: 'string', example: 'M0123456789', description: "Numéro d'Identifiant Unique"),
                new OA\Property(property: 'name', type: 'string', example: 'Ikarootech', description: 'Raison sociale (optionnelle)')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentification réussie',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Authentification réussie'),
                new OA\Property(property: 'access_token', type: 'string', example: '1|abcdef...'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'user', type: 'object')
            ]
        )
    )]
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

    #[OA\Get(
        path: '/api/me',
        operationId: 'me',
        summary: "Récupérer le profil de l'utilisateur connecté",
        description: "Retourne les informations de l'utilisateur actuel. Nécessite le token Bearer.",
        tags: ['Authentification'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(
        response: 200,
        description: 'Profil récupéré',
        content: new OA\JsonContent(type: 'object')
    )]
    #[OA\Response(response: 401, description: 'Non authentifié (Token manquant ou invalide)')]
    public function me(){
        $user = Auth::user();
        if($user->role === "company"){
            $user->load("company");
        }
        if($user->role === "bank"){
            $user->load("bank");
        }
        if($user->role === "cnps"){
            $user->load("cnps");
        }
        if($user->role === "supervisor"){
            $user->load("supervisor");
        }
        return response()->json($user);
    }

    #[OA\Get(
        path: '/api/logout',
        operationId: 'logout',
        summary: "Déconnexion de l'utilisateur",
        description: "Détruit le token actuel de l'utilisateur.",
        tags: ['Authentification'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Déconnexion réussie')]
    public function logout(Request $request){
        Auth::user()->currentAccessToken()->delete();
        return response()->json([
            "message"=>"vous êtes déconnecté"
        ]);
    }
}