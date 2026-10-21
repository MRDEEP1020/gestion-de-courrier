<?php

namespace Database\Factories;

use App\Models\RegleClassement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegleClassement>
 */
class RegleClassementFactory extends Factory
{
    protected $model = RegleClassement::class;

    public function definition(): array
    {
        return [
            'nom' => 'Réclamations',
            'mots_cles' => ['réclamation', 'sinistre'],
            'champs' => null,
            'type_document_propose' => 'Réclamation',
            'service_propose_id' => null,
            'tags' => ['réclamation'],
            'priorite' => 100,
            'actif' => true,
        ];
    }
}
