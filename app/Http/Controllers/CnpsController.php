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

class CnpsController extends Controller
{
    //
 
    /**
     * 1. SUPER TABLEAU DE BORD : Voir et filtrer TOUTES les déclarations
     */
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

        $declarations = $query->orderBy('updated_at', 'desc')->paginate(50);

        return response()->json($declarations);
    }

    /**
     * 2. RAPPROCHEMENT : La CNPS valide définitivement
     */
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

    /**
     * 3. REJET : La CNPS signale un problème (faux PDF, montant erroné, etc.)
     */
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

    /**
     * 4. REPORTING (Bonus) : Fournir les données pour les graphiques React
     */
  public function statistics(Request $request)
    {
        // 1. Initialisation de la requête de base
        $query = Declaration::query();

        // 2. Application du filtre de date (Si l'utilisateur a choisi une période)
        if ($request->filled(['start_date', 'end_date'])) {
            $start = Carbon ::parse($request->start_date)->startOfDay();
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
     * 5. Créer un nouveau compte Banque
     */
    public function storeBank(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'bank_code' => 'required|string|unique:banks,bank_code',
            'bank_name' => 'required|string',
        ]);

        // 1. Création de l'utilisateur de connexion
        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password), 
            'role' => 'bank'
        ]);

        // 2. Création du profil métier lié
        $bank = Bank::create([
            'user_id' => $user->id,
            'bank_code' => $request->bank_code,
            'bank_name' => $request->bank_name,
        ]);

        return response()->json([
            'message' => 'Compte bancaire créé avec succès.',
            'bank' => $bank->load('user') 
        ], 201);
    }

    public function listBanks()
    {
        $banks = Bank::with('user')->orderBy('bank_name', 'asc')->get();

        return response()->json($banks);
    }

    /**
     * ====================================================
     * GESTION DES PROFILS : AGENTS CNPS
     * ====================================================
     */

    /**
     * 7. Créer un nouvel Agent CNPS (Collègue)
     */
    public function storeCnpsAgent(Request $request)
    {
       $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            // --- LA CORRECTION EST ICI : cnps_agents au lieu de cnps ---
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

    /**
     * 8. Lister tous les agents CNPS du système
     */
    public function listCnpsAgents()
    {
        $agents = CnpsAgent::with('user')->orderBy('full_name', 'asc')->get();

        return response()->json($agents);
    }

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

    /**
     * Uploader la quittance officielle (Après le rapprochement)
     */
    public function uploadReceipt(Request $request, $id)
    {
        $declaration = Declaration::findOrFail($id);

        // SÉCURITÉ : On ne peut uploader une quittance que si le paiement a été rapproché
        if ($declaration->status !== 'cnps_validated') {
            return response()->json([
                'message' => 'Le paiement doit d\'abord être rapproché avant d\'y associer une quittance.'
            ], 403);
        }

        // VALIDATION : Le fichier PDF est obligatoire
        $request->validate([
            'receipt_pdf' => 'required|file|mimes:pdf|max:5096'
        ], [
            'receipt_pdf.required' => 'Veuillez joindre la quittance au format PDF.',
            'receipt_pdf.mimes' => 'Le fichier doit être un PDF.'
        ]);

        // Suppression de l'ancienne quittance si elle existait déjà (en cas de correction)
        if ($request->hasFile('receipt_pdf')) {
            if ($declaration->receipt_path) {
                Storage::disk('public')->delete($declaration->receipt_path);
            }
            // Enregistrement dans un dossier spécifique "receipts"
            $declaration->receipt_path = $request->file('receipt_pdf')->store('receipts', 'public');
            $declaration->save();
        }

        // ========================================================
        // NOTIFICATION À L'ENTREPRISE
        // ========================================================
        $declaration->load('company.user');
        if ($declaration->company && $declaration->company->user) {
            $declaration->company->user->notify(new \App\Notifications\DeclarationStatusUpdated(
                "Votre quittance officielle est maintenant disponible au téléchargement.", 
                $declaration
            ));
        }

        return response()->json([
            'message' => 'Quittance uploadée et transmise à l\'entreprise avec succès.',
            'declaration' => $declaration
        ]);
    }
    /**
     * Modifier les droits d'administration d'un agent CNPS
     */
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
}
