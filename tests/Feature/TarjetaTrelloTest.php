<?php

namespace Tests\Feature;

use App\Enums\EstadoPartidaPedido;
use App\Livewire\Pedidos\Nuevo;
use App\Livewire\Pedidos\Ver;
use App\Livewire\Tablero\Index;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoComentario;
use App\Models\PedidoPartida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** T14: tarjetas con la usabilidad de Trello (ventana flotante, comentarios, actividad, checklist). */
class TarjetaTrelloTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(string $rol = 'vendedor', array $datos = []): User
    {
        $u = User::factory()->create(['rol' => $rol] + $datos);
        $this->actingAs($u);

        return $u;
    }

    private function pedidoConPartidas(int $n = 2, array $datos = []): Pedido
    {
        $pedido = Pedido::factory()->create($datos);
        foreach (range(1, $n) as $i) {
            PedidoPartida::create(['pedido_id' => $pedido->id, 'orden' => $i, 'descripcion' => 'PARTIDA '.$i, 'unidad' => 'PZA',
                'cantidad' => 1, 'costo' => 123.45, 'precio' => 987.65]);
        }

        return $pedido->fresh();
    }

    public function test_abrir_y_cerrar_la_tarjeta_por_url(): void
    {
        $this->entrar();
        $cliente = Cliente::factory()->create(['nombre' => 'VIMIFOS']);
        $pedido = $this->pedidoConPartidas(1, ['cliente_id' => $cliente->id, 'numero_oc_cliente' => '78630']);

        // Enlace compartible: /pedidos?pedido=ID abre la ventana flotante sobre el tablero.
        $this->get(route('pedidos.index', ['pedido' => $pedido->id]))->assertOk()
            ->assertSee('modal-tarjeta', false)
            ->assertSee('Comentarios y actividad')
            ->assertSee('Cerrar tarjeta');
        $this->get(route('pedidos.index'))->assertOk()->assertDontSee('modal-tarjeta', false);

        Livewire::test(Index::class)
            ->call('abrir', $pedido->id)->assertSet('abierto', $pedido->id)->assertSee('Comentarios y actividad')
            ->call('cerrar')->assertSet('abierto', null)->assertDontSee('Comentarios y actividad');

        // La tarjeta pide cerrarse con un evento que escucha el tablero.
        Livewire::test(Ver::class, ['pedido' => $pedido, 'enModal' => true])->call('cerrar')->assertDispatched('cerrar-tarjeta');

        // Un id que no existe no truena: simplemente no abre nada.
        $this->get(route('pedidos.index', ['pedido' => 999999]))->assertOk()->assertDontSee('modal-tarjeta', false);

        // La página completa sigue existiendo.
        $this->get('/pedidos/'.$pedido->id)->assertOk()->assertSee('VIMIFOS - OC 78630')->assertSee('← Tablero');
    }

    public function test_comentarios_con_autor_y_permisos_para_borrar(): void
    {
        $autor = $this->entrar('vendedor', ['name' => 'Rosa Ventas']);
        $pedido = $this->pedidoConPartidas(1);

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->call('comentar')->assertHasErrors('comentario')
            ->set('comentario', 'El proveedor entrega el lunes')
            ->call('comentar')->assertHasNoErrors()
            ->assertSet('comentario', '')
            ->assertSee('El proveedor entrega el lunes')
            ->assertSee('Rosa Ventas')
            ->assertDispatched('pedido-actualizado');

        $c = PedidoComentario::firstOrFail();
        $this->assertSame($autor->id, $c->user_id);

        // Otro vendedor no puede borrar el comentario ajeno; el admin sí.
        $this->entrar('vendedor');
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('borrarComentario', $c->id)->assertForbidden();
        $this->entrar('admin');
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('borrarComentario', $c->id)->assertOk();
        $this->assertDatabaseCount('pedido_comentarios', 0);

        // El contador 💬 aparece en la tarjeta del tablero.
        PedidoComentario::create(['pedido_id' => $pedido->id, 'user_id' => $autor->id, 'texto' => 'Uno']);
        PedidoComentario::create(['pedido_id' => $pedido->id, 'user_id' => $autor->id, 'texto' => 'Dos']);
        Livewire::test(Index::class)->assertSee('💬 2');
    }

    public function test_actividad_registra_movimientos_estados_y_archivo(): void
    {
        $u = $this->entrar('vendedor', ['name' => 'Alberto']);
        $pedido = $this->pedidoConPartidas(2);
        [$a] = $pedido->partidas;

        Livewire::test(Index::class)->call('mover', $pedido->id, 'por_facturar', []);
        // Reordenar dentro de la misma columna no es actividad.
        Livewire::test(Index::class)->call('mover', $pedido->id, 'por_facturar', [$pedido->id]);

        $t = Livewire::test(Ver::class, ['pedido' => $pedido->fresh()])
            ->call('establecerEstado', $a->id, 'recibida')
            ->call('alternarBandera', 'revisar')
            ->call('archivar');

        $textos = PedidoActividad::where('pedido_id', $pedido->id)->orderBy('id')->pluck('descripcion')->all();
        $this->assertSame([
            'movió la tarjeta a "Por facturar"',
            'marcó "PARTIDA 1" como recogida',
            'puso la etiqueta "Revisar / dar seguimiento"',
            'archivó la tarjeta',
        ], $textos);
        $this->assertSame($u->id, PedidoActividad::first()->user_id);

        // Comentarios y actividad se muestran juntos, lo más nuevo arriba.
        PedidoComentario::create(['pedido_id' => $pedido->id, 'user_id' => $u->id, 'texto' => 'COMENTARIO-NUEVO']);
        $html = $t->call('$refresh')->html();
        $this->assertTrue(strpos($html, 'COMENTARIO-NUEVO') < strpos($html, 'archivó la tarjeta'));
        $this->assertTrue(strpos($html, 'archivó la tarjeta') < strpos($html, 'movió la tarjeta a'));
    }

    public function test_chip_de_estado_avanza_y_cambio_masivo(): void
    {
        $this->entrar();
        $pedido = $this->pedidoConPartidas(3);
        [$a, $b, $c] = $pedido->partidas;

        $t = Livewire::test(Ver::class, ['pedido' => $pedido]);
        $t->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Solicitada, $a->fresh()->estado);
        $t->call('siguienteEstado', $a->id)->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Entregada, $a->fresh()->estado);
        $t->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Pendiente, $a->fresh()->estado);

        $t->set('seleccion', [(string) $b->id, (string) $c->id])->call('accionMasiva', 'recibida')
            ->assertSee('2/3 recogidas')->assertSet('seleccion', []);
        $this->assertSame(EstadoPartidaPedido::Recibida, $c->fresh()->estado);
        // "Marcar recogida" anota cada partida con lo recogido (piezas sueltas: no pregunta cuántas).
        $this->assertSame(2, PedidoActividad::where('tipo', 'recogida')->where('descripcion', 'like', 'recogió 1 pza de "PARTIDA %": ya está completa (1 de 1)')->count());

        $t->set('seleccion', [(string) $a->id, (string) $b->id, (string) $c->id])->call('accionMasiva', 'facturada')->assertSee('3/3 facturadas');
    }

    public function test_campo_solo_alterna_recogida_y_entregada_y_no_ve_venta(): void
    {
        $this->entrar('campo');
        $pedido = $this->pedidoConPartidas(1);
        $a = $pedido->partidas->first();

        $t = Livewire::test(Ver::class, ['pedido' => $pedido, 'enModal' => true])
            ->assertSee('123.45')->assertDontSee('987.65')->assertDontSee('Utilidad')
            ->assertDontSee('Generar OC a proveedores')->assertDontSee('Archivar pedido');
        $t->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Recibida, $a->fresh()->estado);
        $t->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Entregada, $a->fresh()->estado);
        $t->call('siguienteEstado', $a->id);
        $this->assertSame(EstadoPartidaPedido::Recibida, $a->fresh()->estado);

        // Campo sí comenta, pero no edita título, fecha ni responsable.
        $t->set('comentario', 'Ya lo recogí')->call('comentar')->assertHasNoErrors();
        Livewire::test(Ver::class, ['pedido' => $pedido])->set('titulo', 'OTRO')->call('guardarTitulo')->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->set('fechaCompromiso', '2026-10-10')->call('guardarFechaCompromiso')->assertForbidden();
        Livewire::test(Ver::class, ['pedido' => $pedido])->call('cambiarResponsable', (string) auth()->id())->assertForbidden();

        // En el tablero, al abrir la tarjeta tampoco llega el precio de venta.
        $this->get(route('pedidos.index', ['pedido' => $pedido->id]))->assertOk()->assertDontSee('987.65')->assertDontSee('1,975.30');
    }

    public function test_titulo_fecha_y_responsable_editables(): void
    {
        $this->entrar();
        $cliente = Cliente::factory()->create(['nombre' => 'FANOSA']);
        $otro = User::factory()->create(['name' => 'Irene', 'iniciales' => 'ILE', 'activo' => true]);
        $pedido = $this->pedidoConPartidas(1, ['cliente_id' => $cliente->id, 'numero_oc_cliente' => '616986']);

        $t = Livewire::test(Ver::class, ['pedido' => $pedido])
            ->set('titulo', '  FANOSA   urgente  ')->call('guardarTitulo')->assertSet('titulo', 'FANOSA urgente');
        $this->assertSame('FANOSA urgente', $pedido->fresh()->titulo());

        // Vacío: vuelve al título automático.
        $t->set('titulo', '')->call('guardarTitulo');
        $this->assertNull($pedido->fresh()->titulo_personalizado);
        $this->assertStringStartsWith('FANOSA - OC 616986', $pedido->fresh()->titulo());

        $t->set('fechaCompromiso', '2026-10-05')->call('guardarFechaCompromiso');
        $this->assertSame('2026-10-05', $pedido->fresh()->fecha_compromiso->toDateString());

        $t->call('cambiarResponsable', (string) $otro->id)->assertSet('titulo', 'FANOSA - OC 616986 - ILE');
        $this->assertSame($otro->id, $pedido->fresh()->responsable_id);
        $this->assertTrue(PedidoActividad::where('descripcion', 'asignó a Irene como responsable')->exists());
    }

    public function test_notas_con_markdown_seguro(): void
    {
        $this->entrar();
        $pedido = $this->pedidoConPartidas(1, ['notas' => "**Urgente**\n<script>alert(1)</script>\n- uno"]);

        Livewire::test(Ver::class, ['pedido' => $pedido])
            ->assertSee('<strong>Urgente</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<li>uno</li>', false);
    }

    public function test_tablero_insignias_filtro_por_etiqueta_y_vencimiento(): void
    {
        $this->entrar();
        $vencido = $this->pedidoConPartidas(2, ['fecha_compromiso' => now()->subDays(3)->toDateString(), 'notas' => 'algo', 'revisar' => true]);
        $otro = $this->pedidoConPartidas(1);

        $html = Livewire::test(Index::class)->html();
        $this->assertStringContainsString('☑ 0/2', $html);
        $this->assertStringContainsString('bg-red-600 text-white" title="Fecha compromiso"', $html);
        $this->assertStringContainsString('aria-label="Tiene notas"', $html);

        $ids = fn ($t) => $t->viewData('columnas')->flatMap(fn ($c) => $c['pedidos']->pluck('id'))->all();
        $t = Livewire::test(Index::class);
        $this->assertContains('Revisar / dar seguimiento', $t->viewData('etiquetasDisponibles'));
        $this->assertSame([$vencido->id], $ids($t->set('etiqueta', 'Revisar / dar seguimiento')));
        $this->assertEqualsCanonicalizing([$vencido->id, $otro->id], $ids($t->set('etiqueta', '')));
    }

    public function test_agregar_tarjeta_rapida_en_una_columna(): void
    {
        $this->entrar();
        $cot = Cotizacion::factory()->hasPartidas(1)->create();

        $this->get('/pedidos/nuevo?columna=checar_tema')->assertOk();
        Livewire::withQueryParams(['columna' => 'checar_tema'])->test(Nuevo::class)
            ->assertSet('columna', 'checar_tema')
            ->call('seleccionarCliente', $cot->cliente_id)
            ->set('seleccion', [(string) $cot->partidas[0]->id])
            ->set('numeroOc', 'RAPIDA-1')
            ->set('justificacion', 'La OC llega por correo después')
            ->call('crear')->assertHasNoErrors();

        $pedido = Pedido::where('numero_oc_cliente', 'RAPIDA-1')->firstOrFail();
        $this->assertSame('checar_tema', $pedido->columna->value);
        $this->assertTrue(PedidoActividad::where('pedido_id', $pedido->id)->where('tipo', 'crear')->exists());

        // Una columna inventada cae en "OC recibida".
        Livewire::withQueryParams(['columna' => 'inventada'])->test(Nuevo::class)->assertSet('columna', 'oc_recibida');
    }
}
