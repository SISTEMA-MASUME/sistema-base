<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ClienteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre' => strtoupper($this->faker->unique()->company()),
            'razon_social' => $this->faker->company().' S.A. de C.V.',
            'direccion' => $this->faker->address(),
            'correo' => $this->faker->safeEmail(),
            'dias_credito' => 30,
        ];
    }
}
