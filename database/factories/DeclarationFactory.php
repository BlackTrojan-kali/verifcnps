<?php

namespace Database\Factories;

use App\Models\Declaration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Declaration>
 */
class DeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Les IDs d'entreprise et de banque seront injectés par le Seeder
            
            // --- Références ---
            "reference" => 'REF-' . strtoupper($this->faker->unique()->bothify('?????-#####')),
            "payment_id" => 'TEST' . $this->faker->unique()->randomNumber(6, true),
            "order_reference" => 'ORD-' . strtoupper($this->faker->unique()->bothify('?????-#####')),
            "bank_transaction_ref" => $this->faker->numerify('#############'),
            "mobile_reference" => strtoupper($this->faker->bothify('TXN-#####')),
            
            // --- Dates et Montants ---
            "period" => $this->faker->randomElement(['2026-01-01', '2026-02-01', '2026-03-01']),
            "payment_date" => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            "amount" => $this->faker->randomElement([50000, 150000, 1250000, 3000000, 5000000]),
            
            // --- Moyens de paiement ---
            "payment_mode_code" => $this->faker->randomElement(['14', '15', '01', '02']),
            "payment_mode" => $this->faker->randomElement(['virement', 'especes', 'ordre_virement', 'mobile_money', "orange_money"]),
            "payment_origin" => $this->faker->randomElement(['OMCAM', 'MOMO_API', 'AGENCE_BANQUE']),
            
            // --- Informations CNPS ---
            "employer_number" => $this->faker->numerify('###-#######-###-X'),
            "insurance_type" => 'EM',
            "location_code" => $this->faker->numerify('###-###-###'),
            
            // --- Infos Payeur / Banque ---
            "payer_phone" => '6' . $this->faker->randomNumber(8, true),
            "bank_name" => $this->faker->randomElement(['AFRILAND FIRST BANK', 'UBA', 'ECOBANK', 'SGC']),
            "account_number" => $this->faker->numerify('CM21 ##### ##### ########### ##'),
            
            // --- Fichiers ---
            "proof_path" => 'proofs/' . $this->faker->uuid() . '.pdf',
            // Génération d'une URL pointant vers votre serveur FTP externe
            "receipt_path" => 'ftp://nom-utilisateur:mot-de-passe@ftp.votre-serveur-externe.com/quittances/' . $this->faker->uuid() . '.pdf',
            
            // --- Statut ---
            // Ajout du statut 'initiated'
            "status" => $this->faker->randomElement(["initiated", "submited", "bank_validated", "cnps_validated", "rejected"]),
            
            // Logique conditionnelle : ajoute un commentaire uniquement si le statut est 'rejected'
            'comment_reject' => fn (array $attributes) => $attributes['status'] === 'rejected' ? $this->faker->sentence(6) : null,
        ];
    }
}