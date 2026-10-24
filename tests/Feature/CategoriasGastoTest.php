<?php

namespace Tests\Feature;

use App\Livewire\Finanzas\CategoriasGastos;
use App\Livewire\Finanzas\Gastos;
use App\Models\Gasto;
use App\Models\User;
use App\Support\CategoriasGasto;
use App\Support\Tablero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Categorías de gastos: sugerencia por palabras clave, pantalla "Ordenar categorías" y formulario sin categorías libres. */
class CategoriasGastoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gasto(string $categoria, array $extra = []): Gasto
    {
        return Gasto::create($extra + ['fecha' => '2026-09-10', 'monto' => 100, 'de' => 'masume', 'tipo' => 'gasto',
            'categoria' => $categoria, 'descripcion' => 'x', 'origen' => 'sheets']);
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: bool}> */
    public static function casos(): array
    {
        return [
            'gsolina' => ['GSOLINA', 'GASOLINA', true],
            'gasolina exacta' => ['GASOLINA', 'GASOLINA', true],
            'bateria ram' => ['BATERIA RAM', 'MANTENIMIENTO VEHICULOS', true],
            'ram' => ['RAM', 'MANTENIMIENTO VEHICULOS', true],
            'reparacion ram 700' => ['REPARACION RAM 700', 'MANTENIMIENTO VEHICULOS', true],
            'revision ram' => ['REVISION RAM', 'MANTENIMIENTO VEHICULOS', true],
            'ram 700 es la compra' => ['RAM 700', 'COMPRA DE VEHICULOS Y EQUIPO', true],
            'aciete' => ['CAMBIO DE ACIETE', 'MANTENIMIENTO VEHICULOS', true],
            'aceite' => ['CAMBIO DE ACEITE', 'MANTENIMIENTO VEHICULOS', true],
            'estereo con acento' => ['ESTÉREO', 'MANTENIMIENTO VEHICULOS', true],
            'carls' => ['CARLS', 'ALIMENTOS', true],
            'kfc' => ['KFC', 'ALIMENTOS', true],
            'kevin' => ['KEVIN', 'ALIMENTOS', true],
            'didi food' => ['DIDI FOOD', 'ALIMENTOS', true],
            'didi' => ['DIDI', 'TRANSPORTE Y ENVIOS', true],
            'envio' => ['ENVIO GLOBAL ACERO', 'TRANSPORTE Y ENVIOS', true],
            'sueldo' => ['SUELDO', 'NOMINA', true],
            'aguinaldo' => ['AGUINALDO', 'NOMINA', true],
            'nomina intacta' => ['NOMINA', 'NOMINA', true],
            'reparto socio intacto' => ['REPARTO SOCIO', 'REPARTO SOCIO', true],
            'pago tarjetas paty' => ['PAGO TARJETAS PATY', 'PAGO TARJETAS', true],
            'tarjetas son de presentacion' => ['TARJETAS', 'PUBLICIDAD', true],
            'seguro camioneta' => ['SEGURO CAMIONETA', 'SEGUROS', true],
            'sat' => ['SAT', 'IMPUESTOS', true],
            'timbres' => ['TIMBRES', 'CONTADOR Y TIMBRES', true],
            'pubicidad face' => ['PUBICIDAD FACE', 'PUBLICIDAD', true],
            'lonas calcas' => ['LONAS, CALCAS', 'PUBLICIDAD', true],
            'compu 2' => ['COMPU 2', 'COMPUTO Y PAPELERIA', true],
            'lap' => ['LAP', 'COMPUTO Y PAPELERIA', true],
            'megacable' => ['MEGACABLE', 'SERVICIOS', true],
            'posada' => ['POSADA', 'PERSONAL Y EVENTOS', true],
            'gastos med' => ['GASTOS MED', 'PERSONAL Y EVENTOS', true],
            'subscripciones' => ['SUBSCRIPCIONES', 'SUBSCRIPCIONES', true],
            'bomba' => ['BOMBA', 'REPARACIONES', true],
            'vidrios' => ['VIDRIOS', 'REPARACIONES', true],
            'mezcla gasolina oxxo comida' => ['GASOLINA/OXXO/COMIDA', 'GASOLINA', false],
            'jj' => ['JJ', 'GASTOS OPERATIVOS', false],
            'marisa' => ['MARISA', 'GASTOS OPERATIVOS', false],
            'neufeld' => ['NEUFELD', 'GASTOS OPERATIVOS', false],
            'varios' => ['VARIOS', 'GASTOS OPERATIVOS', false],
            'error' => ['ERROR', 'GASTOS OPERATIVOS', false],
            'pago extraviado' => ['PAGO EXTRAVIADO', 'GASTOS OPERATIVOS', false],
            'anticipo' => ['ANTICIPO', 'GASTOS OPERATIVOS', false],
            'cajas' => ['CAJAS', 'GASTOS OPERATIVOS', false],
            'insurgente' => ['INSURGENTE', 'GASTOS OPERATIVOS', false],
            'gastos operativos exacta' => ['GASTOS OPERATIVOS', 'GASTOS OPERATIVOS', true],
        ];
    }

    #[DataProvider('casos')]
    public function test_sugiere_la_categoria_general(string $entrada, string $esperada, bool $segura): void
    {
        $r = CategoriasGasto::sugerir($entrada);
        $this->assertSame($esperada, $r['categoria'], $entrada);
        $this->assertSame($segura, $r['segura'], $entrada);
    }

    public function test_la_vacia_se_intenta_con_la_descripcion(): void
    {
        $this->assertSame(['categoria' => 'GASOLINA', 'segura' => false], CategoriasGasto::sugerir(null, 'Gasolina de la camioneta'));
        $this->assertSame(['categoria' => null, 'segura' => false], CategoriasGasto::sugerir('', 'cosa rara'));
        $this->assertSame(['categoria' => null, 'segura' => false], CategoriasGasto::sugerir(null, null));
    }

    public function test_todo_lo_que_se_sugiere_esta_en_la_lista_general(): void
    {
        foreach (self::casos() as [$entrada]) {
            $this->assertContains(CategoriasGasto::sugerir($entrada)['categoria'], Gasto::CATEGORIAS);
        }
    }

    // ===== Pantalla =====

    public function test_la_pantalla_unifica_y_el_tablero_agrupa_con_las_generales(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->gasto('GSOLINA', ['monto' => 100]);
        $this->gasto('GASOLINA', ['monto' => 50]);
        $this->gasto('CARLS', ['monto' => 30]);
        $this->gasto('KFC', ['monto' => 20]);
        $this->gasto('JJ', ['monto' => 10]);
        $this->gasto('NOMINA', ['monto' => 5]);
        $borrado = $this->gasto('BATERIA RAM', ['monto' => 7]);
        $borrado->delete();

        Livewire::test(CategoriasGastos::class)
            ->assertSee('GSOLINA')->assertSee('CARLS')->assertSee('revisa')->assertDontSeeHtml('wire:key="cat-'.md5('NOMINA').'"')
            ->call('unificarTodas')->assertSee('Listo');

        $this->assertSame(150.0, (float) Gasto::where('categoria', 'GASOLINA')->sum('monto'));
        $this->assertSame(50.0, (float) Gasto::where('categoria', 'ALIMENTOS')->sum('monto'));
        $this->assertSame(10.0, (float) Gasto::where('categoria', 'GASTOS OPERATIVOS')->sum('monto'));
        $this->assertSame(5.0, (float) Gasto::where('categoria', 'NOMINA')->sum('monto'));
        $this->assertSame('MANTENIMIENTO VEHICULOS', Gasto::withTrashed()->find($borrado->id)->categoria);

        $filas = collect(app(Tablero::class)->gastosPorCategoria(2026))->pluck('monto', 'nombre');
        $this->assertSame(150.0, (float) $filas['GASOLINA']);
        $this->assertSame(50.0, (float) $filas['ALIMENTOS']);
        $this->assertArrayNotHasKey('CARLS', $filas->all());
    }

    public function test_unificar_solo_una_con_la_categoria_elegida(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $this->gasto('JJ');
        $this->gasto('KFC');

        Livewire::test(CategoriasGastos::class)
            ->set('destino.'.md5('JJ'), 'PERSONAL Y EVENTOS')->call('unificarUna', 'JJ');

        $this->assertSame(1, Gasto::where('categoria', 'PERSONAL Y EVENTOS')->count());
        $this->assertSame(1, Gasto::where('categoria', 'KFC')->count());
    }

    public function test_los_sin_categoria_se_asignan_uno_por_uno(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $g = $this->gasto('', ['descripcion' => 'Gasolina camioneta']);
        $g->forceFill(['categoria' => null])->save();

        Livewire::test(CategoriasGastos::class)->assertSee('Sin categoría')
            ->assertSet('sinCategoria.'.$g->id, 'GASOLINA')
            ->set('sinCategoria.'.$g->id, 'SERVICIOS')->call('asignarSinCategoria', $g->id);

        $this->assertSame('SERVICIOS', $g->fresh()->categoria);
    }

    public function test_permisos_de_la_pantalla(): void
    {
        $this->get(route('finanzas.gastos.categorias'))->assertRedirect();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get(route('finanzas.gastos.categorias'))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'permisos' => ['gastos']]))->get(route('finanzas.gastos.categorias'))->assertOk();
        $this->actingAs(User::factory()->create(['rol' => 'admin']))->get(route('finanzas.gastos'))->assertOk()->assertSee('Ordenar categorías');
    }

    // ===== Formulario =====

    public function test_el_formulario_no_acepta_categoria_libre_pero_conserva_la_vieja_al_editar(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'admin']));
        $vieja = $this->gasto('CARLS');

        Livewire::test(Gastos::class)
            ->assertDontSee('Otra (escribirla)')
            ->set('form.monto', '10')->set('form.categoria', 'REGALOS DE FIN DE AÑO')->call('guardar')->assertHasErrors('form.categoria')
            ->call('editar', $vieja->id)->assertSee('CARLS (vieja)')
            ->set('form.monto', '15')->call('guardar')->assertHasNoErrors();
        $this->assertSame('CARLS', $vieja->fresh()->categoria);
        $this->assertSame(0, Gasto::where('categoria', 'REGALOS DE FIN DE AÑO')->count());
    }
}
