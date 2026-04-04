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
        $fileName = 'Preuve_' . ($declaration->reference ?? $declaration->id) . '.pdf';

        return Storage::disk('public')->download($declaration->proof_path, $fileName);
    }

    #[OA\Get(
        path: '/api/declarations/search/specific',
        operationId: 'findDeclarationByNumeroEmployeurAmountPeriod',
        summary: 'Rechercher la déclaration la plus récente',
        description: 'Retrouve la déclaration la plus récente en croisant le Numéro Employeur de l\'entreprise, le montant et la période.',
        tags: ['Déclarations (Général)'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'numero_employeur', in: 'query', required: true, description: 'Numéro Employeur CNPS de l\'entreprise', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'amount', in: 'query', required: true, description: 'Montant de la déclaration', schema: new OA\Schema(type: 'number'))]
    #[OA\Parameter(name: 'period', in: 'query', required: true, description: 'Période (ex: YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Déclaration trouvée avec succès')]
    #[OA\Response(response: 403, description: 'Accès non autorisé')]
    #[OA\Response(response: 404, description: 'Aucune déclaration correspondante')]
    /**
     * Recherche la déclaration la plus récente par Numéro Employeur, montant et période.
     */
    public function findByNumeroEmployeurAmountPeriod(Request $request)
    {
        // 1. Validation des paramètres de recherche
        $request->validate([
            'numero_employeur' => 'required|string',
            'amount' => 'required|numeric',
            'period' => 'required|date',
        ]);

        // 2. Recherche croisée avec la relation Company (en prenant la plus récente)
        $declaration = Declaration::with(['company', 'bank'])
            ->whereHas('company', function ($query) use ($request) {
                // On filtre sur la table "companies" via la relation avec le nouveau nom de colonne
                $query->where('numero_employeur', $request->numero_employeur);
            })
            ->where('amount', $request->amount)
            ->whereDate('period', $request->period)
            ->latest() // <--- Trie par created_at décroissant
            ->first();

        // 3. Gestion du cas où rien n'est trouvé
        if (!$declaration) {
            return response()->json([
                'message' => 'Aucune déclaration ne correspond à ces critères (Numéro Employeur, montant, période).'
            ], 404);
        }

        // 4. --- SÉCURITÉ ---
        // On s'assure qu'une entreprise connectée ne cherche pas le Numéro Employeur d'un concurrent
        $user = Auth::user();
        if ($user->role === 'company' && $declaration->company_id !== $user->company->id) {
            return response()->json(['message' => 'Accès non autorisé à cette cotisation.'], 403);
        }

        // 5. Retour de la déclaration trouvée
        return response()->json([
            'message' => 'Déclaration récente trouvée avec succès.',
            'declaration' => $declaration
        ]);
    }
}