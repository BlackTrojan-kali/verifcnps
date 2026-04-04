<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        "user_id",
        "numero_employeur", // Remplacement de "niu"
        "raison_sociale",
        "telephone",
        "address",
        "is_verified",      // Ajout du nouveau champ
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_verified' => 'boolean', // S'assure que la valeur est toujours castée en booléen (true/false)
    ];

    public function user()
    {
        return $this->belongsTo(User::class, "user_id");
    }

    public function declarations()
    {
        return $this->hasMany(Declaration::class);
    }
}