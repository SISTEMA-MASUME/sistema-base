<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use App\Support\Folios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportarRegistroTest extends TestCase
{
    use RefreshDatabase;

    private function archivo(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'reg');
        file_put_contents($ruta, json_encode([
            'consecutivo' => 10585,
            'clientes' => [['n' => 1, 'nombre' => 'PROVEX', 'direccion' => 'Av. 1', 'contacto' => 'JUAN', 'correo' => 'juan@x.com']],
            'hdr' => [
                ['cliente' => 'PROVEX.', 'responsable' => 'Rosa Ruiz', 'uid' => '9000', 'fecha' => '26/06/2026', 'link_pdf' => 'https://drive/x', 'id_cot' => '', 'formato' => '', 'status' => ''],
                ['cliente' => 'provex', 'responsable' => 'MOSTRADOR', 'uid' => '9001', 'fecha' => '27/06/2026', 'link_pdf' => '', 'id_cot' => '', 'formato' => '', 'status' => ''],
            ],
            'det' => [
                ['uid_linea' => '9000.02', 'uid' => 9000, 'cantidad' => 2, 'descripcion' => 'B', 'um' => 'PZA', 'proveedor' => 'Disnor', 'vendedor_prov' => '', 'modelo' => '', 'costo_sin' => '', 'costo_con' => 116, 'leadtime' => '', 'utilidad' => '', 'precio_sin' => 130, 'precio_neto' => '', 'status' => 'COLOCADO'],
                ['uid_linea' => '9000.01', 'uid' => 9000, 'cantidad' => 1, 'descripcion' => 'A', 'um' => 'PZA', 'proveedor' => 'DISNOR', 'vendedor_prov' => '', 'modelo' => '', 'costo_sin' => 10, 'costo_con' => 11.6, 'leadtime' => 'INMEDIATO', 'utilidad' => 25, 'precio_sin' => 12.5, 'precio_neto' => 14.5, 'status' => 'COTIZADO'],
            ],
        ]));

        return $ruta;
    }

    public function test_importa_cotizaciones_une_clientes_y_ajusta_folio(): void
    {
        User::factory()->create(['rol' => 'admin']);
        $archivo = $this->archivo();

        $this->artisan('masume:importar-registro', ['archivo' => $archivo])->assertSuccessful();
        $this->artisan('masume:importar-registro', ['archivo' => $archivo])->assertSuccessful();

        $this->assertSame(2, Cotizacion::count());
        $this->assertSame(1, Cliente::count());
        $c = Cotizacion::where('folio', 9000)->with('partidas')->first();
        $this->assertSame('aceptada', $c->estado->value);
        $this->assertSame(['A', 'B'], $c->partidas->pluck('descripcion')->all());
        $this->assertEquals(100, $c->partidas[1]->costo);   // 116 con IVA → 100
        $this->assertEquals(272.5, (float) $c->subtotal);   // 12.5 + 2×130
        $this->assertSame('https://drive/x', $c->pdf_original_url);
        $this->assertFalse(User::where('name', 'Rosa Ruiz')->first()->activo);
        $this->assertSame(10586, Folios::siguiente());
    }
}
