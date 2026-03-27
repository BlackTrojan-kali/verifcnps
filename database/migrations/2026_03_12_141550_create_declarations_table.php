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
            
            // 1. Protection des données financières (restrict au lieu de cascade)
            $table->foreignId("company_id")->constrained()->onDelete("restrict");
            // 2. Rendu nullable car un paiement MoMo ou une déclaration non finalisée n'a pas de banque
            $table->foreignId("bank_id")->nullable()->constrained()->onDelete("restrict");
            
            $table->string("reference")->unique()->nullable();
            $table->string("order_reference")->unique()->nullable();
            $table->string("mobile_reference")->nullable();
            $table->date("period");
            $table->decimal("amount", 15, 2);
             
            // 3. Rendu nullable car l'API CNPS l'ignore au moment de l'initialisation
            $table->enum("payment_mode", ['virement', 'especes', 'ordre_virement', 'mobile_money', "orange_money"])->nullable();
            
            // 4. L'AJOUT CRITIQUE : Le chemin du fichier PDF uploadé par l'entreprise/banque (Preuve)
            $table->string("proof_path")->nullable();

            // ==========================================
            // NOUVEAU : La quittance délivrée par la CNPS
            // ==========================================
            $table->string("receipt_path")->nullable();
            $table->string("account_number")->nullable();
            // 5. Ajout de l'état initial ('initiated') et de la valeur par défaut
            $table->enum("status", ["submited", "bank_validated", "cnps_validated", "rejected"])->default("initiated"); 
            
            $table->string("comment_reject")->nullable();  
            $table->timestamps();
            
            $table->index("reference")->nullable();
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