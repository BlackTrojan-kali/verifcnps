<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use App\Models\Bank;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class SupervisorController extends Controller
{
    #[OA\Get(
        path: '/api/supervisor/dashboard-stats',
        operationId: 'supervisorDashboardStats',
        summary: 'Statistiques globales pour le Dashboard Superviseur',
        description: 'Retourne les KPIs globaux, les volumes par banque et les tendances du système entier.',
        tags: ['Espace Superviseur'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'bank_id', in: 'query', required: false, description: 'Filtrer les stats par banque', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Statistiques récupérées avec succès')]
    public function dashboardStats(Request $request)
    {
        $query = Declaration::query();

        if ($request->filled('bank_id')) {
            $query->where('bank_id', $request->bank_id);
        }

        if ($request->filled(['start_date', 'end_date'])) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // KPIs
        $totalVolume = (clone $query)->whereIn('status', ['bank_validated', 'cnps_validated'])->sum('amount');
        $totalReconciled = (clone $query)->where('status', 'cnps_validated')->sum('amount');
        $totalDeclarations = (clone $query)->count();
        $pendingCount = (clone $query)->where('status', 'submited')->count();
        $rejectedCount = (clone $query)->where('status', 'rejected')->count();

        // Performances par Banque
        $bankPerformanceData = (clone $query)
            ->whereNotNull('bank_id')
            ->whereIn('status', ['bank_validated', 'cnps_validated'])
            ->select('bank_id', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(id) as total_transactions'))
            ->groupBy('bank_id')
            ->with('bank:id,bank_name')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->bank ? $item->bank->bank_name : 'Inconnu',
                    'amount' => (float) $item->total_amount,
                    'transactions' => (int) $item->total_transactions
                ];
            })
            ->sortByDesc('amount')
            ->values();

        // Modes de paiement
        $modes = (clone $query)
            ->select('payment_mode', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_mode')
            ->get();

        $colorMap = [
            'virement'       => '#10B981',
            'mobile_money'   => '#1E40AF',
            'orange_money'   => '#F97316',
            'especes'        => '#F59E0B',
            'ordre_virement' => '#8B5CF6'
        ];

        $paymentModeData = $modes->map(function ($item) use ($colorMap) {
            $formattedName = ucfirst(str_replace('_', ' ', $item->payment_mode));
            return [
                'name' => $formattedName,
                'value' => (int) $item->count,
                'color' => $colorMap[$item->payment_mode] ?? '#94A3B8'
            ];
        })->values();

        // Tendance sur 30 jours
        $trendData = (clone $query)
            ->whereIn('status', ['bank_validated', 'cnps_validated'])
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->locale('fr')->translatedFormat('d M'),
                    'amount' => (float) $item->total
                ];
            });

        return response()->json([
            'kpis' => [
                'totalVolume' => (float) $totalVolume,
                'totalReconciled' => (float) $totalReconciled,
                'totalDeclarations' => $totalDeclarations,
                'pendingCount' => $pendingCount,
                'rejectedCount' => $rejectedCount,
            ],
            'bankPerformanceData' => $bankPerformanceData,
            'paymentModeData' => $paymentModeData,
            'trendData' => $trendData
        ]);
    }

    #[OA\Get(
        path: '/api/supervisor/declarations',
        operationId: 'supervisorListDeclarations',
        summary: 'Voir et filtrer TOUTES les déclarations du système',
        description: 'Tableau de bord de supervision avec recherche et filtres croisés.',
        tags: ['Espace Superviseur'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filtrer par statut', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'bank_id', in: 'query', required: false, description: 'Filtrer par banque', schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'payment_mode', in: 'query', required: false, description: 'Filtrer par mode de paiement', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'search', in: 'query', required: false, description: 'Recherche par référence ou NIU', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, description: 'Date de début (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, description: 'Date de fin (Y-m-d)', schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Liste des déclarations')]
    public function index(Request $request)
    {
        $query = Declaration::with(['company', 'bank']);

        $query->when($request->filled('status'), function ($q) use ($request) {
            $q->where('status', $request->status);
        });

        $query->when($request->filled('bank_id'), function ($q) use ($request) {
            $q->where('bank_id', $request->bank_id);
        });

        $query->when($request->filled('payment_mode'), function ($q) use ($request) {
            $q->where('payment_mode', $request->payment_mode);
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $searchTerm = '%' . $request->search . '%';
            $q->where(function($subQuery) use ($searchTerm) {
                $subQuery->where('reference', 'LIKE', $searchTerm)
                         ->orWhere('mobile_reference', 'LIKE', $searchTerm)
                         ->orWhereHas('company', function($companyQuery) use ($searchTerm) {
                             $companyQuery->where('niu', 'LIKE', $searchTerm)
                                          ->orWhere('raison_sociale', 'LIKE', $searchTerm);
                         });
            });
        });

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $declarations = $query->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json($declarations);
    }

    #[OA\Get(
        path: '/api/supervisor/declarations/{id}',
        operationId: 'supervisorShowDeclaration',
        summary: 'Afficher les détails d\'une déclaration',
        tags: ['Espace Superviseur'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID de la déclaration', schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Détails de la déclaration')]
    #[OA\Response(response: 404, description: 'Déclaration non trouvée')]
    public function show($id)
    {
        $declaration = Declaration::with(['company', 'bank', 'documents'])->findOrFail($id);

        return response()->json([
            'declaration' => $declaration
        ]);
    }

    #[OA\Post(
        path: '/api/supervisor/banks/agents',
        operationId: 'storeBankAgentGlobal',
        summary: 'Créer un compte pour une banque spécifique',
        description: 'Crée un agent (Bank) et son compte de connexion associé.',
        tags: ['Espace Superviseur'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password', 'bank_code', 'bank_name'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', description: 'Email de connexion de l\'agent'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6),
                new OA\Property(property: 'bank_code', type: 'string', description: 'Code de la banque (ex: UBA-001)'),
                new OA\Property(property: 'bank_name', type: 'string', description: 'Nom de la banque'),
                new OA\Property(property: 'address', type: 'string', description: 'Adresse de l\'agence'),
                new OA\Property(property: 'is_admin', type: 'boolean', description: 'True si c\'est un chef d\'agence')
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Agent bancaire créé avec succès')]
    public function storeBankAgent(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'bank_code' => 'required|string|unique:banks,bank_code', 
            'bank_name' => 'required|string',
            'address' => 'nullable|string',
            'is_admin' => 'boolean'
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'bank'
        ]);

        $bankAgent = Bank::create([
            'user_id' => $user->id,
            'bank_code' => $request->bank_code,
            'bank_name' => $request->bank_name,
            'address' => $request->address,
            'is_admin' => $request->is_admin ?? false,
        ]);

        return response()->json([
            'message' => 'L\'agent de la banque a été créé avec succès.',
            'agent' => $bankAgent->load('user')
        ], 201);
    }

    #[OA\Patch(
        path: '/api/supervisor/banks/agents/{id}/password',
        operationId: 'updateBankAgentPasswordGlobal',
        summary: 'Modifier le mot de passe d\'un agent de banque',
        description: 'Permet au superviseur de réinitialiser le mot de passe d\'un agent précis.',
        tags: ['Espace Superviseur'],
        security: [['bearerAuth' => []]]
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID du modèle Bank', schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['password'],
            properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 6, description: 'Nouveau mot de passe')
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Mot de passe mis à jour avec succès')]
    public function updateBankAgentPassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:6'
        ]);

        $bankAgent = Bank::with('user')->findOrFail($id);

        $bankAgent->user->update([
            'password' => Hash::make($request->password)
        ]);

        return response()->json([
            'message' => 'Le mot de passe de l\'agent ('.$bankAgent->bank_name.') a été mis à jour avec succès.',
            'agent' => $bankAgent
        ], 200);
    }
}