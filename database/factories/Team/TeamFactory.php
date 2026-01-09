<?php

namespace Database\Factories\Team;

use Illuminate\Database\Eloquent\Factories\Factory;
use Newms87\Danx\Models\Team\Team;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->unique()->company . '-' . uniqid(),
            'namespace' => '',
            'logo'      => null,
        ];
    }
}
