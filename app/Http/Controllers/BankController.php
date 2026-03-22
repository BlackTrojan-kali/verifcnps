<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class BankController extends Controller
{
    #[OA\Get(
        path: '/api/bank/dashboard-stats',
        operationId: 'bankDashboardStats',
        summary: 'Statistiques pour le Dashboard de la Banque',
        description: 'Retourne les KPIs, les données des modes de paiement et la tendance des encaissements.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Statistiques récupérées avec succès')]
    #[OA\Response(response: 403, description: 'Aucune banque rattachée à cet utilisateur')]
    public function dashboardStats(Request $request)
    {
        $user = Auth::user();
        
        // Sécurité : On s'assure que l'utilisateur a bien une banque rattachée
        if (!$user->bank) {
            return response()->json(['message' => 'Aucune banque rattachée à cet utilisateur.'], 403);
        }

        $bankId = $user->bank->id;

        // 1. Initialisation de la requête restreinte à CETTE banque uniquement
        $query = Declaration::where('bank_id', $bankId);

        // 2. Filtre de dates (Optionnel)
        if ($request->filled(['start_date', 'end_date'])) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // ==========================================
        // CALCUL DES KPIs (Cartes du haut)
        // ==========================================
        
        // En attente d'action par le guichetier (à valider ou rejeter)
        $pendingCount = (clone $query)->where('status', 'submited')->count();
        
        // Dossiers traités et validés par la banque (ou déjà rapprochés par CNPS)
        $validatedCount = (clone $query)->whereIn('status', ['bank_validated', 'cnps_validated'])->count();
        
        // Dossiers rejetés par la banque
        $rejectedCount = (clone $query)->where('status', 'rejected')->count();
        
        // Total de l'argent collecté via cette banque (uniquement les montants validés)
        $totalCollected = (clone $query)->whereIn('status', ['bank_validated', 'cnps_validated'])->sum('amount');

        // ==========================================
        // GRAPHIQUE CAMEMBERT : Modes de paiement
        // ==========================================
        $modes = (clone $query)
            ->select('payment_mode', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_mode')
            ->get();

        $colorMap = [
            'virement'       => '#10B981', // Vert
            'especes'        => '#F59E0B', // Jaune/Orange (Saisie guichet)
            'ordre_virement' => '#8B5CF6'  // Violet (Saisie guichet)
        ];

        $paymentModeData = $modes->map(function ($item) use ($colorMap) {
            $formattedName = ucfirst(str_replace('_', ' ', $item->payment_mode));
            return [
                'name' => $formattedName,
                'value' => (int) $item->count,
                'color' => $colorMap[$item->payment_mode] ?? '#94A3B8'
            ];
        })->values();

        // ==========================================
        // GRAPHIQUE ÉVOLUTION : Encaissements sur les 7 derniers jours
        // ==========================================
        $trendData = (clone $query)
            ->whereIn('status', ['bank_validated', 'cnps_validated'])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    // Format court ex: "18 Mars"
                    'date' => Carbon::parse($item->date)->locale('fr')->translatedFormat('d M'),
                    'amount' => (float) $item->total
                ];
            });

        // ==========================================
        // RÉPONSE AU FORMAT JSON POUR REACT
        // ==========================================
        return response()->json([
            'kpis' => [
                'pendingCount' => $pendingCount,
                'validatedCount' => $validatedCount,
                'rejectedCount' => $rejectedCount,
                'totalCollected' => (float) $totalCollected,
            ],
            'paymentModeData' => $paymentModeData,
            'trendData' => $trendData
        ]);
    }

    #[OA\Get(
        path: '/api/bank/declarations',
        operationId: 'listBankDeclarations',
        summary: 'Lister les déclarations affectées à cette banque',
        description: 'Retourne la liste paginée des déclarations filtrées par statut, période ou référence.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'reference', in: 'query', required: false, description: 'Référence de la déclaration', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'status', in: 'query', required: false, description: 'Statut (ex: submited, bank_validated)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'period', in: 'query', required: false, description: 'Période (ex: 2026-03)', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Liste récupérée avec succès')]
    public function index(Request $request)
    {
        $bank = Auth::user()->bank;
        
        $query = Declaration::with('company')->where("bank_id", $bank->id);

        $query->when($request->filled("reference"), function($q) use ($request) {
            $q->where("reference", "like", "%" . $request->reference . "%");
        });
        
        $query->when($request->filled('mobile_reference'), function($q) use($request) {
            $q->where("mobile_reference", "like", "%" . $request->mobile_reference . "%");
        });
        
        $query->when($request->filled("status"), function($q) use($request) {
            $q->where("status", $request->status);
        });
        
        $query->when($request->filled("period"), function($q) use($request) {
            $q->where("period", $request->period);
        });

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $query->whereNotIn("payment_mode", ['mobile_money', "orange_money"]);
        $query->whereNotIn('status', ['initiated', 'cnps_validated']);
        
        $declarations = $query->orderBy('created_at', 'desc')->paginate(25);

        return response()->json($declarations);
    }

    #[OA\Get(
        path: '/api/bank/declarations/{id}',
        operationId: 'showBankDeclaration',
        summary: 'Afficher les détails d\'une déclaration spécifique',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Détails de la déclaration')]
    #[OA\Response(response: 403, description: 'Accès interdit')]
    #[OA\Response(response: 404, description: 'Déclaration non trouvée')]
    public function show($id)
    {
        $user = Auth::user();
        $declaration = Declaration::findOrFail($id);

        // On vérifie que la déclaration appartient bien à la banque connectée
        if ($declaration->bank_id !== $user->bank->id) {
            return response()->json([
                'message' => 'Accès interdit. Cette déclaration n\'appartient pas à votre banque.'
            ], 403);
        }

        return response()->json([
            'declaration' => $declaration
        ]);
    }

    #[OA\Put(
        path: '/api/bank/declarations/{id}/validate',
        operationId: 'validateBankPayment',
        summary: 'Valider un paiement',
        description: 'Confirmation de réception des fonds par la banque.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['reference'],
            properties: [
                new OA\Property(property: 'reference', type: 'string', description: 'Référence de la transaction bancaire'),
                new OA\Property(property: 'order_reference', type: 'string', description: 'Référence de l\'ordre de virement (si applicable)')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Paiement validé avec succès')]
    #[OA\Response(response: 403, description: 'Déclaration déjà traitée')]
    public function validatePayment(Request $request, $id)
    {
        $bank = Auth::user()->bank;
        $declaration = Declaration::where('bank_id', $bank->id)->findOrFail($id);

        if ($declaration->status !== 'submited') {
            return response()->json(['message' => 'Cette déclaration a déjà été traitée.'], 403);
        }

        $rules = [
            'reference' => 'required|string|unique:declarations,reference,' . $declaration->id,
        ];

        if ($declaration->payment_mode === 'ordre_virement') {
            $rules['order_reference'] = 'required|string|unique:declarations,order_reference,' . $declaration->id;
        }

        $request->validate($rules);

        $declaration->reference = $request->reference;
        
        if ($declaration->payment_mode === 'ordre_virement') {
            $declaration->order_reference = $request->order_reference;
        }

        $declaration->status = 'bank_validated';
        $declaration->save();

        // ========================================================
        // ENVOI DE LA NOTIFICATION À LA CNPS (ET À L'ENTREPRISE)
        // ========================================================
        
        // 1. Alerter tous les agents CNPS
        $cnpsAgents = User::where('role', 'cnps')->get();
        Notification::send($cnpsAgents, new DeclarationStatusUpdated(
            "La banque a transféré un paiement de " . number_format($declaration->amount, 0, ',', ' ') . " FCFA.", 
            $declaration
        ));

        // 2. (Optionnel mais recommandé) Alerter l'entreprise que son paiement a passé l'étape banque
        $declaration->load('company.user');
        if ($declaration->company && $declaration->company->user) {
            $declaration->company->user->notify(new DeclarationStatusUpdated(
                "Votre dépôt a été validé par la banque et transmis à la CNPS.", 
                $declaration
            ));
        }

        return response()->json([
            'message' => 'Paiement validé et transmis à la CNPS avec succès.',
            'declaration' => $declaration
        ]);
    }

    #[OA\Put(
        path: '/api/bank/declarations/{id}/reject',
        operationId: 'rejectBankPayment',
        summary: 'Rejeter un paiement',
        description: 'Refuser une déclaration en attente.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['comment_reject'],
            properties: [
                new OA\Property(property: 'comment_reject', type: 'string', description: 'Motif du rejet (min 5 caractères)')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Paiement rejeté')]
    public function rejectPayment(Request $request, $id)
    {
        $bank = Auth::user()->bank;
        $declaration = Declaration::where('bank_id', $bank->id)->findOrFail($id);

        if ($declaration->status !== 'submited') {
            return response()->json(['message' => 'Cette déclaration a déjà été traitée.'], 403);
        }

        $request->validate([
            'comment_reject' => 'required|string|min:5'
        ]);

        $declaration->status = 'rejected';
        $declaration->comment_reject = $request->comment_reject;
        $declaration->save();

        // ========================================================
        // ENVOI DE LA NOTIFICATION DE REJET À L'ENTREPRISE
        // ========================================================
        $declaration->load('company.user');
        if ($declaration->company && $declaration->company->user) {
            $declaration->company->user->notify(new DeclarationStatusUpdated(
                "Votre dépôt a été rejeté par la banque. Motif : " . $request->comment_reject, 
                $declaration
            ));
        }

        return response()->json([
            'message' => 'Le paiement a été rejeté.',
            'declaration' => $declaration
        ]);
    }

    #[OA\Post(
        path: '/api/bank/counter-deposits',
        operationId: 'storeCounterDeposit',
        summary: 'Créer un dépôt au guichet',
        description: 'Enregistre un paiement en espèces ou ordre de virement fait physiquement à la banque.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['company_id', 'reference', 'payment_mode', 'period', 'amount'],
                properties: [
                    new OA\Property(property: 'company_id', type: 'integer', description: 'ID de l\'entreprise'),
                    new OA\Property(property: 'reference', type: 'string', description: 'Référence du reçu bancaire'),
                    new OA\Property(property: 'payment_mode', type: 'string', enum: ['especes', 'ordre_virement']),
                    new OA\Property(property: 'order_reference', type: 'string', description: 'Référence de l\'ordre si applicable'),
                    new OA\Property(property: 'period', type: 'string', format: 'date', description: 'Période (Y-m-d)'),
                    new OA\Property(property: 'amount', type: 'number', description: 'Montant déposé'),
                    new OA\Property(property: 'proof_pdf', type: 'string', format: 'binary', description: 'Fichier PDF justificatif')
                ]
            )
        )
    )]
    #[OA\Response(response: 201, description: 'Dépôt créé avec succès')]
    public function storeCounterDeposit(Request $request)
    {
        $bank = Auth::user()->bank;

        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'reference' => 'required|string|unique:declarations,reference',
            'payment_mode' => 'required|in:especes,ordre_virement', 
            'order_reference' => 'required_if:payment_mode,ordre_virement|nullable|string|unique:declarations,order_reference',
            'period' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'proof_pdf' => 'nullable|file|mimes:pdf|max:5096', 
        ]);

        $path = null;
        if ($request->hasFile("proof_pdf")) {
            $path = $request->file("proof_pdf")->store("proofs", "public");
        }

        $declaration = Declaration::create([
            'company_id' => $request->company_id,
            'bank_id' => $bank->id, 
            'reference' => $request->reference,
            'order_reference' => $request->payment_mode === 'ordre_virement' ? $request->order_reference : null,
            'period' => Carbon::parse($request->period),
            'amount' => $request->amount,
            'payment_mode' => $request->payment_mode,
            'proof_path' => $path,
            'status' => 'bank_validated', // Validé par défaut
        ]);

        // ========================================================
        // ENVOI DE LA NOTIFICATION À LA CNPS POUR LE DÉPÔT GUICHET
        // ========================================================
        $cnpsAgents = User::where('role', 'cnps')->get();
        Notification::send($cnpsAgents, new DeclarationStatusUpdated(
            "Un nouveau dépôt au guichet de " . number_format($declaration->amount, 0, ',', ' ') . " FCFA a été enregistré.", 
            $declaration
        ));

        return response()->json([
            'message' => 'Dépôt au guichet enregistré avec succès.',
            'declaration' => $declaration->load('company')
        ], 201);
    }

    #[OA\Put(
        path: '/api/bank/counter-deposits/{id}',
        operationId: 'updateCounterDeposit',
        summary: 'Modifier un dépôt au guichet',
        description: 'Met à jour un dépôt existant. Note : utiliser POST avec le paramètre _method=PUT si vous envoyez un fichier.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration à modifier', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                required: ['company_id', 'reference', 'payment_mode', 'period', 'amount'],
                properties: [
                    new OA\Property(property: 'company_id', type: 'integer'),
                    new OA\Property(property: 'reference', type: 'string'),
                    new OA\Property(property: 'payment_mode', type: 'string', enum: ['especes', 'ordre_virement']),
                    new OA\Property(property: 'order_reference', type: 'string'),
                    new OA\Property(property: 'period', type: 'string', format: 'date'),
                    new OA\Property(property: 'amount', type: 'number'),
                    new OA\Property(property: 'proof_pdf', type: 'string', format: 'binary', description: 'Nouveau fichier PDF (optionnel)')
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Dépôt modifié avec succès')]
    #[OA\Response(response: 403, description: 'Modification impossible (déjà validé CNPS)')]
    public function updateCounterDeposit(Request $request, $id)
    {
        $bank = Auth::user()->bank;
        
        $declaration = Declaration::where('bank_id', $bank->id)
            ->whereIn('payment_mode', ['especes', 'ordre_virement'])
            ->findOrFail($id);

        if ($declaration->status === 'cnps_validated') {
            return response()->json([
                'message' => 'Impossible de modifier une transaction déjà clôturée par la CNPS.'
            ], 403);
        }

        $request->validate([
            'company_id' => 'required|exists:companies,id',
            'reference' => 'required|string|unique:declarations,reference,' . $declaration->id,
            'payment_mode' => 'required|in:especes,ordre_virement',
            'order_reference' => 'required_if:payment_mode,ordre_virement|nullable|string|unique:declarations,order_reference,' . $declaration->id,
            'period' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'proof_pdf' => 'nullable|file|mimes:pdf|max:5096',
        ]);

        if ($request->hasFile('proof_pdf')) {
            if ($declaration->proof_path) {
                Storage::disk('public')->delete($declaration->proof_path);
            }
            $declaration->proof_path = $request->file("proof_pdf")->store("proofs", "public");
        }

        $declaration->update([
            'company_id' => $request->company_id,
            'reference' => $request->reference,
            'order_reference' => $request->payment_mode === 'ordre_virement' ? $request->order_reference : null,
            'period' => Carbon::parse($request->period),
            'amount' => $request->amount,
            'payment_mode' => $request->payment_mode,
        ]);

        return response()->json([
            'message' => 'Le dépôt a été modifié avec succès.',
            'declaration' => $declaration->load('company')
        ]);
    }

    #[OA\Get(
        path: '/api/bank/companies/search',
        operationId: 'searchCompanyByNiuBank',
        summary: 'Rechercher une entreprise par NIU',
        description: 'Permet à la banque de retrouver les informations d\'une entreprise lors d\'un dépôt au guichet.',
        tags: ['Espace Banque'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'niu', in: 'query', required: true, description: 'Numéro d\'Identifiant Unique de l\'entreprise', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Entreprise trouvée')]
    #[OA\Response(response: 404, description: 'Aucune entreprise trouvée')]
    public function searchCompanyByNiu(Request $request)
    {
        $request->validate(['niu' => 'required|string']);
        
        $company = Company::where('niu', $request->niu)->first();
        
        if (!$company) {
            return response()->json(['message' => 'Aucune entreprise trouvée avec ce NIU.'], 404);
        }
        
        return response()->json(['company' => $company]);
    }
}