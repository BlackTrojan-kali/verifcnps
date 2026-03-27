<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage; // Indispensable pour supprimer l'ancien PDF lors d'une modification

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class CompanyController extends Controller
{
    #[OA\Get(
        path: '/api/company/declarations',
        operationId: 'companyListDeclarations',
        summary: 'Lister l\'historique des déclarations de l\'entreprise',
        description: 'Retourne les déclarations de l\'entreprise connectée avec possibilités de filtres.',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'bank_id', in: 'query', required: false, description: 'ID de la banque', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'reference', in: 'query', required: false, description: 'Référence de la transaction', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'mobile_reference', in: 'query', required: false, description: 'Référence Mobile Money', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'period', in: 'query', required: false, description: 'Période (ex: 2026-03)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Historique récupéré avec succès')]
    /**
     * 1. Lister l'historique des déclarations de l'entreprise
     */
    public function index(Request $request)
    {
        $company = Auth::user()->company; // Correction: pas de parenthèses

        // On charge la relation "bank" pour afficher son nom côté React
        $query = Declaration::with('bank')->where("company_id", $company->id);

        $query->when($request->filled("bank_id"), function($q) use($request){
            $q->where("bank_id", $request->bank_id);
        });

        $query->when($request->filled("reference"), function($q) use($request){
            $q->where("reference", "like", "%" . $request->reference . "%");
        });

        $query->when($request->filled("mobile_reference"), function($q) use($request){
            $q->where("mobile_reference", "like", "%" . $request->mobile_reference . "%");
        });

        $query->when($request->filled("period"), function($q) use($request){
            $q->where("period", $request->period);
        });

        // Correction de la logique des dates
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start_date = Carbon::parse($request->start_date)->startOfDay();
            $end_date = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween("created_at", [$start_date, $end_date]);
        }

        $declarations = $query->orderBy("created_at", "desc")->paginate(25);

        return response()->json([
            "declarations" => $declarations
        ]);
    }
    
    #[OA\Post(
        path: '/api/company/declarations',
        operationId: 'InitiateDeclaration',
        summary: 'Initier une nouvelle déclaration',
        description: 'Crée une déclaration et envoie la preuve de paiement (PDF).',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['reference', 'period', 'amount', 'payment_mode'],
                properties: [
                    new OA\Property(property: 'bank_id', type: 'integer', description: 'ID de la banque (requis sauf pour MoMo)'),
                    new OA\Property(property: 'reference', type: 'string', description: 'Référence du paiement'),
                    new OA\Property(property: 'mobile_reference', type: 'string', description: 'Référence transaction MoMo'),
                    new OA\Property(property: 'period', type: 'string', format: 'date', description: 'Période concernée'),
                    new OA\Property(property: 'amount', type: 'number', description: 'Montant payé'),
                    new OA\Property(property: 'payment_mode', type: 'string', enum: ['virement', 'especes', 'ordre_virement', 'mobile_money', 'orange_money']),
                    new OA\Property(property: 'proof_pdf', type: 'string', format: 'binary', description: 'Preuve de paiement (PDF)'),
                    new OA\Property(property: 'status', type: 'string', description: 'Statut initial (submited par défaut)')
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Paiement initié avec succès')]
    /**
     * 2. Initier une nouvelle déclaration depuis l'espace Entreprise
     */
    public function InitiateDeclaration(Request $request)
    {
        $request->validate([
            "bank_id" => "nullable|integer|exists:banks,id", 
            "period" => "required|date",
            "amount" => "required|numeric",
            "payment_mode" => "required|string|in:virement,especes,ordre_virement,mobile_money,orange_money",
            "status" => "nullable|string",
        ]);

        $company = Auth::user()->company; 
        $date = Carbon::parse($request->period); 

        $path = null;
        if ($request->hasFile("proof_pdf")) {
            $path = $request->file("proof_pdf")->store("proofs", "public");
        }

        // Si MoMo, on passe directement à l'étape "bank_validated" car la banque n'intervient pas
        $isMobileMoney = in_array($request->payment_mode, ['mobile_money', 'orange_money']);
        $finalStatus = $isMobileMoney ? 'bank_validated' : ($request->status ?? "submited");

        $declaration = Declaration::create([
            "company_id" => $company->id,
            "bank_id" => $request->bank_id,
            "period" => $date,
            "amount" => $request->amount,
            "payment_mode" => $request->payment_mode, 
            "status" => $finalStatus, 
        ]);

        // ========================================================
        // GESTION INTELLIGENTE DES NOTIFICATIONS
        // ========================================================
        $formattedAmount = number_format($declaration->amount, 0, ',', ' ') . " FCFA";

        if ($isMobileMoney) {
            // C'est du MoMo : On alerte directement les agents CNPS
            $cnpsAgents = User::where('role', 'cnps')->get();
            Notification::send($cnpsAgents, new DeclarationStatusUpdated(
                "Nouveau paiement Mobile Money de $formattedAmount reçu de {$company->raison_sociale}.", 
                $declaration
            ));
        } else {
            // C'est un virement classique : On alerte uniquement la banque concernée
            $bank = Bank::with('user')->find($request->bank_id);
            if ($bank && $bank->user) {
                $bank->user->notify(new DeclarationStatusUpdated(
                    "L'entreprise {$company->raison_sociale} vous a transmis une preuve de virement ($formattedAmount) pour validation.", 
                    $declaration
                ));
            }
        }

        return response()->json([
            "message" => "Paiement initié avec succès",
            "declaration" => $declaration
        ]);
    }

    #[OA\Put(
        path: '/api/company/declarations/{id}',
        operationId: 'EditDeclaration',
        summary: 'Modifier une déclaration',
        description: 'Corriger une déclaration (généralement après un rejet). Utilisez POST avec _method=PUT si vous envoyez un fichier PDF.',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['reference', 'amount', 'payment_mode'],
                properties: [
                    new OA\Property(property: 'bank_id', type: 'integer'),
                    new OA\Property(property: 'reference', type: 'string'),
                    new OA\Property(property: 'mobile_reference', type: 'string'),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'payment_mode', type: 'string', enum: ['virement', 'especes', 'ordre_virement', 'mobile_money', 'orange_money']),
                    new OA\Property(property: 'proof_pdf', type: 'string', format: 'binary', description: 'Nouveau fichier PDF (optionnel)')
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Déclaration corrigée avec succès')]
    #[OA\Response(response: 403, description: 'Impossible de modifier une transaction validée')]
    /**
     * 3. Modifier une déclaration (Généralement après un rejet de la banque)
     */
    public function EditDeclaration(Request $request, $id)
    {
        $company = Auth::user()->company;
        
        $declaration = Declaration::where('id', $id)->where('company_id', $company->id)->firstOrFail();

        if (in_array($declaration->status, ['cnps_validated', 'bank_validated'])) {
            return response()->json(['message' => 'Impossible de modifier une transaction validée.'], 403);
        }

        $request->validate([
            "bank_id" => "nullable|integer|exists:banks,id",
            "reference" => "required|string",
            "mobile_reference" => "nullable|string",
            "amount" => "required|numeric",
            "payment_mode" => "required|string|in:virement,especes,ordre_virement,mobile_money,orange_money", 
            "proof_pdf" => "nullable|file|mimes:pdf|max:5096",
        ]);

        if ($request->hasFile('proof_pdf')) {
            if ($declaration->proof_path) {
                Storage::disk('public')->delete($declaration->proof_path);
            }
            $declaration->proof_path = $request->file("proof_pdf")->store("proofs", "public");
        }

        $isMobileMoney = in_array($request->payment_mode, ['mobile_money', 'orange_money']);
        $finalStatus = $isMobileMoney ? 'bank_validated' : 'submited';

        $declaration->update([
            "bank_id" => $request->bank_id,
            "reference" => $request->reference,
            "mobile_reference" => $request->mobile_reference,
            "amount" => $request->amount,
            "payment_mode" => $request->payment_mode,
            "status" => $finalStatus 
        ]);

        // ========================================================
        // ENVOYER À NOUVEAU LA NOTIFICATION APRÈS CORRECTION
        // ========================================================
        if (!$isMobileMoney && $request->bank_id) {
            $bank = Bank::with('user')->find($request->bank_id);
            if ($bank && $bank->user) {
                $bank->user->notify(new DeclarationStatusUpdated(
                    "L'entreprise {$company->raison_sociale} a corrigé et renvoyé sa preuve de virement.", 
                    $declaration
                ));
            }
        }

        return response()->json([
            "message" => "Déclaration corrigée et renvoyée avec succès",
            "declaration" => $declaration
        ]);
    }

    #[OA\Patch(
        path: '/api/company/declarations/{id}/status',
        operationId: 'changeDeclarationStatus',
        summary: 'Changer directement le statut (Utilitaire)',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['status'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['initiated', 'submited', 'bank_validated', 'cnps_validated', 'rejected'])
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    /**
     * 4. (Utilitaire) Changer directement le statut
     */
    public function changeDeclarationStatus(Request $request, $id)
    {
        $request->validate([
            "status" => "required|string|in:initiated,submited,bank_validated,cnps_validated,rejected"
        ]);
        
        $declaration = Declaration::findOrFail($id);
        $declaration->status = $request->status; 
        $declaration->save();
        
        return response()->json([
            "message" => "Statut mis à jour avec succès"
        ]);
    }
 
    #[OA\Get(
        path: '/api/company/declarations/{id}/download-receipt',
        operationId: 'downloadReceipt',
        summary: 'Télécharger la quittance officielle de la CNPS',
        description: 'Télécharge le fichier PDF de la quittance si elle a été générée.',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Fichier de la quittance', content: new OA\MediaType(mediaType: 'application/pdf'))]
    #[OA\Response(response: 403, description: 'Accès non autorisé')]
    #[OA\Response(response: 404, description: 'Quittance introuvable')]
    /**
     * Endpoint général pour télécharger la quittance officielle de la CNPS (PDF)
     */
    public function downloadReceipt($id)
    {
        $user = Auth::user();
        $declaration = Declaration::find($id);

        if (!$declaration) {
            return response()->json(['message' => 'Cotisation introuvable.'], 404);
        }

        // --- SÉCURITÉ ---
        if ($user->role === 'company' && $declaration->company_id !== $user->company->id) {
            return response()->json(['message' => 'Accès non autorisé à ce document.'], 403);
        }

        // --- VÉRIFICATION DU FICHIER DE QUITTANCE ---
        if (empty($declaration->receipt_path)) {
            return response()->json(['message' => 'La quittance officielle n\'a pas encore été générée par la CNPS pour cette déclaration.'], 404);
        }

        if (!Storage::disk('public')->exists($declaration->receipt_path)) {
            return response()->json(['message' => 'Le fichier physique de la quittance est introuvable sur le serveur.'], 404);
        }

        // --- TÉLÉCHARGEMENT ---
        $fileName = 'Quittance_CNPS_' . $declaration->reference . '.pdf';

        return Storage::disk('public')->download($declaration->receipt_path, $fileName);
    }
}