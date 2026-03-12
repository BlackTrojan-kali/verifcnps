<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    //
    protected $fillable=[
        "user_id",
        "nui",
        "raison_social",
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
