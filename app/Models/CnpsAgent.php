<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CnpsAgent extends Model
{
    use HasFactory;
    //
    protected $fillable = [
        "user_id",
        "matricule",
        "full_name",
        "department"
    ];
    
    public function user(){
        return $this->belongsTo(User::class,"user_id");
    }
}
