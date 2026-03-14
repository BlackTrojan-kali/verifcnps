<?php

namespace App\Http\Controllers;

use App\Models\Declaration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BankController extends Controller
{
    //

    public function index(Request $request){
        $bank = Auth::user()->bank;
        $query = Declaration::where("bank_id",$bank->id);
        $query->when($request->filled("reference"),function($q) use ($request){
            $q->where("reference",$request->reference);
        });
        $query->when($request->filled('mobile_reference'),function($q) use($request){
            $q->where("mobile_reference",$request->mobile_reference);
        });
        $query->when($request->filled("period"),function($q)use($request){
        
        });
        
    }
}
