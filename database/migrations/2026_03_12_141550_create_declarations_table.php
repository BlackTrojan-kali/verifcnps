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
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId("company_id")->constrained()->onDelete("cascade");
            $table->foreignId("bank_id")->constrained()->onDelete("cascade");
            $table->string("reference")->unique();
            $table->string("mobile_reference")->nullable();
            $table->date("period");
            $table->decimal("amount",15,2);
            $table->enum("paiment_mode",["virement","espece",'ordre_virement',"mobile_money","orange_money"]);
            $table->enum("status",["submited","bank_validated","cnps_validated","rejected"]);   
            $table->timestamps();
            $table->index("reference");
            $table->index("status");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
