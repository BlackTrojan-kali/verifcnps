<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bank extends Model
{
    use HasFactory;
    //
    protected $fillable= [
        "bank_code",
        "user_id",
        "bank_name",
        "address",
        "is_admin", // <-- À rajouter ici
    ];

    public function user(){
        return $this->belongsTo(User::class,"user_id");
    }
    public function declarations(){
        return $this->hasMany(Declaration::class);
    }
}
