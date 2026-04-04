<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained()->onDelete("cascade");
            $table->string("numero_employeur"); // Remplacé "niu" par "numero_employeur"
            $table->string("raison_sociale");
            $table->string("telephone")->nullable();
            $table->string("address")->nullable();
            $table->boolean("is_verified")->default(false); // Ajout du champ is_verified
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};