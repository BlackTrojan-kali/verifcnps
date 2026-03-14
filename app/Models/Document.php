<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    
    use HasFactory;
    //
    protected $fillable=[
        "declaration_id",
        "document_type",
        "file_path",
        "original_name"
    ];

    public function user(){
        $this->belongsTo(Declaration::class,"declaration_id");
    }
    
}
