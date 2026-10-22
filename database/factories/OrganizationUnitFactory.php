<?php

namespace Database\Factories;

use App\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationUnit>
 */
class OrganizationUnitFactory extends Factory
{
    protected $model = OrganizationUnit::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
        ];
    }
}
