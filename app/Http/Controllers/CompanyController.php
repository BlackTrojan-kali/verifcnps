<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyController extends Controller
{
    //
    public function index(Request $request){
        $company = Auth::user()->company;

        $query = Declaration::where("company_id",$company->id);

        $query->when($request->filled("bank_id"),function($q) use($request){
            $q->where("bank_id",$request->bank_id);
        });
        $query->when($request->filled("reference"),function($q) use($request){

            $q->where("reference",$request->reference);
        });
        $query->when($request->filled("mobile_reference"),function($q) use($request){
            $q->where("mobile_reference",$request->mobile_reference);
        });
        $query->when($request->filled("period"),function($q)use($request){
            $q->where("period",$request->period);
        });
        $start_date = Carbon::parse($request->filled("start_date"));
        $end_date = Carbon::parse($request->filled("end_date"));
        $start_date = $start_date->startOfDay();
        $end_date = $end_date->endOfDay();
        $query->when( $start_date && $end_date,function($q) use($start_date,$end_date){
            $q->whereBetween("created_at",[
              $start_date,
              $end_date
                
            ]);
        });
        $declarations = $query->orderBy("created_at","desc")->paginate(25);

        return response()->json([
            "declarations"=>$declarations
        ]);

    }
    
    public function InitiateDeclaration(Request $request){
        $request->validate([
            "bank_id"=>"required",
            "reference"=>"string | required",
            "mobile_reference"=>"string | nullable",
            "period"=>"required| date",
            "proof_pdf"=>"nullable | file:pdf | max:5096",
            "amount"=>"numeric ",
            "payment_mode"=> "string | required",
            "status"=>"string |required",
        ]);

        $company=  Auth::user()->company();
        $date  = Carbon::today();
        $path = $request->file("proof_pdf")->store("proofs","public");
        Declaration::create([
            "company_id" =>$request->company_id,
            "bank_id"=>$request->bank_id,
            "reference"=>$request->reference,
            "mobile_reference"=>$request->mobile_reference,
            "period"=>$date,
            "amount"=>$request->amount,
            "payment_mode"=>$request->payment_mode, 
            "proof_path"=>$path,
            "status"=>"initiated",
        ]);

        return response()->json([
            "message"=>"paiement initié"
        ]);
    }
    public function EditDeclaration(Request $request){
        $request->validate([
         "bank_id"=>$request->bank_id,
        "reference"=>$request->reference,
        "mobile_reference"=>$request->mobile_reference,
        "amount"=>$request->amount,
        "payment_mode"=>$request->payment_mode, 
        "proof_path"=>"nullable | file:pdf| max:5096",
        ]);
    }
    public function changeDeclarationStatus(Request $request,$id){
        $request->validate([
            "status"=>"required | string"
        ]);
        $declaration  = Declaration::findOrFail($id);
        $declaration->status  = $request->status();
        $declaration->save();
        return response()->json([
            "message"=>"status mis à jour avec succès"
        ]);
    }
    
    
}
