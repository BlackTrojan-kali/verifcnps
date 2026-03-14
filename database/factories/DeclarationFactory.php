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
            //
            // Les IDs d'entreprise et de banque seront injectés par le Seeder
            "reference" => 'REF-' . strtoupper($this->faker->unique()->bothify('?????-#####')),
            "mobile_reference" => strtoupper($this->faker->bothify('TXN-#####')),
           "period" => $this->faker->randomElement(['2026-01-01', '2026-02-01', '2026-03-01']),
            "amount" => $this->faker->randomElement([50000, 150000, 1250000, 3000000, 5000000]),
            "payment_mode" => $this->faker->randomElement(['virement', 'especes', 'ordre_virement', 'mobile_money',"orange_money"]),
            "status" => $this->faker->randomElement(["submited","bank_validated","cnps_validated","rejected"]),
            'comment_reject' => null,
        ];
    }
}
