<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // <-- NE PAS OUBLIER CET IMPORT
use Illuminate\Support\Facades\Storage;

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class DeclarationController extends Controller
{
    #[OA\Get(
        path: '/api/declarations/{id}',
        operationId: 'showDeclarationGeneral',
        summary: 'Détail d\'une cotisation spécifique',
        description: 'Endpoint général permettant à une banque ou une entreprise de voir les détails de SA cotisation.',
        tags: ['Déclarations (Général)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Détails récupérés avec succès')]
    #[OA\Response(response: 403, description: 'Accès non autorisé')]
    #[OA\Response(response: 404, description: 'Cotisation introuvable')]
    /**
     * Endpoint de détail d'une cotisation spécifique avec ses informations de paiement.
     */
    public function show($id)
    {
        $user = Auth::user();
        $declaration = Declaration::with(['company', 'bank'])->find($id);

        if (!$declaration) {
            return response()->json(['message' => 'Cotisation introuvable.'], 404);
        }

        // --- SÉCURITÉ (Autorisations) ---
        if ($user->role === 'company' && $declaration->company_id !== $user->company->id) {
            return response()->json(['message' => 'Accès non autorisé à cette cotisation.'], 403);
        }
        if ($user->role === 'bank' && $declaration->bank_id !== $user->bank->id) {
            return response()->json(['message' => 'Accès non autorisé à cette cotisation.'], 403);
        }

        return response()->json([
            'message' => 'Détails de la cotisation récupérés avec succès.',
            'declaration' => $declaration
        ]);
    }

    #[OA\Get(
        path: '/api/declarations/{id}/download-proof',
        operationId: 'downloadProofGeneral',
        summary: 'Télécharger la preuve de paiement (PDF)',
        description: 'Permet de télécharger le justificatif de paiement uploadé par l\'entreprise.',
        tags: ['Déclarations (Général)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Fichier de la preuve (PDF)', content: new OA\MediaType(mediaType: 'application/pdf'))]
    #[OA\Response(response: 403, description: 'Accès non autorisé')]
    #[OA\Response(response: 404, description: 'Cotisation ou fichier introuvable')]
    /**
     * Endpoint général pour télécharger la preuve de paiement (PDF)
     */
    public function downloadProof($id)
    {
        $user = Auth::user();
        $declaration = Declaration::find($id);

        if (!$declaration) {
            return response()->json(['message' => 'Cotisation introuvable.'], 404);
        }

        // --- SÉCURITÉ IDENTIQUE (On empêche le vol de documents entre entreprises) ---
        if ($user->role === 'company' && $declaration->company_id !== $user->company->id) {
            return response()->json(['message' => 'Accès non autorisé à ce document.'], 403);
        }
        if ($user->role === 'bank' && $declaration->bank_id !== $user->bank->id) {
            return response()->json(['message' => 'Accès non autorisé à ce document.'], 403);
        }

        // --- VÉRIFICATION DU FICHIER ---
        // 1. Vérifier si un chemin a bien été enregistré en base de données
        if (empty($declaration->proof_path)) {
            return response()->json(['message' => 'Aucune preuve de paiement n\'est rattachée à cette déclaration (ex: Paiement Mobile Money).'], 404);
        }

        // 2. Vérifier si le fichier physique existe réellement sur le serveur
        if (!Storage::disk('public')->exists($declaration->proof_path)) {
            return response()->json(['message' => 'Le fichier physique est introuvable sur le serveur.'], 404);
        }

        // --- TÉLÉCHARGEMENT ---
        // On donne un nom propre au fichier lors du téléchargement (ex: Preuve_VRT-12345.pdf)
        $fileName = 'Preuve_' . $declaration->reference . '.pdf';

        return Storage::disk('public')->download($declaration->proof_path, $fileName);
    }
    
}