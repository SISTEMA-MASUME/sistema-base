<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CotizacionPdfTest extends TestCase
{
    use RefreshDatabase;

    private function cotizacion(): Cotizacion
    {
        $cliente = Cliente::factory()->create(['nombre' => 'ACME PRUEBA', 'razon_social' => 'ACME PRUEBA SA DE CV']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'forma_pago' => 'Contado']);
        $prov = Proveedor::factory()->create(['nombre' => 'PROVEEDOR SECRETO']);

        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'Martillo incluido', 'cantidad' => 2,
            'costo' => 123.4567, 'precio' => 200, 'importe' => 400, 'proveedor_id' => $prov->id,
            'proveedor_vendedor' => 'VENDEDOR OCULTO', 'entrega_lenta' => true, 'tiempo_entrega' => '3-5 días hábiles',
        ]);
        CotizacionPartida::factory()->create([
            'cotizacion_id' => $cot->id, 'orden' => 2, 'descripcion' => 'Pieza excluida', 'incluir' => false,
            'precio' => 999, 'importe' => 999,
        ]);

        return $cot->fresh();
    }

    public function test_genera_pdf_valido(): void
    {
        $bytes = CotizacionPdf::generar($this->cotizacion());

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
    }

    public function test_html_contiene_datos_y_no_costo_ni_proveedor(): void
    {
        $cot = $this->cotizacion();
        $html = CotizacionPdf::html($cot);

        $this->assertStringContainsString((string) $cot->folio, $html);
        $this->assertStringContainsString('ACME PRUEBA SA DE CV', $html);
        $this->assertStringContainsString('Martillo incluido', $html);
        $this->assertStringContainsString('$400.00', $html);
        $this->assertStringContainsString('Cuatrocientos sesenta y cuatro pesos 00/100 M.N.', $html);
        $this->assertStringContainsString('lento', $html);
        $this->assertStringNotContainsString('Pieza excluida', $html);
        $this->assertStringNotContainsString('PROVEEDOR SECRETO', $html);
        $this->assertStringNotContainsString('VENDEDOR OCULTO', $html);
        $this->assertStringNotContainsString('123.45', $html);
        // Teléfono de MASUME en el encabezado y al pie de cada hoja.
        $this->assertStringContainsString('33 0000 0000', $html);
        $this->assertStringContainsString('33 0000 0000', CotizacionPdf::pie($cot));
        $this->assertStringNotContainsString('33 0000 9999', $html);
    }

    public function test_guardar_escribe_en_disco_local(): void
    {
        Storage::fake('local');
        $cot = $this->cotizacion();

        $ruta = CotizacionPdf::guardar($cot);

        $this->assertStringStartsWith('cotizaciones/', $ruta);
        Storage::disk('local')->assertExists($ruta);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($ruta));
    }

    public function test_partida_con_imagen_y_sin_imagen(): void
    {
        Storage::fake('public');
        $cot = $this->cotizacion();
        Storage::disk('public')->put('partidas/x.jpg', file_get_contents(base_path('tests/Fixtures/producto.jpg')));
        $cot->partidas()->where('orden', 1)->update(['imagen_path' => 'partidas/x.jpg']);

        $this->assertStringContainsString('x.jpg', CotizacionPdf::html($cot->fresh()));
        $this->assertStringStartsWith('%PDF', CotizacionPdf::generar($cot->fresh()));
    }
}
