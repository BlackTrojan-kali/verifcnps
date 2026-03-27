<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CnpsAgent;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

// Importation indispensable pour Swagger
use OpenApi\Attributes as OA;

class CnpsController extends Controller
{
    #[OA\Get(
        path: '/api/cnps/declarations',
        operationId: 'cnpsListDeclarations',
        summary: 'Voir et filtrer TOUTES les déclarations',
        description: 'Tableau de bord super-admin pour la CNPS avec recherche et filtres avancés.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filtrer par statut', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'bank_id', in: 'query', required: false, description: 'ID de la banque', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'payment_mode', in: 'query', required: false, description: 'Mode de paiement', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'search', in: 'query', required: false, description: 'Recherche par référence ou NIU', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Liste des déclarations')]
    public function index(Request $request)
    {
        // On charge les relations pour que React ait le NIU de l'entreprise et le nom de la banque
        $query = Declaration::with(['company', 'bank']);

        // Filtre par Statut (ex: voir uniquement ce que la banque a validé)
        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        // Filtre par Banque (ex: voir tous les encaissements de UBA)
        $query->when($request->filled('bank_id'), function ($q) use ($request) {
            $q->where('bank_id', $request->bank_id);
        });

        // Filtre par Mode de paiement
        $query->when($request->filled('payment_mode'), function ($q) use ($request) {
            $q->where('payment_mode', $request->payment_mode);
        });

        // Recherche textuelle puissante (par Référence CNPS ou NIU de l'entreprise)
        $query->when($request->filled('search'), function ($q) use ($request) {
            $searchTerm = '%' . $request->search . '%';
            $q->where(function($subQuery) use ($searchTerm) {
                $subQuery->where('reference', 'LIKE', $searchTerm)
                         ->orWhereHas('company', function($companyQuery) use ($searchTerm) {
                             $companyQuery->where('niu', 'LIKE', $searchTerm);
                         });
            });
        });

        // Filtre de dates de paiement
        $query->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
            $q->whereBetween('created_at', [
                $request->start_date . ' 00:00:00', 
                $request->end_date . ' 23:59:59'
            ]);
        });

        $declarations = $query->where("status","!=","rejected")->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json($declarations);
    }

    #[OA\Put(
        path: '/api/cnps/declarations/{id}/reconcile',
        operationId: 'reconcilePayment',
        summary: 'Rapprochement du paiement',
        description: 'La CNPS valide définitivement la déclaration.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Paiement rapproché avec succès')]
    public function reconcilePayment($id)
    {
        $declaration = Declaration::findOrFail($id);

        $declaration->update([
            'status' => 'cnps_validated'
        ]);

        $declaration->company->user->notify(new DeclarationStatusUpdated(
         "Félicitations, votre paiement (Réf: {$declaration->reference}) a été définitivement rapproché par la CNPS.",    
        $declaration
           ));

        return response()->json([
            'message' => 'Paiement rapproché avec succès.',
            'declaration' => $declaration
        ]);
    }

    #[OA\Put(
        path: '/api/cnps/declarations/{id}/reject',
        operationId: 'cnpsRejectPayment',
        summary: 'Rejeter un paiement',
        description: 'La CNPS signale un problème (faux PDF, montant erroné, etc.).',
        tags: ['Espace CNPS'],
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
    #[OA\Response(response: 200, description: 'Déclaration rejetée')]
    public function rejectPayment(Request $request, $id)
    {
        $request->validate([
            'comment_reject' => 'required|string|min:5' 
        ]);

        $declaration = Declaration::findOrFail($id);

        $declaration->update([
            'status' => 'rejected',
            'comment_reject' => $request->comment_reject
        ]);

        // Notification d'alerte à l'entreprise
        $declaration->company->user->notify(new DeclarationStatusUpdated(
            "ATTENTION : Votre déclaration ({$declaration->reference}) a été rejetée. Motif : {$request->comment_reject}"
        ,  
        $declaration, 
        ));

        return response()->json([
            'message' => 'Déclaration rejetée.',
            'declaration' => $declaration
        ]);
    }

    #[OA\Get(
        path: '/api/cnps/statistics',
        operationId: 'cnpsStatistics',
        summary: 'Données pour les graphiques React',
        description: 'Fournit les KPIs et les statistiques des banques/modes de paiement.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Statistiques récupérées')]
    public function statistics(Request $request)
    {
        // 1. Initialisation de la requête de base
        $query = Declaration::query();

        // 2. Application du filtre de date (Si l'utilisateur a choisi une période)
        if ($request->filled(['start_date', 'end_date'])) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // ==========================================
        // CALCUL DES KPIs (Cartes du haut)
        // ==========================================
        
        // Total collecté (Uniquement ce qui est définitivement validé par la CNPS)
        $totalCollected = (clone $query)->where('status', 'cnps_validated')->sum('amount');
        
        // Nombre de rejets
        $rejectedCount = (clone $query)->where('status', 'rejected')->count();

        // Taux de rapprochement : (Nombre Rapprochés / Nombre total validé par la banque) * 100
        $reconciledCount = (clone $query)->where('status', 'cnps_validated')->count();
        $totalToReconcile = (clone $query)->whereIn('status', ['bank_validated', 'cnps_validated'])->count();
        
        $reconciliationRate = 0;
        if ($totalToReconcile > 0) {
            $reconciliationRate = round(($reconciledCount / $totalToReconcile) * 100, 1);
        }

        // ==========================================
        // GRAPHIQUE BARRES : Encaissements par Banque
        // ==========================================
        
        $bankData = (clone $query)
            ->whereNotNull('bank_id')
            ->where('status', 'cnps_validated') // On ne compte que l'argent réellement encaissé
            ->select('bank_id', DB::raw('SUM(amount) as total_amount'))
            ->groupBy('bank_id')
            ->with('bank:id,bank_name') // On joint la table banque pour récupérer le nom
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->bank ? $item->bank->bank_name : 'Inconnu',
                    'amount' => (float) $item->total_amount // Cast en float pour Recharts
                ];
            })
            // On trie par montant décroissant pour que le graphique soit beau (du + grand au + petit)
            ->sortByDesc('amount')
            ->values();

        // ==========================================
        // GRAPHIQUE CAMEMBERT : Modes de paiement
        // ==========================================
        
        $modes = (clone $query)
            ->whereNotNull('payment_mode')
            ->select('payment_mode', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_mode')
            ->get();

        $totalModes = $modes->sum('count');

        // On assigne une couleur précise à chaque mode pour coller au design
        $colorMap = [
            'virement'       => '#10B981', // Vert émeraude
            'mobile_money'   => '#1E40AF', // Bleu foncé
            'orange_money'   => '#F97316', // Orange
            'especes'        => '#64748B', // Gris
            'ordre_virement' => '#8B5CF6'  // Violet
        ];

        $paymentModeData = $modes->map(function ($item) use ($totalModes, $colorMap) {
            // On calcule le pourcentage que représente ce mode de paiement
            $percentage = $totalModes > 0 ? round(($item->count / $totalModes) * 100, 1) : 0;
            
            // On formate le nom (ex: "mobile_money" devient "Mobile money")
            $formattedName = ucfirst(str_replace('_', ' ', $item->payment_mode));

            return [
                'name' => $formattedName,
                'value' => $percentage,
                'color' => $colorMap[$item->payment_mode] ?? '#CBD5E1' // Gris clair par défaut
            ];
        })->values();

        // ==========================================
        // RÉPONSE AU FORMAT ATTENDU PAR REACT
        // ==========================================
        return response()->json([
            'kpis' => [
                'totalCollected' => (float) $totalCollected,
                'reconciliationRate' => $reconciliationRate,
                'rejectedCount' => $rejectedCount,
            ],
            'bankChartData' => $bankData,
            'paymentModeData' => $paymentModeData
        ]);
    }

    /**
     * ====================================================
     * GESTION DES PROFILS : AGENTS CNPS UNIQUEMENT
     * ====================================================
     */

    #[OA\Post(
        path: '/api/cnps/agents',
        operationId: 'storeCnpsAgent',
        summary: 'Créer un nouvel Agent CNPS (Collègue)',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password', 'matricule', 'full_name'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6),
                new OA\Property(property: 'matricule', type: 'string'),
                new OA\Property(property: 'full_name', type: 'string')
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Agent CNPS créé avec succès')]
    public function storeCnpsAgent(Request $request)
    {
       $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'matricule' => 'required|string|unique:cnps_agents,matricule', 
            'full_name' => 'required|string',
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'cnps'
        ]);

        $agent = CnpsAgent::create([
            'user_id' => $user->id,
            'matricule' => $request->matricule,
            'full_name' => $request->full_name,
        ]);

        return response()->json([
            'message' => 'Agent CNPS créé avec succès.',
            'agent' => $agent->load('user')
        ], 201);
    }

    #[OA\Get(
        path: '/api/cnps/agents',
        operationId: 'listCnpsAgents',
        summary: 'Lister tous les agents CNPS du système',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Response(response: 200, description: 'Liste des agents CNPS')]
    public function listCnpsAgents()
    {
        $agents = CnpsAgent::with('user')->orderBy('full_name', 'asc')->get();

        return response()->json($agents);
    }

    #[OA\Patch(
        path: '/api/cnps/agents/{id}/password',
        operationId: 'updateAgentPassword',
        summary: 'Modifier le mot de passe d\'un agent CNPS',
        description: 'Permet à un administrateur CNPS de réinitialiser le mot de passe d\'un collègue.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de l\'agent CNPS', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['password'],
            properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6, description: 'Le nouveau mot de passe')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Mot de passe mis à jour avec succès')]
    public function updateAgentPassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:6'
        ]);

        // On récupère l'agent et on charge son compte utilisateur associé
        $agent = CnpsAgent::with('user')->findOrFail($id);
        
        // On met à jour le mot de passe dans la table users
        $agent->user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'message' => 'Le mot de passe de l\'agent a été mis à jour avec succès.',
            'agent' => $agent
        ], 200);
    }

    #[OA\Patch(
        path: '/api/cnps/agents/{id}/toggle-admin',
        operationId: 'toggleAdminStatus',
        summary: 'Modifier les droits d\'administration',
        description: 'Inverse le statut administrateur d\'un agent CNPS.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de l\'agent', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Statut mis à jour')]
    #[OA\Response(response: 403, description: 'Opération refusée')]
    public function toggleAdminStatus($id)
    {
        $agent = CnpsAgent::findOrFail($id);

        // Sécurité : on empêche l'utilisateur connecté de modifier son propre statut
        if ($agent->user_id === Auth::user()->id) {
            return response()->json([
                'message' => 'Opération refusée : vous ne pouvez pas modifier vos propres droits d\'administration.'
            ], 403);
        }

        // On inverse le statut (si true devient false, si false devient true)
        $agent->is_admin = !$agent->is_admin;
        $agent->save();

        $roleText = $agent->is_admin ? 'Administrateur' : 'Agent simple';

        return response()->json([
            'message' => "Le statut a été mis à jour avec succès ($roleText).",
            'agent' => $agent
        ], 200);
    }

    /**
     * ====================================================
     * GESTION DES DOCUMENTS ET RAPPORTS
     * ====================================================
     */

    #[OA\Get(
        path: '/api/cnps/reports/declarations/pdf',
        operationId: 'exportPdf',
        summary: 'Exporter le rapport PDF',
        description: 'Génère un PDF basé sur les filtres fournis.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'bank_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'payment_mode', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Fichier PDF généré', content: new OA\MediaType(mediaType: 'application/pdf'))]
    public function exportPdf(Request $request)
    {
        $query = Declaration::with(['company', 'bank']);

        // --- MÊMES FILTRES QUE LA FONCTION INDEX ---
        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('bank_id'), function ($q) use ($request) {
            $q->where('bank_id', $request->bank_id);
        });

        $query->when($request->filled('payment_mode'), function ($q) use ($request) {
            $q->where('payment_mode', $request->payment_mode);
        });

        $query->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
            $q->whereBetween('created_at', [
                $request->start_date . ' 00:00:00', 
                $request->end_date . ' 23:59:59'
            ]);
        });
        // ------------------------------------------

        $declarations = $query->orderBy('created_at', 'desc')->get();

        // On calcule des totaux pour rendre le rapport professionnel
        $totalAmount = $declarations->sum('amount');
        $totalCount = $declarations->count();
        $dateGeneration = now()->format('d/m/Y à H:i');

        // On charge la vue Blade avec les données
        $pdf = Pdf::loadView('declarations_pdf', compact(
            'declarations', 
            'totalAmount', 
            'totalCount',
            'dateGeneration',
            'request'
        ))->setPaper('a4', 'landscape'); 

        return $pdf->download('Reporting_CNPS_' . date('Y-m-d') . '.pdf');
    }
