<?php

namespace Tests\Feature;

use App\Livewire\Vacaciones\Index;
use App\Models\User;
use App\Models\Vacacion;
use App\Support\Vacaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Vacaciones por antigüedad: días por ley, periodo y saldo, días hábiles, calendario de Google y permisos. */
class VacacionesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://script.google.com/macros/s/PRUEBA/exec';

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

    private function configurarCalendario(): void
    {
        config(['services.calendario.url' => self::URL, 'services.calendario.token' => 'secreto']);
    }

    private function rosa(): User
    {
        return User::factory()->create(['name' => 'Rosa', 'fecha_ingreso' => '2023-07-03', 'trabaja_sabados' => false]);
    }

    public function test_dias_por_ley_segun_antiguedad(): void
    {
        foreach ([0 => 0, 1 => 12, 2 => 14, 3 => 16, 4 => 18, 5 => 20, 6 => 22, 10 => 22, 11 => 24, 15 => 24, 16 => 26, 21 => 28, 26 => 30, 31 => 32, 36 => 34] as $anios => $dias) {
            $this->assertSame($dias, Vacaciones::diasPorLey($anios), "años $anios");
        }

        $r = Vacaciones::resumen($this->rosa());   // 3 años cumplidos el 3-jul-2026
        $this->assertSame(16, $r['dias_periodo']);
        $this->assertSame('2026-07-03', $r['desde']->toDateString());
        $this->assertSame('2027-07-02', $r['hasta']->toDateString());
        $this->assertSame('2027-07-03', $r['proximo_aniversario']->toDateString());
        $this->assertSame(18, $r['proximos_dias']);
        $this->assertSame(16.0, $r['saldo']);
    }

    public function test_primer_anio_sin_dias_y_anticipados_en_negativo(): void
    {
        $a = User::factory()->create(['name' => 'Andrea', 'fecha_ingreso' => '2026-04-01']);
        $this->assertSame(0, Vacaciones::resumen($a)['dias_periodo']);

        Vacacion::create(['user_id' => $a->id, 'tipo' => 'ajuste', 'desde' => '2026-09-01', 'dias' => -5, 'estatus' => 'registrada']);
        $r = Vacaciones::resumen($a);
        $this->assertSame(-5.0, $r['saldo']);
        $this->assertSame(12, $r['proximos_dias']);

        // Al cumplir el año, lo anticipado se descuenta del primer periodo (el que empieza en el aniversario).
        $r = Vacaciones::resumen($a, Carbon::parse('2027-05-01'));
        $this->assertSame(12, $r['dias_periodo']);
        $this->assertSame(7.0, $r['saldo']);
        // En el segundo periodo ya no pesa.
        $this->assertSame(14.0, Vacaciones::resumen($a, Carbon::parse('2028-05-01'))['saldo']);
    }

    public function test_saldo_con_tomadas_y_ajustes_solo_del_periodo(): void
    {
        $u = $this->rosa();
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'ajuste', 'desde' => '2026-07-03', 'dias' => -6, 'estatus' => 'registrada']);
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'tomadas', 'desde' => '2026-12-21', 'hasta' => '2026-12-23', 'dias' => 3, 'estatus' => 'registrada']);
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'tomadas', 'desde' => '2026-11-02', 'hasta' => '2026-11-03', 'dias' => 2, 'estatus' => 'cancelada']);
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'tomadas', 'desde' => '2026-02-02', 'hasta' => '2026-02-06', 'dias' => 5, 'estatus' => 'registrada']);  // periodo anterior

        $r = Vacaciones::resumen($u);
        $this->assertSame(3.0, $r['usados']);
        $this->assertSame(-6.0, $r['ajustes']);
        $this->assertSame(16 - 3 - 6.0, $r['saldo']);
    }

    public function test_sin_fecha_de_ingreso_no_se_calcula_pero_con_dias_propios_si(): void
    {
        $e = User::factory()->create(['name' => 'Ernesto']);
        $r = Vacaciones::resumen($e);
        $this->assertFalse($r['calculable']);
        $this->assertSame('Falta la fecha de ingreso', $r['mensaje']);
        $this->assertNull($r['saldo']);

        $e->update(['vacaciones_dias_anuales' => 10]);
        $r = Vacaciones::resumen($e->fresh());
        $this->assertSame(10.0, $r['saldo']);
    }

    public function test_dias_habiles_domingo_sabado_y_festivos(): void
    {
        $sinSabado = User::factory()->create(['trabaja_sabados' => false]);
        $conSabado = User::factory()->create(['trabaja_sabados' => true]);

        // Lun 5 a dom 11 de octubre de 2026.
        $this->assertSame(5, Vacaciones::diasHabiles($sinSabado, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11')));
        $this->assertSame(6, Vacaciones::diasHabiles($conSabado, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-11')));
        // 16 de septiembre 2026 (miércoles) es festivo.
        $this->assertSame(4, Vacaciones::diasHabiles($sinSabado, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-18')));
        // Tercer lunes de noviembre 2026 = 16 (festivo).
        $this->assertTrue(Vacaciones::esFestivo(Carbon::parse('2026-11-16')));
        $this->assertSame(4, Vacaciones::diasHabiles($sinSabado, Carbon::parse('2026-11-16'), Carbon::parse('2026-11-20')));
        // 1 de octubre solo en año de cambio de presidente.
        $this->assertTrue(Vacaciones::esFestivo(Carbon::parse('2030-10-01')));
        $this->assertFalse(Vacaciones::esFestivo(Carbon::parse('2026-10-01')));
        $this->assertTrue(Vacaciones::esFestivo(Carbon::parse('2036-10-01')));
        // Primer lunes de febrero y tercer lunes de marzo de 2026.
        $this->assertTrue(Vacaciones::esFestivo(Carbon::parse('2026-02-02')));
        $this->assertTrue(Vacaciones::esFestivo(Carbon::parse('2026-03-16')));
    }

    public function test_registrar_crea_la_vacacion_y_el_evento(): void
    {
        $this->configurarCalendario();
        Http::fake([self::URL => Http::response(['ok' => true, 'evento_id' => 'evt123'])]);
        $u = $this->rosa();

        Livewire::actingAs($u)->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-09')->set('nota', 'Viaje')
            ->call('registrar')
            ->assertSet('aviso', null)
            ->assertSet('desde', '');

        $v = Vacacion::first();
        $this->assertSame($u->id, $v->user_id);
        $this->assertSame('tomadas', $v->tipo);
        $this->assertSame('5.0', (string) $v->dias);
        $this->assertSame('evt123', $v->evento_id);

        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->url() === self::URL && $r->method() === 'POST'
                && $d['token'] === 'secreto' && $d['accion'] === 'crear'
                && $d['titulo'] === 'Vacaciones: Rosa (5 días)'
                && $d['desde'] === '2026-10-05' && $d['hasta'] === '2026-10-09'
                && str_contains($d['descripcion'], 'Viaje');
        });
    }

    public function test_sin_configuracion_se_registra_igual_con_aviso(): void
    {
        Http::fake();
        $u = $this->rosa();

        Livewire::actingAs($u)->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-05')
            ->call('registrar')
            ->assertSee('anótalo a mano')->assertSee('CALENDARIO_URL');

        $this->assertSame(1, Vacacion::count());
        $this->assertNull(Vacacion::first()->evento_id);
        Http::assertNothingSent();
    }

    public function test_si_el_calendario_falla_se_registra_igual_con_aviso(): void
    {
        $this->configurarCalendario();
        Http::fake([self::URL => Http::response('Error', 500)]);

        Livewire::actingAs($this->rosa())->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-05')
            ->call('registrar')
            ->assertSee('anótalo a mano')->assertSee('Google no respondió bien');

        $this->assertSame(1, Vacacion::count());
    }

    public function test_token_que_no_coincide_lo_explica(): void
    {
        $this->configurarCalendario();
        Http::fake([self::URL => Http::response(['ok' => false, 'error' => 'Token inválido'])]);

        Livewire::actingAs($this->rosa())->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-05')
            ->call('registrar')
            ->assertSee('la clave no coincide');
    }

    public function test_pasarse_del_saldo_pide_confirmacion(): void
    {
        $u = $this->rosa();
        Vacacion::create(['user_id' => $u->id, 'tipo' => 'ajuste', 'desde' => '2026-07-03', 'dias' => -15, 'estatus' => 'registrada']);   // queda 1

        Livewire::actingAs($u)->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-07')
            ->call('registrar')
            ->assertSet('pedirConfirmacion', true);
        $this->assertSame(0, Vacacion::where('tipo', 'tomadas')->count());

        Livewire::actingAs($u)->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-07')
            ->call('registrar', true);
        $this->assertSame(1, Vacacion::where('tipo', 'tomadas')->count());
    }

    public function test_no_se_cruzan_vacaciones_ni_se_registran_dias_sin_habiles(): void
    {
        $u = $this->rosa();
        $c = Livewire::actingAs($u)->test(Index::class)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-07')->call('registrar');
        $c->set('desde', '2026-10-07')->set('hasta', '2026-10-08')->call('registrar')
            ->assertSet('error', 'Ya hay vacaciones registradas que se cruzan con esas fechas.');
        $c->set('desde', '2026-10-17')->set('hasta', '2026-10-18')->call('registrar');   // sábado y domingo
        $this->assertSame(1, Vacacion::count());
    }

    public function test_cancelar_manda_borrar_al_calendario(): void
    {
        $this->configurarCalendario();
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $u = $this->rosa();
        $v = Vacacion::create(['user_id' => $u->id, 'tipo' => 'tomadas', 'desde' => '2026-10-05', 'hasta' => '2026-10-06',
            'dias' => 2, 'estatus' => 'registrada', 'evento_id' => 'evt123']);

        Livewire::actingAs($u)->test(Index::class)->call('cancelar', $v->id)->assertSet('aviso', null);

        $this->assertSame('cancelada', $v->fresh()->estatus);
        Http::assertSent(fn (Request $r) => $r['accion'] === 'borrar' && $r['evento_id'] === 'evt123' && $r['token'] === 'secreto');
    }

    public function test_permisos_cada_quien_solo_lo_suyo(): void
    {
        $rosa = $this->rosa();
        $otro = User::factory()->create(['name' => 'Hugo', 'rol' => 'campo', 'fecha_ingreso' => '2026-02-23']);
        $ajena = Vacacion::create(['user_id' => $otro->id, 'tipo' => 'tomadas', 'desde' => '2026-10-20', 'hasta' => '2026-10-21', 'dias' => 2, 'estatus' => 'registrada']);
        $pasada = Vacacion::create(['user_id' => $rosa->id, 'tipo' => 'tomadas', 'desde' => '2026-09-21', 'hasta' => '2026-09-22', 'dias' => 2, 'estatus' => 'registrada']);

        $c = Livewire::actingAs($rosa)->test(Index::class)->set('paraUsuario', (string) $otro->id)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-05')->call('registrar');
        $this->assertSame($rosa->id, Vacacion::latest('id')->first()->user_id);   // ignoró "paraUsuario": se registró a sí misma

        $c->call('cancelar', $ajena->id)->assertSet('error', 'No puedes cancelar esas vacaciones.');
        $c->call('cancelar', $pasada->id)->assertSet('error', 'No puedes cancelar esas vacaciones.');
        $this->assertSame('registrada', $ajena->fresh()->estatus);

        Livewire::actingAs($rosa)->test(Index::class)->set('ajUsuario', (string) $otro->id)->set('ajDias', '-2')->set('ajNota', 'x y z')
            ->call('guardarAjuste')->assertForbidden();
        Livewire::actingAs($rosa)->test(Index::class)->call('editar', $otro->id)->assertForbidden();
    }

    public function test_el_admin_registra_por_otros_ajusta_y_edita(): void
    {
        $admin = User::factory()->create(['rol' => 'admin', 'name' => 'Daniel']);
        $rosa = $this->rosa();

        $c = Livewire::actingAs($admin)->test(Index::class)->set('paraUsuario', (string) $rosa->id)
            ->set('desde', '2026-10-05')->set('hasta', '2026-10-05')->call('registrar');
        $this->assertSame($rosa->id, Vacacion::first()->user_id);
        $this->assertSame($admin->id, Vacacion::first()->registrada_por);

        $c->set('ajUsuario', (string) $rosa->id)->set('ajDias', '-2,5')->set('ajFecha', '2026-10-01')->set('ajNota', 'Días anticipados')
            ->call('guardarAjuste');
        $this->assertSame('-2.5', (string) Vacacion::where('tipo', 'ajuste')->first()->dias);

        $c->call('cancelar', Vacacion::where('tipo', 'tomadas')->first()->id)->assertSet('error', null);

        $c->call('editar', $rosa->id)->set('edIngreso', '2020-01-06')->set('edDias', '')->set('edSabados', true)->call('guardarEmpleado');
        $rosa->refresh();
        $this->assertSame('2020-01-06', $rosa->fecha_ingreso->toDateString());
        $this->assertTrue($rosa->trabaja_sabados);

        $c->call('editar', $rosa->id)->set('edDias', '9')->call('guardarEmpleado');
        $this->assertSame(9, $rosa->fresh()->vacaciones_dias_anuales);
    }

    public function test_acceso_a_la_pantalla(): void
    {
        $this->get('/vacaciones')->assertRedirect();

        foreach (['vendedor', 'campo', 'admin'] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]))->get('/vacaciones')->assertOk()->assertSee('Mis vacaciones');
        }

        $ned = User::factory()->create(['rol' => 'nedtracking']);
        $this->actingAs($ned)->get('/vacaciones')->assertRedirect(route('ned.index'));
    }
}
