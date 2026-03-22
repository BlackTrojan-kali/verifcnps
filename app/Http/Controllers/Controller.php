<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

// L'importation a changé : "Attributes" au lieu de "Annotations"
use OpenApi\Attributes as OA; 

#[OA\Info(
    version: "1.0.0", 
    description: "API pour la gestion des paiements CNPS, Banques et Entreprises.", 
    title: "Documentation API DANAZ Pay"
)]
#[OA\Server(
    url: "http://localhost:8000", 
    description: "Serveur Principal local"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth", 
    type: "http", 
    name: "bearerAuth", 
    in: "header", 
    scheme: "bearer", 
    bearerFormat: "JWT"
)]
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}