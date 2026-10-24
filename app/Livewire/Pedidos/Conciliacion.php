<?php

namespace App\Livewire\Pedidos;

use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Services\ConciliadorOc;
use App\Support\LecturaOc;
use App\Support\Precios;
use App\Support\ReenvioOrden;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Revisa la OC del cliente contra las partidas del pedido. La IA solo lee el archivo;
 * el emparejamiento y las comparaciones las hace ConciliadorOc. Solo guarda ids en el
 * navegador (nunca precios): el rol campo únicamente ve el resultado, sin dinero.
 */
class Conciliacion extends Component
{
    public const MINUTOS_MAXIMOS = LecturaOc::MINUTOS_MAXIMOS;

    #[Locked]
    public int $pedidoId;

    public string $mensaje = '';

    public string $error = '';

    /** Clave de la fila que se está por ignorar (pide motivo). */
    public string $ignorando = '';

    public string $motivo = '';

    public function mount(int $pedidoId): void
    {
        $this->pedidoId = $pedidoId;
    }

    private function pedido(): Pedido
    {
        return Pedido::with(Pedido::CLIENTE_REGLAS)->findOrFail($this->pedidoId);
    }

    private function esCampo(): bool
    {
        return (bool) auth()->user()?->esCampo();
    }

    private function actual(): ?PedidoConciliacion
    {
        return PedidoConciliacion::where('pedido_id', $this->pedidoId)->where('estado', 'completada')->latest('id')->first();
    }

    private function enProceso(): ?PedidoConciliacion
    {
        return PedidoConciliacion::where('pedido_id', $this->pedidoId)->where('estado', 'procesando')->latest('id')->first();
    }

    private function correr(PedidoConciliacion $c, ?Pedido $pedido = null): array
    {
        $pedido ??= $this->pedido();
        $partidas = $pedido->partidas()->get(['id', 'descripcion', 'modelo', 'cantidad', 'unidad', 'precio']);

        return app(ConciliadorOc::class)->conciliar((array) $c->extraccion, $partidas, $pedido->numero_oc_cliente, (array) $c->resoluciones);
    }

    /** Vuelve a conciliar con las partidas actuales y guarda el resultado. */
    private function guardarResultado(PedidoConciliacion $c): array
    {
        $r = $this->correr($c);
        $c->update(['resultado' => $r, 'resumen' => $r['resumen'], 'hay_diferencias' => $r['hay_diferencias']]);

        return $r;
    }

    // ------------------------------------------------------------------ revisar

