<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CnpsAgent;
use App\Models\Declaration;
use App\Models\User;
use App\Notifications\DeclarationStatusUpdated;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        // Total des montants validés par la CNPS
        $totalEncaisse = Declaration::where('status', 'cnps_validated')->sum('amount');
        
        // Nombre de déclarations en attente
        $enAttente = Declaration::whereIn('status', ['submited', 'bank_validated'])->count();

        // Répartition des paiements par mode (pour un graphique en camembert)
        $repartitionModes = Declaration::selectRaw('payment_mode, COUNT(*) as count, SUM(amount) as total')
            ->whereNotNull('payment_mode')
            ->groupBy('payment_mode')
            ->get();

        return response()->json([
            'total_encaisse' => $totalEncaisse,
            'declarations_en_attente' => $enAttente,
            'repartition_modes' => $repartitionModes,
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
            'bank_code' => 'required|string|unique:banks,code_banque',
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
            'matricule' => 'required|string|unique:cnps,matricule', 
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
        $agents = CnpsAgent::with('user')->orderBy('fullname', 'asc')->get();

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

}
