<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use App\Support\Folios;
use Illuminate\Database\Eloquent\Factories\Factory;

class CotizacionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'folio' => fn () => Folios::siguiente(),
            'cliente_id' => Cliente::factory(),
            'vendedor_id' => User::factory(),
            'fecha' => now()->toDateString(),
            'estado' => 'borrador',
        ];
    }
}
