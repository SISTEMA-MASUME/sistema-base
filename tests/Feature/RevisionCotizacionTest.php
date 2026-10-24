<?php

namespace Tests\Feature;

use App\Livewire\Compartir;
use App\Livewire\Cotizaciones\Editor;
use App\Livewire\Cotizaciones\EnviarCorreo;
use App\Livewire\Cotizaciones\Revision;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionCambio;
use App\Models\CotizacionComentario;
use App\Models\CotizacionPartida;
use App\Models\User;
use App\Services\CotizacionPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Revisión interna de cotizaciones: pedir revisión por WhatsApp, corregir, comentar y marcar revisada. */
class RevisionCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private function cotizacion(User $vendedor): Cotizacion
    {
        $cliente = Cliente::factory()->create(['nombre' => 'FANOSA']);
        $cot = Cotizacion::factory()->create(['cliente_id' => $cliente->id, 'vendedor_id' => $vendedor->id, 'estado' => 'borrador']);
        CotizacionPartida::factory()->create(['cotizacion_id' => $cot->id, 'orden' => 1, 'descripcion' => 'BROCA COBALTO', 'costo' => 100, 'utilidad_pct' => 25, 'precio' => 125, 'importe' => 125]);
        $cot->recalcularTotales();

        return $cot->fresh();
    }

    public function test_pedir_revision_corregir_comentar_y_marcar_revisada(): void
    {
        $rosa = User::factory()->create(['name' => 'Rosa', 'rol' => 'vendedor']);
        $ernesto = User::factory()->create(['name' => 'Ernesto', 'rol' => 'vendedor']);
        $this->actingAs($rosa);
        $cot = $this->cotizacion($rosa);
        $partida = $cot->partidas()->first();

        // Antes de pedir revisión, los cambios de un borrador no se registran (igual que antes).
        $partida->update(['utilidad_pct' => 26]);
        $this->assertSame(0, CotizacionCambio::count());

        // Rosa pide revisión: se abre WhatsApp con el mensaje para el grupo y queda "En revisión".
        // El total del mensaje se toma al momento de pedirla (aunque la página se haya abierto en $0.00).
        $pagina = Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->assertSee('Pedir revisión (WhatsApp)');
        $cot->forceFill(['total' => 1234.5])->save();
        $url = $pagina->call('pedirRevision')
            ->assertSee('En revisión')
            ->assertDispatched('revision-actualizada')
            ->effects['returns'][0];
        $this->assertStringStartsWith('https://wa.me/?text=', $url);
        $texto = rawurldecode(substr($url, strlen('https://wa.me/?text=')));
        $this->assertStringContainsString('Cliente: FANOSA', $texto);
        $this->assertStringContainsString('Total: $1,234.50', $texto);
        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FFFF}]/u', $texto);   // sin emojis

        // Ernesto corrige directo (utilidad y proveedor) y comenta; todo queda con su nombre.
        $this->actingAs($ernesto);
        Livewire::test(Editor::class, ['cotizacion' => $cot->fresh()])
            ->call('guardarCampos', $partida->id, ['utilidad_pct' => '30', 'descripcion' => 'BROCA DE COBALTO']);
        // Los comentarios ahora van por WhatsApp: la sección de comentarios ya no se muestra.
        $t = Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->assertDontSee('Comentarios de revisión')
            ->set('partidaId', (string) $partida->id)->set('comentario', 'Cámbiala a TRUPER, sale más barato')->call('comentar');

        $cambios = CotizacionCambio::with('user')->orderBy('id')->get();
        $this->assertSame(0, (int) $cot->fresh()->revision);   // la revisión interna no abre Rev. 1
        $textos = $cambios->map(fn ($c) => $c->user?->name.': '.$c->texto())->all();
        $this->assertContains('Rosa: pidió revisión de la cotización', $textos);
        $this->assertContains('Ernesto: Partida 1 (BROCA COBALTO): utilidad % 26 → 30', $textos);
        $this->assertContains('Ernesto: comentó en la partida 1: "Cámbiala a TRUPER, sale más barato"', $textos);

        // Rosa lo resuelve y Ernesto la marca como revisada.
        $this->actingAs($rosa);
        $c = CotizacionComentario::firstOrFail();
        Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->call('resolver', $c->id);
        $this->assertNotNull($c->fresh()->resuelto_at);
        $this->actingAs($ernesto);
        Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->call('marcarRevisada')->assertSee('Revisada por Ernesto');
        $this->assertTrue($cot->fresh()->estaRevisada());
        $this->assertNull($cot->fresh()->avisoSinRevisar());

        // El editor muestra todo en el historial; los comentarios nunca salen en el PDF.
        Livewire::test(Editor::class, ['cotizacion' => $cot->fresh()])->assertSee('marcó la cotización como revisada');
        $this->assertStringNotContainsString('TRUPER', CotizacionPdf::html($cot->fresh()));

        // Si se vuelve a pedir revisión, deja de contar como revisada.
        Livewire::test(Revision::class, ['cotizacionId' => $cot->id])->call('pedirRevision');
        $this->assertFalse($cot->fresh()->estaRevisada());
    }

    public function test_pregunta_antes_de_enviar_sin_revision_pero_es_opcional(): void
    {
        config(['masume.cuentas_correo.ventas.usuario' => 'ventas@ejemplo.com']);
        $rosa = User::factory()->create(['rol' => 'vendedor', 'cuenta_correo' => 'ventas']);
        $this->actingAs($rosa);
        $cot = $this->cotizacion($rosa);

        // Sin revisión: el correo, WhatsApp y la descarga preguntan primero.
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])
            ->assertSee('wire:confirm="¿No la vas a mandar a revisión?', false)
            ->call('abrir')->assertSet('abierto', true);   // es opcional: se puede enviar igual
        Livewire::test(Compartir::class, ['tipo' => 'cotizacion', 'documentoId' => $cot->id])
            ->assertSee('No la vas a mandar a revisión', false);

        // En revisión todavía: otro aviso.
        $cot->forceFill(['revision_pedida_at' => now()])->save();
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->assertSee('está en revisión y todavía nadie la marca como revisada', false);

        // Revisada: ya no pregunta.
        $cot->forceFill(['revisada_at' => now()->addMinute()])->save();
        Livewire::test(EnviarCorreo::class, ['cotizacion' => $cot])->assertDontSee('wire:confirm=', false);
        Livewire::test(Compartir::class, ['tipo' => 'cotizacion', 'documentoId' => $cot->id])->assertDontSee('No la vas a mandar a revisión', false);
    }

    public function test_al_duplicar_no_se_copia_la_revision(): void
    {
        $u = User::factory()->create(['rol' => 'vendedor']);
        $this->actingAs($u);
        $cot = $this->cotizacion($u);
        $cot->forceFill(['revision_pedida_at' => now(), 'revisada_at' => now(), 'revisada_por' => $u->id])->save();

        Livewire::test(Editor::class, ['cotizacion' => $cot])->call('duplicarCotizacion');
        $nueva = Cotizacion::where('id', '!=', $cot->id)->latest('id')->firstOrFail();
        $this->assertNull($nueva->revision_pedida_at);
        $this->assertNull($nueva->revisada_at);
    }
}
