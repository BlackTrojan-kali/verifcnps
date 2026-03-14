<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'document_type' => 'avis_cotisation',
            // Nous simulons un chemin de fichier factice pour ne pas saturer votre disque dur
            "file_path" => 'documents/dummy_avis_' . $this->faker->randomNumber(4) . '.pdf',
            "original_name" => 'Avis_Cotisation_' . $this->faker->monthName() . '.pdf',
        ];
    }
}