#[OA\Post(
        path: '/api/cnps/declarations/{id}/receipt',
        operationId: 'uploadReceipt',
        summary: 'Associer la quittance officielle (URL)',
        description: 'Lier l\'URL de la quittance générée par la CNPS après le rapprochement.',
        tags: ['Espace CNPS'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'application/json',
            schema: new OA\Schema(
                required: ['receipt_url'],
                properties: [
                    new OA\Property(property: 'receipt_url', type: 'string', format: 'url', description: 'Le lien URL vers le document PDF de la quittance')
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Quittance associée avec succès')]
    #[OA\Response(response: 403, description: 'Paiement non rapproché')]
    public function uploadReceipt(Request $request, $id)
    {
        $declaration = Declaration::findOrFail($id);

        // SÉCURITÉ : On ne peut associer une quittance que si le paiement a été rapproché
        if ($declaration->status !== 'cnps_validated') {
            return response()->json([
                'message' => 'Le paiement doit d\'abord être rapproché avant d\'y associer une quittance.'
            ], 403);
        }

        // VALIDATION : L'URL est obligatoire et doit avoir un format valide
        $request->validate([
            'receipt_url' => 'required|url|max:2048'
        ], [
            'receipt_url.required' => 'Veuillez renseigner le lien de la quittance.',
            'receipt_url.url' => 'Le lien fourni n\'est pas une URL valide.'
        ]);

        // Enregistrement de l'URL dans la base de données
        // (On conserve la colonne 'receipt_path' pour stocker cette URL)
        $declaration->receipt_path = $request->input('receipt_url');
        $declaration->save();

        // ========================================================
        // NOTIFICATION À L'ENTREPRISE
        // ========================================================
        $declaration->load('company.user');
        if ($declaration->company && $declaration->company->user) {
            $declaration->company->user->notify(new \App\Notifications\DeclarationStatusUpdated(
                "Le lien de votre quittance officielle est maintenant disponible.", 
                $declaration
            ));
        }

        return response()->json([
            'message' => 'Lien de la quittance enregistré et transmis à l\'entreprise avec succès.',
            'declaration' => $declaration
        ]);
    }
}