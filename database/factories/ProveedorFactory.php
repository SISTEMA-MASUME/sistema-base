<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProveedorFactory extends Factory
{
    public function definition(): array
    {
        return ['nombre' => strtoupper($this->faker->unique()->company())];
    }
}
