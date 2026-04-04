<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http; // <-- Ajout de la façade Http

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
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'guichet@banque.cm'),
                new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password')
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
            "email" => "email|required",
            "password" => "required|string|min:4",
        ]);

        $credentials = [
            "email" => $request->email,
            "password" => $request->password
        ];

        if(!Auth::attempt($credentials)){
            return response()->json(["message" => "Identifiants invalides"], 401);
        }

        $user = User::where("email", $request->email)->first();

        if($user->role === "company"){
            return response()->json(["message" => "Les entreprises doivent se connecter via l'API spécifique"], 403);
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

        $token = $user->createToken("verif_cnps_token")->plainTextToken;

        return response()->json([
            "user" => $user,
            "access_token" => $token,
            "token_type" => "Bearer",
            "message" => "Authentification réussie"
        ]);
    }

    #[OA\Post(
        path: '/api/login-company',
        operationId: 'loginCompany',
        summary: "Connexion d'une entreprise via Numéro Employeur",
        description: "Authentifie ou crée une entreprise avec son Numéro Employeur après vérification sur la plateforme CNPS, et génère un token Danazpay.",
        tags: ['Authentification']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['numero_employeur'],
            properties: [
                new OA\Property(property: 'numero_employeur', type: 'string', example: 'M0123456789', description: "Numéro Employeur CNPS"),
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
    #[OA\Response(response: 400, description: 'Numéro employeur non reconnu par la CNPS')]
    #[OA\Response(response: 503, description: 'Service CNPS indisponible')]
    public function loginCompany(Request $request)
    {
        $request->validate([
            "numero_employeur" => ["required", "string"], 
            "name" => "string|nullable",
        ]);

        $numeroEmployeur = $request->numero_employeur;

        // ==========================================
        // VÉRIFICATION AUPRÈS DE L'API EXTERNE CNPS
        // ==========================================
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent' => 'USERAGENT_TEST' // Vous pourrez remplacer par 'Danazpay' en production
            ])->get("http://172.17.15.151:8080/energiZerServiceREST/paiement/IdentifierE/" . urlencode($numeroEmployeur));

            // Si le serveur CNPS retourne une erreur (4xx ou 5xx)
            if ($response->failed()) {
                return response()->json([
                    "message" => "Échec de la vérification. Le numéro employeur n'a pas pu être validé par la plateforme CNPS."
                ], 400);
            }
            
            // Note : Si l'API renvoie un statut 200 même quand le numéro n'est pas trouvé (avec un message spécifique dans le JSON), 
            // vous pouvez ajouter une condition ici. Par exemple :
            // if ($response->json('status') !== 'SUCCESS') { return ... }

        } catch (\Exception $e) {
            // Si le serveur est injoignable (timeout, mauvaise URL, etc.)
            return response()->json([
                "message" => "Impossible de contacter le serveur de vérification de la CNPS pour le moment."
            ], 503);
        }
        // ==========================================

        // Si la vérification passe, on cherche ou on crée l'entreprise
        $company = Company::where("numero_employeur", $numeroEmployeur)->first();
        
        if (!$company) {
            $user = User::create([
                "role" => "company",
            ]);
            
            $company = Company::create([
                "user_id" => $user->id,
                "numero_employeur" => $numeroEmployeur,
                "raison_sociale" => $request->name ?? "À définir",
                "is_verified" => true // On le passe directement à true vu que la CNPS vient de le valider
            ]);
        
            $user->load("company");
        } else {
            $user = $company->user; 
            
            // S'assurer qu'elle est marquée comme vérifiée si ce n'était pas le cas
            if (!$company->is_verified) {
                $company->update(['is_verified' => true]);
            }
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
            "message" => "Vous êtes déconnecté"
        ]);
    }
}