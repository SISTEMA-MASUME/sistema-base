<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'nombre' => $this->faker->name(),
            'puesto' => $this->faker->jobTitle(),
            'correo' => $this->faker->safeEmail(),
            'telefono' => $this->faker->numerify('33 #### ####'),
        ];
    }
}
