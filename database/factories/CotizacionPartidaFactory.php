<?php

namespace Database\Factories;

use App\Models\Cotizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

class CotizacionPartidaFactory extends Factory
{
    public function definition(): array
    {
        $costo = $this->faker->randomFloat(2, 10, 2000);
        $cantidad = $this->faker->numberBetween(1, 20);
        $precio = round($costo * 1.3, 2);

        return [
            'cotizacion_id' => Cotizacion::factory(),
            'orden' => 1,
            'descripcion' => strtoupper($this->faker->words(5, true)),
            'unidad' => 'PZA',
            'cantidad' => $cantidad,
            'costo' => $costo,
            'utilidad_pct' => 30,
            'precio' => $precio,
            'importe' => round($precio * $cantidad, 2),
            'tiempo_entrega' => 'Inmediata',
        ];
    }
}
