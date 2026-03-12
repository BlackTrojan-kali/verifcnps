<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CnpsAgents extends Model
{
    //
    protected $fillable = [
        "user_id",
        "matricule",
        "fullname",
        "departement"
    ];
    
    public function user(){
        return $this->belongsTo(User::class,"user_id");
    }
}
