<?php

namespace Tests\Unit;

use App\Support\Nombres;
use PHPUnit\Framework\TestCase;

class NombresTest extends TestCase
{
    public function test_variantes_del_mismo_cliente_tienen_la_misma_llave(): void
    {
        $this->assertSame('PROVEX', Nombres::limpiar('  provex. '));
        $this->assertSame(Nombres::llave('PROVEX'), Nombres::llave('Provex-'));
        $this->assertSame(Nombres::llave('Central Guadalajara'), Nombres::llave('CENTRAL GUADALAJARA.'));
    }
}
