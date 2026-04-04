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
            
            // --- Relations ---
            $table->foreignId("company_id")->constrained()->onDelete("restrict");
            $table->foreignId("bank_id")->nullable()->constrained()->onDelete("restrict");
            
            // --- Références de transaction ---
            $table->string("reference")->unique()->nullable(); // Référence interne
            $table->string("payment_id")->unique()->nullable(); // <-- Pour "id_paiement" ("TEST111241")
            $table->string("order_reference")->unique()->nullable();
            $table->string("bank_transaction_ref")->nullable(); // <-- Pour "refTransactionBank"
            $table->string("mobile_reference")->nullable();
            
            // --- Informations de paiement ---
            $table->date("period"); // Période de la déclaration (mois/trimestre)
            $table->date("payment_date")->nullable(); // <-- Pour "date_paiement" ("24-04-2018")
            $table->decimal("amount", 15, 2); // <-- Pour "montant" ("200000")
            
            // --- Mode et Origine ---
            $table->string("payment_mode_code")->nullable(); // <-- Pour "mode_paiement" ("14")
            $table->enum("payment_mode", ['virement', 'especes', 'ordre_virement', 'mobile_money', 'orange_money'])->nullable();
            $table->string("payment_origin")->nullable(); // <-- Pour "origine" ("OMCAM")
            
            // --- Méta-données API CNPS ---
            $table->string("employer_number")->nullable(); // <-- Pour "matricule" ("384-0000036-000-X")
            $table->string("insurance_type")->nullable(); // <-- Pour "type_assu" ("EM")
            $table->string("location_code")->nullable(); // <-- Pour "localisation" ("124-125-141")
            
            // --- Informations du payeur et de la banque ---
            $table->string("payer_phone")->nullable(); // <-- Pour "telephone" ("670322140")
            $table->string("bank_name")->nullable(); // <-- Pour "nomBank" ("AFRILAND FIRST BANK")
            $table->string("account_number")->nullable();
            
            // --- Fichiers ---
            $table->string("proof_path")->nullable();
            $table->string("receipt_path")->nullable();
            
            // --- Statut et validation ---
            // "initiated" a été ajouté dans les options de l'enum
            $table->enum("status", ["initiated", "submited", "bank_validated", "cnps_validated", "rejected"])->default("initiated"); 
            $table->string("comment_reject")->nullable();  
            
            $table->timestamps();
            
            // --- Index pour les performances ---
            $table->index("reference"); 
            $table->index("payment_id");
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