<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BankController extends Controller
{
    /**
     * 1. Lister les déclarations affectées à cette banque
     */
    public function index(Request $request)
    {
        $bank = Auth::user()->bank;
        
        // On charge la relation "company" pour afficher le nom de l'entreprise côté front
        $query = Declaration::with('company')->where("bank_id", $bank->id);

        // --- Filtres ---
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

        // Filtre par plage de dates
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // On exclut le statut "initiated" car la banque ne doit voir que ce qui lui a été soumis ("submited" et plus)
        //$query->where('status', '!=', 'initiated');
        $query->whereNotIn("payment_mode",['mobile_money', "orange_money"]);
        $query->whereNotIn('status', ['initiated', 'cnps_validated']);
        $declarations = $query->orderBy('created_at', 'desc')->paginate(25);

        return response()->json($declarations);
    }

    /**
     * 2. Afficher les détails d'une déclaration spécifique
     */
    public function show($id)
    {
        $bank = Auth::user()->bank;
        $declaration = Declaration::with('company')->where("bank_id", $bank->id)->findOrFail($id);

        return response()->json($declaration);
    }

    /**
     * 3. Valider le paiement (Confirmation de réception des fonds)
     */
    public function validatePayment(Request $request, $id)
    {
        $bank = Auth::user()->bank;
        $declaration = Declaration::where('bank_id', $bank->id)->findOrFail($id);

        // Sécurité : On ne valide que ce qui est "soumis"
        if ($declaration->status !== 'submited') {
            return response()->json(['message' => 'Cette déclaration a déjà été traitée.'], 403);
        }

        // --- Logique de validation dynamique ---
        // La première référence est toujours obligatoire
        $rules = [
            'reference' => 'required|string|unique:declarations,reference,' . $declaration->id,
        ];

        // Règle métier : Si c'est un Ordre de Virement, on exige la 2ème référence
        if ($declaration->payment_mode === 'ordre_virement') {
            $rules['order_reference'] = 'required|string|unique:declarations,order_reference,' . $declaration->id;
        }

        $request->validate($rules);

        // --- Mise à jour ---
        $declaration->reference = $request->reference;
        
        if ($declaration->payment_mode === 'ordre_virement') {
            $declaration->order_reference = $request->order_reference;
        }

        $declaration->status = 'bank_validated';
        $declaration->save();

        return response()->json([
            'message' => 'Paiement validé et transmis à la CNPS avec succès.',
            'declaration' => $declaration
        ]);
    }

    /**
     * 4. Rejeter le paiement (Fonds introuvables, chèque sans provision, etc.)
     */
    public function rejectPayment(Request $request, $id)
    {
        $bank = Auth::user()->bank;
        $declaration = Declaration::where('bank_id', $bank->id)->findOrFail($id);

        if ($declaration->status !== 'submited') {
            return response()->json(['message' => 'Cette déclaration a déjà été traitée.'], 403);
        }

        // Un motif de rejet est obligatoire pour informer l'entreprise
        $request->validate([
            'comment_reject' => 'required|string|min:5'
        ]);

        $declaration->status = 'rejected';
        $declaration->comment_reject = $request->comment_reject;
        $declaration->save();

        return response()->json([
            'message' => 'Le paiement a été rejeté.',
            'declaration' => $declaration
        ]);
    }
}