    public function revisar(): void
    {
        abort_if($this->esCampo(), 403);
        $this->mensaje = $this->error = '';

        $pedido = $this->pedido();
        $disco = Storage::disk('local');
        if (! $pedido->oc_cliente_path || ! $disco->exists($pedido->oc_cliente_path)) {
            $this->error = 'Este pedido no tiene archivo de OC para revisar.';

            return;
        }
        if ($this->enProceso()) {
            $this->error = 'Ya hay una revisión en curso; espera a que termine.';

            return;
        }

        $ruta = $disco->path($pedido->oc_cliente_path);
        $hash = hash_file('sha256', $ruta);

        // Mismo archivo ya leído: no se paga otra vez; solo se re-concilia (las partidas pudieron cambiar).
        $previa = PedidoConciliacion::where('pedido_id', $pedido->id)->where('archivo_hash', $hash)
            ->where('estado', 'completada')->whereNotNull('extraccion')->latest('id')->first();
        if ($previa) {
            $nueva = PedidoConciliacion::create([
                'pedido_id' => $pedido->id,
                'user_id' => auth()->id(),
                'archivo_hash' => $hash,
                'proveedor_ia' => $previa->proveedor_ia,
                'modelo' => $previa->modelo,
                'response_id' => $previa->response_id,
                'estado' => 'completada',
                'extraccion' => $previa->extraccion,
                'resoluciones' => $previa->resoluciones,
                'duracion_seg' => 0,
            ]);
            $this->guardarResultado($nueva);
            $this->mensaje = 'La OC no cambió desde la última lectura: se comparó de nuevo con las partidas actuales, sin costo.';

            return;
        }

        $mime = LecturaOc::mime($ruta);
        if (! $mime) {
            $this->error = 'El archivo de la OC no es PDF ni imagen (JPG, PNG, WEBP); no se puede leer con IA.';

            return;
        }
        $modelo = LecturaOc::modelo($mime);
        if ($aviso = LecturaOc::sinPresupuesto($modelo)) {
            $this->error = $aviso;

            return;
        }

        try {
            $c = LecturaOc::reservar([
                'pedido_id' => $pedido->id,
                'user_id' => auth()->id(),
                'archivo_hash' => $hash,
                'proveedor_ia' => 'openai',
                'modelo' => $modelo,
                'estado' => 'procesando',
            ]);
        } catch (\DomainException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $contexto = 'Cliente capturado por el vendedor: '.($pedido->cliente->nombre ?? 'desconocido').'. '
            .'Número de OC capturado: '.($pedido->numero_oc_cliente ?: 'no capturado').'. '
            .'Extrae los renglones del documento adjunto.';
        LecturaOc::iniciar($c, $ruta, $mime, $contexto);
    }

    // ------------------------------------------------------------------ sondeo

    public function consultar(): void
    {
        $c = $this->enProceso();
        if (! $c) {
            return;
        }
        if (LecturaOc::consultar($c)) {
            try {
                $this->guardarResultado($c);
                $this->mensaje = 'Revisión terminada.';
            } catch (Throwable $e) {
                LecturaOc::fallarConExcepcion($c, $e);
            }
        }
    }

    // ------------------------------------------------------------------ acciones por fila

    private function filaEditable(string $clave, ?PedidoConciliacion &$c = null): ?array
    {
        abort_if($this->esCampo(), 403);
        $c = $this->actual();
        if (! $c) {
            return null;
        }
        foreach ($this->correr($c)['filas'] as $f) {
            if ($f['clave'] === $clave) {
                return $f;
            }
        }
        $this->error = 'Esa fila ya no existe; la lista se actualizó.';

        return null;
    }

    public function usarCantidad(string $clave): void
    {
        $this->mensaje = $this->error = '';
        $f = $this->filaEditable($clave, $c);
        if (! $f || ! $f['renglon'] || ! $f['partida'] || ! in_array('cantidad_distinta', $f['diferencias'], true)) {
            return;
        }

        $pedido = $this->pedido();
        $partida = $pedido->partidas()->find($f['partida']['id']);
        if (! $partida) {
            return;
        }
        $partida->update(['cantidad' => $f['renglon']['cantidad']]);
        $pedido->recalcularTotales();
        $this->guardarResultado($c);
        $this->mensaje = 'Cantidad actualizada a '.rtrim(rtrim(number_format($f['renglon']['cantidad'], 3, '.', ','), '0'), '.').' en la partida del pedido.';
        if ($aviso = ReenvioOrden::alCambiarPartida($partida)) {
            $this->mensaje .= ' '.$aviso;
        }
        $this->dispatch('partidas-actualizadas');
    }

    public function agregarAlPedido(string $clave): void
    {
        $this->mensaje = $this->error = '';
        $f = $this->filaEditable($clave, $c);
        if (! $f || ! $f['renglon'] || $f['partida'] || ! in_array('falta_en_pedido', $f['diferencias'], true)) {
            return;
        }

        $r = $f['renglon'];
        $pedido = $this->pedido();

        // La partida guarda precio SIN IVA; si la OC trae precios con IVA se le quita.
        $precio = (float) ($r['precio_unitario'] ?? 0);
        if ($precio > 0 && ($c->resultado['incluyen_iva'] ?? false)) {
            $precio = round($precio / (1 + Precios::iva()), 2);
        }

        PedidoPartida::create([
            'pedido_id' => $pedido->id,
            'orden' => ((int) $pedido->partidas()->max('orden')) + 1,
            'descripcion' => mb_strtoupper(trim($r['descripcion'])),
            'modelo' => filled($r['modelo']) ? $r['modelo'] : null,
            'marca' => filled($r['marca']) ? $r['marca'] : null,
            'unidad' => mb_substr(mb_strtoupper(trim((string) ($r['unidad'] ?: 'PZA'))), 0, 10),
            'cantidad' => $r['cantidad'],
            'costo' => null,
            'precio' => $precio,
        ]);
        $pedido->recalcularTotales();
        $this->guardarResultado($c);
        $this->mensaje = 'Partida agregada al pedido. Captura su proveedor y costo antes de generar la OC.';
        $this->dispatch('partidas-actualizadas');
    }

    /**
     * Cuando la IA no ve que un renglón de la OC es una partida del pedido (otro código del cliente, otra forma de
     * describirlo), una persona lo decide: "Es la misma que…". Queda registrado quién y cuándo; se puede deshacer.
     */
    public function emparejarAMano(string $clave, int $partidaId): void
    {
        $this->mensaje = $this->error = '';
        $f = $this->filaEditable($clave, $c);
        if (! $f || ! $f['renglon'] || $f['partida'] || ! str_starts_with($clave, 'r')) {
            return;
        }
        $libre = collect($this->correr($c)['filas'])->first(fn ($x) => ! $x['renglon'] && ($x['partida']['id'] ?? null) === $partidaId);
        if (! $libre) {
            $this->error = 'Esa partida ya está emparejada con otro renglón de la OC.';

            return;
        }
        $resoluciones = (array) $c->resoluciones;
        $resoluciones['_parejas'][(int) substr($clave, 1)] = [
            'partida_id' => $partidaId, 'user_id' => auth()->id(), 'usuario' => auth()->user()->name, 'fecha' => now()->toIso8601String(),
        ];
        unset($resoluciones[$clave], $resoluciones['p'.$partidaId]);
        $c->update(['resoluciones' => $resoluciones]);
        $this->guardarResultado($c);
        $this->mensaje = 'Listo: se emparejó a mano. Si cantidad o precio no cuadran, se sigue marcando.';
    }

    public function deshacerPareja(string $clave): void
    {
        $this->mensaje = $this->error = '';
        abort_if($this->esCampo(), 403);
        $c = $this->actual();
        $i = (int) substr($clave, 1);
        if (! $c || ! isset(((array) $c->resoluciones)['_parejas'][$i])) {
            return;
        }
        $resoluciones = (array) $c->resoluciones;
        unset($resoluciones['_parejas'][$i]);
        $c->update(['resoluciones' => $resoluciones]);
        $this->guardarResultado($c);
        $this->mensaje = 'Se deshizo la pareja.';
    }

    public function pedirIgnorar(string $clave): void
    {
        abort_if($this->esCampo(), 403);
        $this->ignorando = $clave;
        $this->motivo = '';
        $this->resetErrorBag();
    }

    public function cancelarIgnorar(): void
    {
        $this->ignorando = '';
        $this->motivo = '';
        $this->resetErrorBag();
    }

    public function ignorar(): void
    {
        $this->mensaje = $this->error = '';
        $this->validate(
            ['motivo' => ['required', 'string', 'min:3', 'max:500']],
            ['motivo.required' => 'Escribe el motivo para ignorar esta diferencia.', 'motivo.min' => 'El motivo es muy corto.']
        );

        $f = $this->filaEditable($this->ignorando, $c);
        if (! $f || $f['estado'] === 'coincide') {
            $this->cancelarIgnorar();

            return;
        }

        $resoluciones = (array) $c->resoluciones;
        $resoluciones[$f['clave']] = [
            'accion' => 'ignorada',
            'motivo' => trim($this->motivo),
            'user_id' => auth()->id(),
            'usuario' => auth()->user()->name,
            'fecha' => now()->toIso8601String(),
        ];
        $c->update(['resoluciones' => $resoluciones]);
        $this->guardarResultado($c);
        $this->cancelarIgnorar();
        $this->mensaje = 'Diferencia ignorada; quedó registrado quién y por qué.';
    }

    // ------------------------------------------------------------------ vista

    public function render()
    {
        $pedido = $this->pedido();
        $actual = $this->actual();
        $enProceso = $this->enProceso();
        $ultimoError = ! $enProceso
            ? PedidoConciliacion::where('pedido_id', $pedido->id)->latest('id')->first()
            : null;
        $ultimoError = $ultimoError && $ultimoError->estado === 'error' ? $ultimoError : null;

        return view('livewire.pedidos.conciliacion', [
            'pedido' => $pedido,
            'esCampo' => $this->esCampo(),
            'tieneArchivo' => (bool) $pedido->oc_cliente_path,
            'actual' => $actual,
            'resultado' => $actual ? $this->correr($actual, $pedido) : null,
            'enProceso' => $enProceso,
            'ultimoError' => $ultimoError,
            'historial' => PedidoConciliacion::with('user:id,name')->where('pedido_id', $pedido->id)->latest('id')->limit(10)->get(),
        ]);
    }
}
