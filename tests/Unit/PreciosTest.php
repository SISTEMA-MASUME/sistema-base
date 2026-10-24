<?php

namespace Tests\Unit;

use App\Support\Precios;
use Tests\TestCase;

class PreciosTest extends TestCase
{
    public function test_precio_desde_utilidad_es_markup_sobre_costo(): void
    {
        $this->assertSame(130.0, Precios::precioDesdeUtilidad(100, 30));
    }

    public function test_utilidad_desde_precio(): void
    {
        $this->assertSame(30.0, Precios::utilidadDesdePrecio(100, 130));
        $this->assertNull(Precios::utilidadDesdePrecio(0, 130));
    }

    public function test_costo_con_iva_se_convierte(): void
    {
        $this->assertEqualsWithDelta(100.0, Precios::costoSinIva(116), 0.0001);
    }

    public function test_totales_con_iva(): void
    {
        $this->assertSame(['subtotal' => 9298.63, 'iva' => 1487.78, 'total' => 10786.41], Precios::totales([8000, 1298.63]));
    }
}
