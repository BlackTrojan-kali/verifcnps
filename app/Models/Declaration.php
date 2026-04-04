<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Declaration extends Model
{
    use HasFactory;

    protected $fillable = [
        "company_id",
        "bank_id",
        
        // Références
        "reference",
        "payment_id",
        "order_reference",
        "bank_transaction_ref",
        "mobile_reference",
        
        // Informations de paiement
        "period",
        "payment_date",
        "amount",
        
        // Mode et Origine
        "payment_mode_code",
        "payment_mode",
        "payment_origin",
        
        // Méta-données API CNPS
        "employer_number",
        "insurance_type",
        "location_code",
        
        // Informations du payeur et banque
        "payer_phone",
        "bank_name",
        "account_number",
        
        // Fichiers
        "proof_path",
        "receipt_path",
        
        // Statuts
        "status", 
        "comment_reject", 
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'period' => 'date',
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, "company_id");
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class, "bank_id");
    }

    public function documents()
    {
        // Utilise implicitement App\Models\Document puisqu'on est dans le même namespace
        return $this->hasMany(Document::class);
    }
}