<?php

namespace App\Models;

use Dom\Document;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Declaration extends Model
{
    
    use HasFactory;
    //
    protected $fillable=[
        "company_id",
        "bank_id",
        "reference",
        "mobile_reference",
        "period",
        "amount",
        "payment_mode", 
        "proof_path",
        "status", 
        "comment_reject", 
        "receipt_path"
    ];

    public function company(){
        return $this->belongsTo(Company::class,"company_id");
    }
    public function bank(){
        return $this->belongsTo(Bank::class,"bank_id");
    }
    public function documents(){
        return $this->hasMany(Document::class);
    }

}
