<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supervisor extends Model
{
    use HasFactory;
    //
    protected  $fillable = [
        "supervisor_name",
        "user_id",
        "is_admin"
    ];

    public function  user(){
        return $this->belongsTo(User::class,"user_id");
    }
}
