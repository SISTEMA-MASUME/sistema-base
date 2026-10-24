<?php

namespace Database\Factories;

use App\Models\Pedido;
use Illuminate\Database\Eloquent\Factories\Factory;

class PedidoPartidaFactory extends Factory
{
    public function definition(): array
    {
        $costo = $this->faker->randomFloat(2, 10, 2000);

        return [
            'pedido_id' => Pedido::factory(),
            'orden' => 1,
            'descripcion' => strtoupper($this->faker->words(5, true)),
            'unidad' => 'PZA',
            'cantidad' => $this->faker->numberBetween(1, 20),
            'costo' => $costo,
            'precio' => round($costo * 1.3, 2),
        ];
    }
}
