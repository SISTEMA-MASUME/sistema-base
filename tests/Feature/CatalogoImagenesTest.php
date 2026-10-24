<?php

namespace Tests\Feature;

use App\Livewire\Catalogo\Imagenes;
use App\Livewire\Cotizaciones\Editor;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\ProductoImagen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogoImagenesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($this->user);
    }

    private function editor()
    {
        $cot = Cotizacion::factory()->create(['vendedor_id' => $this->user->id]);

        return Livewire::test(Editor::class, ['cotizacion' => $cot])->call('agregarPartida');
    }

    private function id(Testable $c, int $i = 0): int
    {
        return (int) $c->get('partidas')[$i]['id'];
    }

    public function test_la_imagen_queda_ligada_y_se_pone_sola_en_otra_cotizacion(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Llave de impacto 1/2"', 'modelo' => 'DCF899']);
        $c->set('imagenes.0', UploadedFile::fake()->image('foto.jpg', 400, 300))->assertHasNoErrors();
        $ruta = CotizacionPartida::first()->imagen_path;
        $this->assertDatabaseHas('producto_imagenes', ['modelo' => 'DCF899', 'imagen_path' => $ruta]);

        // Otra cotización: mismo modelo escrito distinto → se pone sola.
        $d = $this->editor();
        $d->call('guardarCampos', $this->id($d), ['descripcion' => 'Llave impacto dewalt', 'modelo' => 'dcf-899']);
        $this->assertSame($ruta, CotizacionPartida::find($this->id($d))->imagen_path);
        $d->assertSet('mensaje', fn ($m) => str_contains($m, 'imagen guardada'));
        $this->assertSame(1, ProductoImagen::first()->veces_usada);
    }

    public function test_sin_modelo_se_reconoce_por_descripcion_pero_no_le_pone_la_de_otro_modelo(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Estopa blanca']);
        $c->set('imagenes.0', UploadedFile::fake()->image('estopa.png', 100, 100));
        $ruta = CotizacionPartida::first()->imagen_path;

        $d = $this->editor();
        $d->call('guardarCampos', $this->id($d), ['descripcion' => 'ESTOPA  BLANCA.']);
        $this->assertSame($ruta, CotizacionPartida::find($this->id($d))->imagen_path);

        // Descripción igual pero con un modelo ligado a otra imagen: no se mezclan.
        $e = $this->editor();
        $e->call('guardarCampos', $this->id($e), ['descripcion' => 'Taladro', 'modelo' => 'A1']);
        $e->set('imagenes.0', UploadedFile::fake()->image('a1.png', 100, 100));
        $f = $this->editor();
        $f->call('guardarCampos', $this->id($f), ['descripcion' => 'Taladro', 'modelo' => 'B2']);
        $this->assertNull(CotizacionPartida::find($this->id($f))->imagen_path);

        // Se escribe la descripción (sale la de "sin modelo") y luego el modelo: cambia a la de ese modelo.
        $g = $this->editor();
        $g->call('guardarCampos', $this->id($g), ['descripcion' => 'Estopa blanca']);
        $this->assertSame($ruta, CotizacionPartida::find($this->id($g))->imagen_path);
        $g->call('guardarCampos', $this->id($g), ['modelo' => 'a1']);
        $this->assertSame(ProductoImagen::where('modelo', 'A1')->value('imagen_path'), CotizacionPartida::find($this->id($g))->imagen_path);
    }

    public function test_quitar_la_imagen_la_desliga_pero_otras_cotizaciones_la_conservan(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Guantes nitrilo']);
        $c->set('imagenes.0', UploadedFile::fake()->image('g.png', 100, 100));
        $ruta = CotizacionPartida::first()->imagen_path;

        $d = $this->editor();
        $d->call('guardarCampos', $this->id($d), ['descripcion' => 'Guantes nitrilo']);
        $d->call('quitarImagen', 0);

        $this->assertSame(0, ProductoImagen::count());
        $this->assertNull(CotizacionPartida::find($this->id($d))->imagen_path);
        // La primera cotización conserva su imagen y el archivo sigue.
        $this->assertSame($ruta, CotizacionPartida::find($this->id($c))->imagen_path);
        Storage::disk('public')->assertExists($ruta);

        $e = $this->editor();
        $e->call('guardarCampos', $this->id($e), ['descripcion' => 'Guantes nitrilo']);
        $this->assertNull(CotizacionPartida::find($this->id($e))->imagen_path);
    }

    public function test_cambiar_la_imagen_actualiza_el_producto(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Cinta teflón']);
        $c->set('imagenes.0', UploadedFile::fake()->image('1.png', 100, 100));
        $c->set('imagenes.0', UploadedFile::fake()->image('2.png', 120, 100));
        $nueva = CotizacionPartida::first()->imagen_path;

        $this->assertSame(1, ProductoImagen::count());
        $this->assertSame($nueva, ProductoImagen::first()->imagen_path);
    }

    public function test_imagen_subida_antes_de_la_descripcion_se_liga_al_escribirla(): void
    {
        $c = $this->editor();
        $c->set('imagenes.0', UploadedFile::fake()->image('x.png', 100, 100));
        $this->assertSame(0, ProductoImagen::count());
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Pinza de presión']);
        $this->assertSame(1, ProductoImagen::count());
    }

    public function test_pantalla_del_catalogo_cambia_y_quita(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Martillo de uña', 'modelo' => 'M16']);
        $c->set('imagenes.0', UploadedFile::fake()->image('m.png', 100, 100));
        $ficha = ProductoImagen::first();

        $this->get('/catalogo-imagenes')->assertOk()->assertSee('Martillo de uña')->assertSee('M16');

        $p = Livewire::test(Imagenes::class)->set('busqueda', 'martillo')->assertSee('Martillo de uña');
        $p->set('archivos.'.$ficha->id, UploadedFile::fake()->image('n.png', 100, 100))->assertHasNoErrors();
        $this->assertNotSame($ficha->imagen_path, $ficha->fresh()->imagen_path);
        // La cotización que ya la tenía conserva la suya.
        Storage::disk('public')->assertExists(CotizacionPartida::first()->imagen_path);

        $p->call('quitar', $ficha->id);
        $this->assertSame(0, ProductoImagen::count());
    }

    public function test_avisa_si_el_producto_ya_tenia_imagen_o_hay_uno_parecido(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Disco de corte metal 4 1/2']);
        $c->set('imagenes.0', UploadedFile::fake()->image('1.png', 100, 100));

        // Mismo producto en otra cotización, con otra foto: avisa que ya tenía y la cambia (no duplica).
        $d = $this->editor();
        $d->call('guardarCampos', $this->id($d), ['descripcion' => 'disco de corte metal 4-1/2']);
        $d->call('quitarImagen', 0);
        $this->assertSame(0, ProductoImagen::count());
        $c->set('imagenes.0', UploadedFile::fake()->image('2.png', 100, 100))
            ->assertSet('mensaje', fn ($m) => str_contains($m, 'Imagen guardada'));
        $e = $this->editor();
        $e->call('guardarCampos', $this->id($e), ['descripcion' => 'Disco de corte metal 4 1/2']);
        $e->set('imagenes.0', UploadedFile::fake()->image('3.png', 100, 100))
            ->assertSet('mensaje', fn ($m) => str_contains($m, 'ya tenía imagen guardada'));
        $this->assertSame(1, ProductoImagen::count());

        // Otro nombre del mismo producto: avisa que hay uno parecido.
        $f = $this->editor();
        $f->call('guardarCampos', $this->id($f), ['descripcion' => 'Disco corte para metal marca Truper']);
        $f->set('imagenes.0', UploadedFile::fake()->image('4.png', 100, 100))
            ->assertSet('mensaje', fn ($m) => str_contains($m, 'ya hay uno parecido') && str_contains($m, 'Disco de corte metal'));
    }

    public function test_buscar_en_catalogo_usa_la_imagen_y_liga_el_otro_nombre(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Silicón transparente', 'modelo' => 'SIL-280']);
        $c->set('imagenes.0', UploadedFile::fake()->image('s.png', 100, 100));
        $ficha = ProductoImagen::first();

        $d = $this->editor();
        $d->call('guardarCampos', $this->id($d), ['descripcion' => 'Sellador silicon cartucho']);
        $this->assertNull(CotizacionPartida::find($this->id($d))->imagen_path);
        $d->call('abrirCatalogo', 0)->set('buscarCatalogo', 'sil-280')->assertSee('Silicón transparente')
            ->call('usarDelCatalogo', $ficha->id)->assertSet('catalogoPara', null);
        $this->assertSame($ficha->imagen_path, CotizacionPartida::find($this->id($d))->imagen_path);

        // La próxima vez el otro nombre también la toma sola.
        $e = $this->editor();
        $e->call('guardarCampos', $this->id($e), ['descripcion' => 'SELLADOR SILICON CARTUCHO']);
        $this->assertSame($ficha->imagen_path, CotizacionPartida::find($this->id($e))->imagen_path);
    }

    public function test_sugerencias_muestran_la_imagen_del_catalogo(): void
    {
        $c = $this->editor();
        $c->call('guardarCampos', $this->id($c), ['descripcion' => 'Flexómetro 8 m', 'modelo' => 'FH-8M']);
        $c->set('imagenes.0', UploadedFile::fake()->image('f.png', 100, 100));
        $cot = CotizacionPartida::first()->cotizacion;

        $json = $this->getJson('/clientes/'.$cot->cliente_id.'/sugerencias')->assertOk()->json();
        $this->assertStringContainsString(ProductoImagen::first()->imagen_path, $json[0]['im'] ?? '');
        $global = $this->getJson('/sugerencias/global?q=flexómetro')->json();
        $this->assertArrayHasKey('im', $global[0]);
    }

    public function test_campo_no_entra_al_catalogo(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'campo']));
        $this->get('/catalogo-imagenes')->assertForbidden();
    }
}
