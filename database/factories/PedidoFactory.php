<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use App\Support\Folios;
use Illuminate\Database\Eloquent\Factories\Factory;

class PedidoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'folio' => fn () => Folios::siguiente('pedido'),
            'cliente_id' => Cliente::factory(),
            'responsable_id' => User::factory(),
            'numero_oc_cliente' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'fecha_oc' => now()->toDateString(),
        ];
    }
}
