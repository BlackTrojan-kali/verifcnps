<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeclarationController extends Controller
{
    /**
     * Endpoint de détail d'une cotisation spécifique avec ses informations de paiement.
     */
    public function show($id)
    {
        // On récupère l'utilisateur connecté pour vérifier ses droits (optionnel mais recommandé)
        $user = Auth::user();

        // On cherche la déclaration et on charge "automatiquement" l'entreprise et la banque associées
        $declaration = Declaration::with(['company', 'bank'])->find($id);

        // Si l'ID n'existe pas dans la base de données
        if (!$declaration) {
            return response()->json([
                'message' => 'Cotisation introuvable.'
            ], 404);
        }

        // --- SÉCURITÉ (Autorisations) ---
        // Si c'est une entreprise, elle ne peut voir que SES propres déclarations
        if ($user->role === 'company' && $declaration->company_id !== $user->company->id) {
            return response()->json(['message' => 'Accès non autorisé à cette cotisation.'], 403);
        }
        
        // Si c'est une banque, elle ne peut voir que les paiements qui la concernent
        if ($user->role === 'bank' && $declaration->bank_id !== $user->bank->id) {
            return response()->json(['message' => 'Accès non autorisé à cette cotisation.'], 403);
        }

        // La CNPS (rôle 'cnps') passe ces vérifications et peut tout voir.

        return response()->json([
            'message' => 'Détails de la cotisation récupérés avec succès.',
            'declaration' => $declaration
        ]);
    }
}