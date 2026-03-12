<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Declaration extends Model
{
    //
    protected $fillable=[
        "company_id",
        "bank_id",
        "reference",
        "period",
        "amount",
        "payment_mode",
        "status",
        
    ];

    public function company(){
        return $this->belongsTo(Company::class,"company_id");
    }
    public function bank(){
        return $this->belongsTo(Bank::class,"bank_id");
    }
    public function document(){
        
    }

}
