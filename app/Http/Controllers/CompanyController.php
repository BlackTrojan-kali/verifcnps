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
use Illuminate\Support\Facades\Storage; 
use Illuminate\Support\Facades\Http; // <-- Indispensable pour l'API CNPS
use Illuminate\Support\Facades\Log;  // <-- Pour logger les appels API

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class CompanyController extends Controller
{
    /**
     * ========================================================
     * MÉTHODE PRIVÉE : SYNCHRONISATION AVEC L'API DE LA CNPS
     * ========================================================
     */
    private function syncWithCnpsApi(Declaration $declaration)
    {
        $declaration->loadMissing('company');

        // Codes de paiement (Mobile Money = 14, Orange Money = 15)
        $modePaiementCode = '14'; 
        $origine = 'MOMO_API';
        if ($declaration->payment_mode === 'orange_money') {
            $modePaiementCode = '15';
            $origine = 'OMCAM';
        }

        // Construction du Payload (Tableau d'objets comme défini dans votre CURL)
        $payload = [
            [
                "matricule" => $declaration->employer_number ?? $declaration->company->numero_employeur,
                "id_paiement" => $declaration->payment_id ?? 'MOMO-' . $declaration->id . '-' . time(),
                "montant" => (string) $declaration->amount, 
                "date_paiement" => Carbon::parse($declaration->payment_date ?? now())->format('d-m-Y'),
                "origine" => $declaration->payment_origin ?? $origine,
                "mode_paiement" => $declaration->payment_mode_code ?? $modePaiementCode,
                "type_assu" => $declaration->insurance_type ?? 'EM',
                "telephone" => $declaration->payer_phone ?? $declaration->company->telephone ?? '000000000',
                "localisation" => $declaration->location_code ?? '000-000-000',
                "nomBank" => $declaration->bank_name ?? 'MOBILE MONEY',
                "refTransactionBank" => $declaration->mobile_reference ?? $declaration->reference ?? 'NON_APPLICABLE'
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent' => 'Danazpay'
            ])->put("http://172.17.15.151:8080/energiZerServiceREST/paiement/transfert", $payload);

            if ($response->failed()) {
                Log::error("Échec de la synchronisation CNPS (MoMo) pour la déclaration ID {$declaration->id}. Statut: {$response->status()} - Réponse: {$response->body()}");
            } else {
                Log::info("Synchronisation CNPS (MoMo) réussie pour la déclaration ID {$declaration->id}.");
            }
        } catch (\Exception $e) {
            Log::error("Erreur critique lors de l'appel API CNPS (MoMo) pour la déclaration ID {$declaration->id} : " . $e->getMessage());
        }
    }

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
        $company = Auth::user()->company; 

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
                required: ['period', 'amount', 'payment_mode'],
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
            "reference" => "nullable|string",
            "mobile_reference" => "nullable|string",
            "period" => "required|date",
            "amount" => "required|numeric",
            "payment_mode" => "required|string|in:virement,especes,ordre_virement,mobile_money,orange_money",
            "status" => "nullable|string",
            "proof_pdf" => "nullable|file|mimes:pdf|max:5096",
        ]);

        $company = Auth::user()->company; 
        $date = Carbon::parse($request->period); 

        $path = null;
        if ($request->hasFile("proof_pdf")) {
            $path = $request->file("proof_pdf")->store("proofs", "public");
        }

        $isMobileMoney = in_array($request->payment_mode, ['mobile_money', 'orange_money']);
        $finalStatus = $isMobileMoney ? 'bank_validated' : ($request->status ?? "submited");

        $declaration = Declaration::create([
            "company_id" => $company->id,
            "bank_id" => $isMobileMoney ? null : $request->bank_id, // Pas de banque pour MoMo
            "reference" => $request->reference,
            "mobile_reference" => $request->mobile_reference,
            "period" => $date,
            "amount" => $request->amount,
            "payment_mode" => $request->payment_mode, 
            "payment_date" => $isMobileMoney ? now() : null, // On acte la date si c'est instantané
            "proof_path" => $path,
            "status" => $finalStatus, 
        ]);

        // ========================================================
        // GESTION INTELLIGENTE DES NOTIFICATIONS & API CNPS
        // ========================================================
        $formattedAmount = number_format($declaration->amount, 0, ',', ' ') . " FCFA";

        if ($isMobileMoney) {
            // 1. Appel API de la CNPS immédiatement
            $this->syncWithCnpsApi($declaration);

            // 2. Alerte aux agents CNPS
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
                required: ['amount', 'payment_mode'],
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
            "reference" => "nullable|string",
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
            "bank_id" => $isMobileMoney ? null : $request->bank_id,
            "reference" => $request->reference,
            "mobile_reference" => $request->mobile_reference,
            "amount" => $request->amount,
            "payment_mode" => $request->payment_mode,
            "payment_date" => $isMobileMoney ? now() : $declaration->payment_date,
            "status" => $finalStatus 
        ]);

        // ========================================================
        // GESTION DES NOTIFICATIONS ET API APRÈS CORRECTION
        // ========================================================
        if ($isMobileMoney) {
            // Si la correction transforme le paiement en MoMo, on le pousse à la CNPS
            $this->syncWithCnpsApi($declaration);
            
            $cnpsAgents = User::where('role', 'cnps')->get();
            Notification::send($cnpsAgents, new DeclarationStatusUpdated(
                "L'entreprise {$company->raison_sociale} a corrigé sa déclaration et payé par Mobile Money.", 
                $declaration
            ));
        } elseif ($request->bank_id) {
            // Renvoi de la notification à la banque
            $bank = Bank::with('user')->find($request->bank_id);
            if ($bank && $bank->user) {
                $bank->user->notify(new DeclarationStatusUpdated(
                    "L'entreprise {$company->raison_sociale} a corrigé et renvoyé sa preuve de paiement.", 
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
        description: 'Télécharge le fichier PDF de la quittance depuis le serveur FTP de la CNPS.',
        tags: ['Espace Entreprise'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Fichier de la quittance', content: new OA\MediaType(mediaType: 'application/pdf'))]
    #[OA\Response(response: 403, description: 'Accès non autorisé')]
    #[OA\Response(response: 404, description: 'Quittance introuvable')]
    /**
     * 5. Endpoint pour télécharger la quittance officielle de la CNPS (Proxy FTP)
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

        // --- VÉRIFICATION DU CHEMIN DE LA QUITTANCE (URL FTP) ---
        if (empty($declaration->receipt_path)) {
            return response()->json(['message' => 'La quittance officielle n\'a pas encore été générée par la CNPS pour cette déclaration.'], 404);
        }

        // Le chemin est une URL externe (ex: ftp://...), on lit directement le flux
        // (Attention: il faut que 'allow_url_fopen' soit activé sur votre serveur PHP)
        try {
            $fileContent = @file_get_contents($declaration->receipt_path);

            if ($fileContent === false) {
                return response()->json(['message' => 'Impossible de récupérer la quittance depuis le serveur distant de la CNPS.'], 404);
            }

            $fileName = 'Quittance_CNPS_' . ($declaration->reference ?? $declaration->id) . '.pdf';

            // On retourne le fichier directement dans la réponse HTTP comme un téléchargement normal
            return response($fileContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');

        } catch (\Exception $e) {
            Log::error("Erreur de téléchargement FTP pour la quittance ID {$declaration->id} : " . $e->getMessage());
            return response()->json(['message' => 'Une erreur est survenue lors du contact avec le serveur d\'archives de la CNPS.'], 500);
        }
    }
}