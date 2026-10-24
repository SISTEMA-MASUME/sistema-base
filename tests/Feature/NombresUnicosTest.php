<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NombresUnicosTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_permite_variantes_del_mismo_cliente(): void
    {
        $c = Cliente::create(['nombre' => ' provex ']);
        $this->assertSame('PROVEX', $c->nombre);

        $this->expectException(QueryException::class);
        Cliente::create(['nombre' => 'PROVEX.']);
    }
}
