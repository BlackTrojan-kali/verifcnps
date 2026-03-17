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

class CompanyController extends Controller
{
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
    
    /**
     * 2. Initier une nouvelle déclaration depuis l'espace Entreprise
     */
    public function InitiateDeclaration(Request $request)
    {
        $request->validate([
            "bank_id" => "nullable|integer|exists:banks,id", 
            "reference" => "required|string",
            "mobile_reference" => "nullable|string",
            "period" => "required|date",
            "amount" => "required|numeric",
            "payment_mode" => "required|string|in:virement,especes,ordre_virement,mobile_money,orange_money",
            "proof_pdf" => "nullable|file|mimes:pdf|max:5096",
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
            "reference" => $request->reference,
            "mobile_reference" => $request->mobile_reference,
            "period" => $date,
            "amount" => $request->amount,
            "payment_mode" => $request->payment_mode, 
            "proof_path" => $path,
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
}
