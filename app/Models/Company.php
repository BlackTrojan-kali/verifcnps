<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    
    use HasFactory;
    //
    protected $fillable=[
        "user_id",
        "niu",
        "raison_sociale",
        "telephone",
        "address",
    ];
    public function user(){
        return $this->belongsTo(User::class,"user_id");
    }
    public function declarations(){
        return $this->hasMany(Declaration::class);
    }
}
