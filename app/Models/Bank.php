<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bank extends Model
{
    //
    protected $fillable= [
        "bank_code",
        "user_id",
        "bank_name",
        "address",
    ];

    public function user(){
        return $this->belongsTo(User::class,"user_id");
    }
    public function declarations(){
        return $this->hasMany(Declaration::class);
    }
}
