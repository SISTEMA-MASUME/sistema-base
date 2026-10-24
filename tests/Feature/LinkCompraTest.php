<?php

namespace Tests\Feature;

use App\Livewire\Cotizaciones\Editor;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\User;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Link de compra pegado por error en "Modelo": se pasa a su campo y nunca sale al cliente. */
class LinkCompraTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_pegado_en_modelo_pasa_a_link_de_compra_y_no_sale_en_el_pdf(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create();
        $p = CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'modelo' => null, 'link_compra' => null]);
        $url = 'https://articulo.mercadolibre.com.mx/MLM-3884022798-engrapadora';

        Livewire::test(Editor::class, ['cotizacion' => $cot])->call('guardarCampos', $p->id, ['modelo' => $url]);
        $p->refresh();
        $this->assertNull($p->modelo);
        $this->assertSame($url, $p->link_compra);

        // Aunque viniera en "Modelo" (partidas viejas), el PDF del cliente no lo muestra.
        $p->forceFill(['modelo' => $url])->save();
        $this->assertStringNotContainsString('mercadolibre', CotizacionPdf::html($cot->fresh()));
    }

    public function test_el_modelo_es_interno_y_no_sale_en_el_pdf_ni_en_la_lista_sin_precios(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));
        $cot = Cotizacion::factory()->create();
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'descripcion' => 'ROTOMARTILLO 800W', 'marca' => 'DEWALT', 'modelo' => 'SKU-PROV-99871']);

        $html = CotizacionPdf::html($cot->fresh());
        $this->assertStringContainsString('ROTOMARTILLO 800W', $html);
        $this->assertStringContainsString('DEWALT', $html);
        $this->assertStringNotContainsString('SKU-PROV-99871', $html);

        $lista = Livewire::test(Editor::class, ['cotizacion' => $cot])->assertSee('SKU-PROV-99871')->viewData('listaSinPrecios');
        $this->assertStringNotContainsString('SKU-PROV-99871', $lista);
    }
}
