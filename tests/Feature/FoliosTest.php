<?php

namespace Tests\Feature;

use App\Support\Folios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoliosTest extends TestCase
{
    use RefreshDatabase;

    public function test_arranca_despues_del_folio_inicial_y_no_repite(): void
    {
        config(['masume.folio_inicial.cotizacion' => 10500]);

        $this->assertSame(10501, Folios::siguiente());
        $this->assertSame(10502, Folios::siguiente());
        $this->assertDatabaseHas('contadores', ['clave' => 'cotizacion', 'ultimo' => 10502]);
    }
}
