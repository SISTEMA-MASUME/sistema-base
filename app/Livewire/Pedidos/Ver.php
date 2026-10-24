<?php

namespace App\Livewire\Pedidos;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoPartidaPedido;
use App\Models\CambioPrecio;
use App\Models\ClienteSucursal;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoArchivo;
use App\Models\PedidoComentario;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\AltaSicarPartidas;
use App\Support\CostoOrden;
use App\Support\DocumentoPropio;
use App\Support\LigasPorConfirmar;
use App\Support\Nombres;
use App\Support\PorRecoger;
use App\Support\ProductosCotizados;
use App\Support\ReenvioOrden;
use App\Support\Telefono;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Detalle del pedido con la distribución de una tarjeta de Trello. Se usa como página completa
 * (/pedidos/{id}) y dentro de la ventana flotante del tablero ($enModal). Solo guarda el id
 * (no el modelo) para que los totales de costo nunca viajen al navegador del rol campo.
 */
#[Layout('layouts.app')]
#[Title('Pedido')]
class Ver extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $pedidoId;

    /** OC del cliente subida o reemplazada desde el detalle (arrastrar, pegar o elegir). */
    public $archivoOc = null;

    /** IDs de partidas marcadas para la acción masiva. */
    public array $seleccion = [];

    public string $notas = '';

    public string $mensaje = '';

    public bool $agregando = false;

    public string $n_descripcion = '';

    public string $n_cantidad = '1';

    public string $n_unidad = 'PZA';

    public string $n_modelo = '';

    public string $n_precio = '';

    public string $n_costo = '';

    public string $n_proveedor = '';

    /** Partida que se está editando en línea (null = ninguna). */
    public ?int $editandoId = null;

    public string $e_descripcion = '';

    public string $e_cantidad = '1';

    public string $e_unidad = 'PZA';

    public string $e_modelo = '';

    public string $e_costo = '';

    public string $e_proveedor = '';

    /** Productos ya cotizados parecidos a lo que se escribe al editar la partida (solo quien ve venta/costos). */
    public array $eSugerencias = [];

    /** Cotización de donde se tomó el producto sugerido (para la bitácora). */
    public ?string $eOrigen = null;

    /** Buscador de partidas cotizadas para agregar al pedido. */
    public bool $buscandoCot = false;

    public string $cotBusca = '';

    public bool $cotTodas = false;

    /** IDs de partidas de cotización marcadas y la cantidad de cada una (id => cantidad). */
    public array $cotSel = [];

    public array $cotCant = [];

    public function updatedArchivoOc(): void
    {
        abort_if(auth()->user()->esCampo(), 403);

        $this->validate(
            ['archivoOc' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480']],
            [
                'archivoOc.mimes' => 'La OC debe ser PDF, JPG, PNG o WEBP.',
                'archivoOc.max' => 'El archivo pesa más de 20 MB.',
                'archivoOc.file' => 'Sube un archivo PDF o una imagen.',
            ],
        );

        if ($motivo = DocumentoPropio::motivoNoEsOc((string) file_get_contents($this->archivoOc->getRealPath()), $this->archivoOc->getClientOriginalName())) {
            $this->archivoOc = null;
            $this->addError('archivoOc', $motivo);

            return;
        }

        $pedido = Pedido::findOrFail($this->pedidoId);
        $anterior = $pedido->oc_cliente_path;
        $pedido->update(['oc_cliente_path' => $this->archivoOc->store('pedidos/oc-cliente', 'local')]);
        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }

        $this->archivoOc = null;
        $this->mensaje = $anterior ? 'Se reemplazó la OC del cliente.' : 'Se cargó la OC del cliente.';
        $this->actividad('archivo', $anterior ? 'reemplazó el archivo de la OC del cliente' : 'subió el archivo de la OC del cliente');
        $this->dispatch('oc-cliente-actualizada');
    }

    /** El hijo de reglas del cliente (plaza, evidencia) cambió datos que afectan las etiquetas. */
    #[On('reglas-actualizadas')]
    public function refrescarReglas(): void
    {
        // Solo vuelve a renderizar.
    }

    /** El expediente reemplazó o quitó la OC del cliente: vuelve a pintar la zona de la OC y la conciliación. */
    #[On('oc-cliente-actualizada')]
    #[On('facturas-actualizadas')]
    public function refrescarOc(): void
    {
        // Solo vuelve a renderizar.
    }

    /** El hijo (conciliación) cambió partidas: basta con volver a renderizar para leerlas de la BD. */
    #[On('partidas-actualizadas')]
    public function refrescarPartidas(): void
    {
        $this->seleccion = [];
    }

    public string $numeroOc = '';

    /** Abierto como ventana flotante sobre el tablero. */
    public bool $enModal = false;

    public string $titulo = '';

    public string $fechaCompromiso = '';

    public string $comentario = '';

    /** Recién se marcó una partida como entregada y el pedido no tiene foto de entrega: invitación (no obligatoria) a tomarla. */
    public bool $pedirFoto = false;

    /** Partidas en la ventanita "¿Cuántas recogiste?" (recogidas parciales). */
    public array $recogiendoIds = [];

    /** Folio (de factura o de nota) que se escribe en "Agregar factura o nota". */
    public function mount(Pedido $pedido, bool $enModal = false): void
    {
        $this->pedidoId = $pedido->id;
        $this->enModal = $enModal;
        $this->notas = (string) $pedido->notas;
        $this->titulo = $pedido->titulo();
        $this->fechaCompromiso = (string) $pedido->fecha_compromiso?->toDateString();
    }

    private function actividad(string $tipo, string $descripcion): void
    {
        PedidoActividad::registrar($this->pedidoId, $tipo, $descripcion);
        // El tablero (si la tarjeta está abierta en la ventana flotante) vuelve a pintar la tarjeta.
        $this->dispatch('pedido-actualizado');
    }

    public function cerrar(): void
    {
        $this->dispatch('cerrar-tarjeta');
    }

    public function guardarTitulo(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->validate(['titulo' => ['nullable', 'string', 'max:255']], ['titulo.max' => 'El título es muy largo.']);

        $pedido = $this->pedido()->load('cliente:id,nombre', 'responsable:id,iniciales');
        $nuevo = trim(preg_replace('/\s+/', ' ', $this->titulo));
        $antes = $pedido->titulo();
        // Si queda vacío o igual al automático se vuelve a armar solo (cliente - OC - iniciales).
        $pedido->titulo_personalizado = null;
        $automatico = $pedido->titulo();
        $pedido->titulo_personalizado = $nuevo !== '' && $nuevo !== $automatico ? $nuevo : null;
        $pedido->save();

        $this->titulo = $pedido->titulo();
        if ($this->titulo !== $antes) {
            $this->actividad('titulo', 'cambió el título a "'.$this->titulo.'"');
        }
    }

    public function guardarFechaCompromiso(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->validate(['fechaCompromiso' => ['nullable', 'date']], ['fechaCompromiso.date' => 'Fecha no válida.']);

        $pedido = $this->pedido();
        $pedido->update(['fecha_compromiso' => $this->fechaCompromiso !== '' ? $this->fechaCompromiso : null]);
        $this->actividad('fecha', $pedido->fecha_compromiso
            ? 'puso la fecha compromiso '.$pedido->fecha_compromiso->format('d/m/Y')
            : 'quitó la fecha compromiso');
    }

    public function cambiarResponsable(string $userId): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $user = User::where('activo', true)->find((int) $userId);
        if (! $user) {
            return;
        }
        $pedido = $this->pedido();
        if ($pedido->responsable_id === $user->id) {
            return;
        }
        $pedido->update(['responsable_id' => $user->id]);
        $this->titulo = $pedido->load('cliente:id,nombre', 'responsable:id,iniciales')->titulo();
        $this->actividad('responsable', 'asignó a '.$user->name.' como responsable');
    }

    public function comentar(): void
    {
        $this->validate(['comentario' => ['required', 'string', 'max:5000']], [
            'comentario.required' => 'Escribe el comentario.',
            'comentario.max' => 'El comentario es muy largo.',
        ]);
        PedidoComentario::create(['pedido_id' => $this->pedidoId, 'user_id' => auth()->id(), 'texto' => trim($this->comentario)]);
        $this->comentario = '';
        $this->dispatch('pedido-actualizado');
    }

    public function borrarComentario(int $id): void
    {
        $c = PedidoComentario::where('pedido_id', $this->pedidoId)->findOrFail($id);
        abort_unless(auth()->user()->esAdmin() || $c->user_id === auth()->id(), 403);
        $c->delete();
        $this->dispatch('pedido-actualizado');
    }

    /** Clic en el chip de estado de una partida: pasa al siguiente estado permitido (como un checklist). */
    public function siguienteEstado(int $partidaId): void
    {
        $p = $this->pedido()->partidas()->findOrFail($partidaId);
        $permitidos = $this->estadosPermitidos();
        $i = array_search($p->estado, $permitidos, true);
        $nuevo = $permitidos[$i === false ? 0 : ($i + 1) % count($permitidos)];
        $this->establecerEstado($partidaId, $nuevo->value);
    }

    private function pedido(): Pedido
    {
        return Pedido::findOrFail($this->pedidoId);
    }

    private function verCostos(): bool
    {
        return auth()->user()->puedeVerCostos();
    }

    private function verVenta(): bool
    {
        return auth()->user()->puedeVerVenta();
    }

    /** Al poner un estado a mano: recogida o entregada = todo recogido; antes de eso se borra lo registrado en partes. */
    private static function recogidaPara(EstadoPartidaPedido $estado, float $cantidad): ?float
    {
        return $estado->nivel() >= EstadoPartidaPedido::Recibida->nivel() ? $cantidad : null;
    }

    /** Abre "¿Cuántas recogiste?" para una partida que aún no se recoge completa. */
    public function abrirRecoger(int $partidaId): void
    {
        $this->abrirRecogerVarias([$partidaId]);
    }

    /**
     * "¿Cuántas recogiste?" para varias partidas a la vez. Es lo que hacen el estado de la partida y "Marcar recogida" con
     * casillas: los dos preguntan cuántas (por si el proveedor entregó incompleto). Las ya completas se ignoran.
     *
     * @param  list<int|string>  $ids
     */
    private function abrirRecogerVarias(array $ids): void
    {
        abort_unless(in_array(EstadoPartidaPedido::Recibida, $this->estadosPermitidos(), true), 403);
        $this->recogiendoIds = $this->pedido()->partidas()->whereIn('id', array_map('intval', $ids))->orderBy('orden')->get()
            ->filter(fn ($p) => $p->faltaRecoger() > 0)->pluck('id')->values()->all();
        if ($this->recogiendoIds === []) {
            $this->mensaje = 'Esas partidas ya estaban recogidas completas.';

            return;
        }
        // Piezas sueltas (1 de 1): no hay nada que preguntar, se marcan recogidas de una vez.
        $pendientes = $this->pedido()->partidas()->whereIn('id', $this->recogiendoIds)->get();
        if ($pendientes->every(fn ($p) => (float) $p->cantidad <= 1)) {
            $this->registrarRecogidas($pendientes->mapWithKeys(fn ($p) => [$p->id => $p->faltaRecoger()])->all());
        }
    }

    public function cerrarRecoger(): void
    {
        $this->recogiendoIds = [];
    }

    /** Se recogió $cantidad de una partida (p. ej. "Recogí lo que faltaba"). */
    public function registrarRecogida(int $partidaId, mixed $cantidad): void
    {
        $this->registrarRecogidas([$partidaId => $cantidad]);
    }

    /**
     * Lo recogido en este viaje, por partida (id => cantidad; 0 o vacío = de esa no se recogió nada). La que se completa pasa
     * a "Recogida"; la que no, queda "Pedida al proveedor" con lo recogido anotado y el pedido muestra "Falta recoger".
     * Lo usan campo, vendedores y admin.
     *
     * @param  array<int|string, mixed>  $cantidades
     */
    public function registrarRecogidas(array $cantidades): void
    {
        abort_unless(in_array(EstadoPartidaPedido::Recibida, $this->estadosPermitidos(), true), 403);
        $this->resetErrorBag('recoger');
        $limpias = [];
        foreach (array_slice($cantidades, 0, 200, true) as $id => $cantidad) {
            $c = is_numeric($cantidad) ? round((float) $cantidad, 3) : 0.0;
            if ($c > 0 && (int) $id > 0) {
                $limpias[(int) $id] = $c;
            }
        }
        if ($limpias === []) {
            $this->addError('recoger', 'Escribe cuántas recogiste (más de 0).');

            return;
        }

        $mensajes = DB::transaction(function () use ($limpias) {
            $partidas = PedidoPartida::where('pedido_id', $this->pedidoId)->whereIn('id', array_keys($limpias))->lockForUpdate()->get()->keyBy('id');
            $out = [];
            foreach ($limpias as $id => $c) {
                $p = $partidas->get($id);
                abort_unless($p, 404);
                $out[] = PorRecoger::anotar($p, $c);
            }

            return $out;
        });

        $this->recogiendoIds = [];
        $this->seleccion = [];
        $this->mensaje = count($mensajes) === 1 ? $mensajes[0] : 'Listo: '.count($mensajes).' partidas anotadas. '.implode(' ', $mensajes);
        $this->dispatch('pedido-actualizado');
    }

    /** Estados que puede poner el usuario: el personal de campo solo recibe y entrega. */
    private function estadosPermitidos(): array
    {
        return auth()->user()->esCampo()
            ? [EstadoPartidaPedido::Recibida, EstadoPartidaPedido::Entregada]
            : EstadoPartidaPedido::cases();
    }

    public function mover(string $columna): void
    {
        $col = ColumnaPedido::tryFrom($columna);
        $pedido = $this->pedido();
        if (! $col || $pedido->columna === $col) {
            return;
        }
        Pedido::where('columna', $col->value)->increment('posicion');
        $pedido->update(['columna' => $col, 'posicion' => 0]);
        $this->mensaje = 'Movido a '.$col->etiqueta().'.';
        $this->actividad('mover', 'movió la tarjeta a "'.$col->etiqueta().'"');
    }

    /** El cliente mandó su OC después de surtir (nota de venta): se captura aquí. */
    public function guardarNumeroOc(): void
    {
        abort_if(auth()->user()->esCampo(), 403);

        $numero = trim($this->numeroOc);
        $this->validate(['numeroOc' => ['required', 'string', 'max:80',
            fn ($a, $v, $falla) => Pedido::esFolioDeCotizacion($v) ? $falla('Eso es el folio de una cotización, no la OC del cliente. Escribe el número de la OC que mandó el cliente.') : null]],
            ['numeroOc.required' => 'Escribe el número de OC.']);

        $pedido = $this->pedido();
        $llave = Nombres::llave(preg_replace('/^\s*(OC|O\.C\.|PO)\s*[:#-]?\s*/i', '', $numero));
        $otro = Pedido::withTrashed()->where('cliente_id', $pedido->cliente_id)
            ->where('numero_oc_llave', $llave)->where('id', '!=', $pedido->id)->first();
        if ($otro) {
            $this->addError('numeroOc', "Esa OC ya está en el pedido P-{$otro->folio} de este cliente.");

            return;
        }

        $pedido->update(['numero_oc_cliente' => $numero]);
        $this->numeroOc = '';
        $this->titulo = $pedido->load('cliente:id,nombre', 'responsable:id,iniciales')->titulo();
        $this->actividad('oc', 'registró la OC '.$numero.' del cliente');
        $this->mensaje = "Se registró la OC {$numero} del cliente.";
    }

    /** Se subió la factura al portal de proveedores del cliente (clientes que generan su OC después). */
    public function marcarFacturaPortal(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $pedido = $this->pedido();
        abort_unless($pedido->clienteSubeFacturaPortal(), 422);

        $pedido->update(['factura_portal_at' => now(), 'factura_portal_por' => auth()->id()]);
        $this->mensaje = 'Se registró que la factura ya está en el portal del cliente.';
        $this->actividad('portal', 'marcó la factura como subida al portal del cliente');
    }

    public function deshacerFacturaPortal(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->pedido()->update(['factura_portal_at' => null, 'factura_portal_por' => null]);
        $this->mensaje = 'Se quitó la marca de factura subida al portal.';
        $this->actividad('portal', 'quitó la marca de factura subida al portal');
    }

    public function alternarBandera(string $campo): void
    {
        abort_unless(in_array($campo, ['alta_sicar', 'revisar', 'modificar_oc'], true), 422);
        $pedido = $this->pedido();
        $pedido->update([$campo => ! $pedido->$campo]);
        if ($campo === 'alta_sicar' && $pedido->alta_sicar) {
            AltaSicarPartidas::marcar(AltaSicarPartidas::faltantes($pedido));
        }
        $texto = ['alta_sicar' => 'Mat. dado de alta', 'revisar' => 'Revisar / dar seguimiento', 'modificar_oc' => 'Se envía a modificar OC'][$campo];
        $this->actividad('etiqueta', ($pedido->$campo ? 'puso' : 'quitó').' la etiqueta "'.$texto.'"');
    }

    public function alternarCancelado(): void
    {
        $pedido = $this->pedido();
        $pedido->update(['cancelado_at' => $pedido->cancelado_at ? null : now()]);
        $this->mensaje = $pedido->cancelado_at ? 'Pedido marcado como cancelado.' : 'Se quitó la cancelación.';
        $this->actividad('cancelado', $pedido->cancelado_at ? 'marcó el pedido como cancelado por el cliente' : 'quitó la cancelación');
    }

    public function archivar(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->pedido()->update(['archivado_at' => now(), 'archivado_por' => auth()->id()]);
        $this->mensaje = 'Pedido archivado. Ya no aparece en el tablero; lo encuentras en "Ver archivados".';
        $this->actividad('archivar', 'archivó la tarjeta');
    }

    public function desarchivar(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->pedido()->update(['archivado_at' => null, 'archivado_por' => null]);
        $this->mensaje = 'Pedido desarchivado: volvió al tablero.';
        $this->actividad('archivar', 'desarchivó la tarjeta');
    }

    public function establecerEstado(int $partidaId, string $estado): void
    {
        $nuevo = EstadoPartidaPedido::tryFrom($estado);
        abort_unless($nuevo && in_array($nuevo, $this->estadosPermitidos(), true), 403);

        $p = $this->pedido()->partidas()->findOrFail($partidaId);
        if ($p->estado === $nuevo) {
            return;
        }
        $p->update(['estado' => $nuevo, 'cantidad_recogida' => self::recogidaPara($nuevo, (float) $p->cantidad)]);
        $this->actividad('estado', 'marcó "'.Str::limit($p->descripcion, 60).'" como '.mb_strtolower($nuevo->etiqueta()));
        $this->invitarFoto($nuevo);
    }

    /** Al entregar sin foto de entrega en el pedido, se muestra el recordatorio (se puede cerrar: no es obligatorio). */
    private function invitarFoto(EstadoPartidaPedido $nuevo): void
    {
        if ($nuevo === EstadoPartidaPedido::Entregada && ! PedidoArchivo::where('pedido_id', $this->pedidoId)->where('tipo', 'evidencia')->exists()) {
            $this->pedirFoto = true;
        }
    }

    public function cerrarAvisoFoto(): void
    {
        $this->pedirFoto = false;
    }

    /** Fecha estimada de entrega de una partida (como en Trello). El personal de campo solo la ve. */
    public function guardarFechaEntrega(int $partidaId, string $fecha = ''): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $fecha = trim($fecha);
        if ($fecha !== '' && (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
            $this->addError('fechaEntrega.'.$partidaId, 'Elige una fecha válida.');

            return;
        }
        $p = $this->pedido()->partidas()->findOrFail($partidaId);
        $antes = $p->fecha_entrega?->format('Y-m-d');
        if ($antes === ($fecha !== '' ? $fecha : null)) {
            return;
        }
        $p->update(['fecha_entrega' => $fecha !== '' ? $fecha : null]);
        $this->resetErrorBag('fechaEntrega.'.$partidaId);
        $nombre = '"'.Str::limit($p->descripcion, 60).'"';
        $this->actividad('fecha_entrega', $fecha !== ''
            ? 'puso la fecha estimada de entrega de '.$nombre.': '.Carbon::parse($fecha)->format('d/m/Y')
            : 'quitó la fecha estimada de entrega de '.$nombre);
        $this->mensaje = $fecha !== '' ? 'Fecha estimada guardada.' : 'Fecha estimada quitada.';
    }

    public function alternarFacturada(int $partidaId): void
    {
        abort_unless($this->verVenta(), 403);

        $p = $this->pedido()->partidas()->findOrFail($partidaId);
        $p->update(['facturada' => ! $p->facturada]);
        $this->actividad('facturada', ($p->facturada ? 'marcó como facturada' : 'quitó facturada a').' "'.Str::limit($p->descripcion, 60).'"');
    }

    public function accionMasiva(string $accion): void
    {
        $ids = array_map('intval', $this->seleccion);
        if (! $ids) {
            $this->mensaje = 'Marca primero las partidas.';

            return;
        }
        $partidas = $this->pedido()->partidas()->whereIn('id', $ids);

        // "Marcar recogida" pregunta cuántas, igual que el estado de cada partida (el proveedor a veces entrega incompleto).
        if ($accion === EstadoPartidaPedido::Recibida->value) {
            $this->abrirRecogerVarias($ids);

            return;
        }
        if ($accion === 'facturada') {
            abort_unless($this->verVenta(), 403);
            $n = $partidas->update(['facturada' => true]);
            $this->actividad('facturada', 'marcó '.$n.($n === 1 ? ' partida' : ' partidas').' como facturadas');
        } else {
            $estado = EstadoPartidaPedido::tryFrom($accion);
            abort_unless($estado && in_array($estado, $this->estadosPermitidos(), true), 403);
            $n = $partidas->update(['estado' => $estado, 'cantidad_recogida' => $estado->nivel() >= EstadoPartidaPedido::Recibida->nivel() ? DB::raw('cantidad') : null]);
            $this->actividad('estado', 'marcó '.$n.($n === 1 ? ' partida' : ' partidas').' como '.mb_strtolower($estado->etiqueta()));
            $this->invitarFoto($estado);
        }
        $this->seleccion = [];
        $this->mensaje = 'Partidas actualizadas.';
    }

    /** Solo quien tiene el permiso 'ventas' y puede ver importes de venta (el rol campo no) maneja las facturas del pedido. */
    private function puedeFacturas(): bool
    {
        $u = auth()->user();

        return $u->puede('ventas') && $u->puedeVerVenta();
    }

    public function guardarNotas(): void
    {
        $this->validate(['notas' => ['nullable', 'string', 'max:5000']]);
        $this->pedido()->update(['notas' => trim($this->notas) !== '' ? trim($this->notas) : null]);
        $this->mensaje = 'Notas guardadas.';
        $this->actividad('notas', 'editó la descripción');
    }

    /** Busca un proveedor que ya existe por su nombre (no crea uno nuevo). Devuelve false si no existe. */
    private function proveedorPorNombre(string $nombre): int|false|null
    {
        $nombre = Nombres::limpiar($nombre);
        $llave = Nombres::llave($nombre);
        if ($llave === '') {
            return null;
        }

        return Proveedor::where('nombre_llave', $llave)->value('id') ?? false;
    }

    /** Aviso de reenvío con el folio de la OC, o null si la partida no está en una OC ya enviada. */
    private function avisoReenvio(PedidoPartida $partida): ?string
    {
        $ordenId = $partida->orden_proveedor_id;
        if (! ReenvioOrden::alCambiarPartida($partida) || ! $ordenId) {
            return null;
        }

        return 'La '.OrdenProveedor::find($ordenId)?->folioTexto().' hay que reenviarla al proveedor.';
    }

    private function cantidadTexto(mixed $c): string
    {
        return rtrim(rtrim(number_format((float) $c, 3, '.', ','), '0'), '.');
    }

    public function editarPartida(int $partidaId): void
    {
        abort_unless($this->verVenta(), 403);
        $p = $this->pedido()->partidas()->with('proveedor:id,nombre')->findOrFail($partidaId);

        $this->resetErrorBag();
        $this->editandoId = $p->id;
        $this->e_descripcion = (string) $p->descripcion;
        $this->e_cantidad = $this->cantidadTexto($p->cantidad);
        $this->e_unidad = (string) $p->unidad;
        $this->e_modelo = (string) $p->modelo;
        $this->e_costo = $p->costo !== null ? (string) (float) $p->costo : '';
        $this->e_proveedor = (string) ($p->proveedor->nombre ?? '');
        $this->eSugerencias = [];
        $this->eOrigen = null;
    }

    /** Al escribir otra descripción (o modelo) sugiere productos ya cotizados; primero los de este cliente. */
    public function updatedEDescripcion(): void
    {
        $this->sugerirProducto($this->e_descripcion);
    }

    public function updatedEModelo(): void
    {
        $this->sugerirProducto($this->e_modelo);
    }

    private function sugerirProducto(string $texto): void
    {
        if (! $this->editandoId || ! $this->verVenta()) {
            return;
        }
        $texto = trim($texto);
        $this->eSugerencias = mb_strlen($texto) < 4 ? [] : array_map(fn ($r) => array_intersect_key($r, array_flip([
            'descripcion', 'modelo', 'unidad', 'costo', 'proveedor', 'folio', 'fecha', 'cliente',
        ])), ProductosCotizados::buscar($texto, $texto, 5, $this->pedido()->cliente_id));
    }

    /** "Usar este": llena la partida con el producto ya cotizado (cantidad y precio de venta no cambian). */
    public function usarSugerencia(int $i): void
    {
        abort_unless($this->verVenta(), 403);
        $r = $this->eSugerencias[$i] ?? null;
        if (! $r || ! $this->editandoId) {
            return;
        }
        $p = $this->pedido()->partidas()->findOrFail($this->editandoId);
        $this->e_descripcion = $r['descripcion'];
        $this->e_modelo = (string) $r['modelo'];
        if ($r['unidad'] !== '') {
            $this->e_unidad = $r['unidad'];
        }
        if ($r['costo'] !== null) {
            $this->e_costo = (string) (float) $r['costo'];
        }
        if ($r['proveedor'] !== '' && ! $p->orden_proveedor_id) {
            $this->e_proveedor = $r['proveedor'];
        }
        $this->eOrigen = 'cotización '.$r['folio'].($r['cliente'] !== '' ? ' de '.$r['cliente'] : '');
        $this->eSugerencias = [];
    }

    public function cancelarEdicion(): void
    {
        $this->editandoId = null;
        $this->eSugerencias = [];
        $this->eOrigen = null;
        $this->resetErrorBag();
    }

    public function guardarPartida(): void
    {
        abort_unless($this->verVenta(), 403);
        $p = $this->pedido()->partidas()->with('proveedor:id,nombre')->findOrFail((int) $this->editandoId);

        $d = $this->validate([
            'e_descripcion' => ['required', 'string', 'max:2000'],
            'e_cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'e_unidad' => ['required', 'string', 'max:10'],
            'e_modelo' => ['nullable', 'string', 'max:255'],
            'e_costo' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'e_proveedor' => ['nullable', 'string', 'max:255'],
        ], [
            'e_descripcion.required' => 'Escribe la descripción.',
            'e_cantidad.gt' => 'La cantidad debe ser mayor a 0.',
            'e_cantidad.required' => 'Escribe la cantidad.',
            'e_cantidad.numeric' => 'La cantidad debe ser un número.',
            'e_costo.numeric' => 'El costo debe ser un número.',
        ]);

        $provId = $this->proveedorPorNombre($d['e_proveedor'] ?? '');
        if ($provId === false) {
            $this->addError('e_proveedor', 'No encontré ese proveedor. Elige uno de la lista.');

            return;
        }
        if ($p->orden_proveedor_id && $provId !== $p->proveedor_id) {
            $this->addError('e_proveedor', 'Esta partida ya está en una OC a proveedor: no se le puede cambiar el proveedor. Cancela esa OC primero.');

            return;
        }

        $nueva = [
            'descripcion' => mb_strtoupper(trim($d['e_descripcion'])),
            'cantidad' => (float) $d['e_cantidad'],
            'unidad' => mb_strtoupper(trim($d['e_unidad'])),
            'modelo' => filled($d['e_modelo']) ? trim($d['e_modelo']) : null,
            'costo' => filled($d['e_costo']) ? (float) $d['e_costo'] : null,
            'proveedor_id' => $provId,
        ];

        $nombre = Str::limit($p->descripcion, 60);
        $cambios = [];
        $afectaOc = false;
        $cambioCosto = false;
        if ($nueva['descripcion'] !== $p->descripcion) {
            $cambios[] = 'cambió la descripción de "'.$nombre.'" a "'.Str::limit($nueva['descripcion'], 60).'"';
            $afectaOc = true;
            $nombre = Str::limit($nueva['descripcion'], 60);
        }
        if (abs($nueva['cantidad'] - (float) $p->cantidad) > 0.0005) {
            $cambios[] = 'cambió la cantidad de "'.$nombre.'" de '.$this->cantidadTexto($p->cantidad).' a '.$this->cantidadTexto($nueva['cantidad']);
            $afectaOc = true;
        }
        if ($nueva['unidad'] !== $p->unidad) {
            $cambios[] = 'cambió la unidad de "'.$nombre.'" de '.$p->unidad.' a '.$nueva['unidad'];
        }
        if ((string) $nueva['modelo'] !== (string) $p->modelo) {
            $cambios[] = 'cambió el modelo de "'.$nombre.'" de '.($p->modelo ?: 'vacío').' a '.($nueva['modelo'] ?: 'vacío');
            $afectaOc = true;
        }
        if ($p->costo === null ? $nueva['costo'] !== null : ($nueva['costo'] === null || abs($nueva['costo'] - (float) $p->costo) > 0.00005)) {
            $cambioCosto = true;
            $cambios[] = 'cambió el costo de "'.$nombre.'" de '.($p->costo === null ? 'vacío' : '$'.number_format((float) $p->costo, 2)).' a '.($nueva['costo'] === null ? 'vacío' : '$'.number_format($nueva['costo'], 2));
            $afectaOc = true;
        }
        if ($nueva['proveedor_id'] !== $p->proveedor_id) {
            $cambios[] = 'cambió el proveedor de "'.$nombre.'" de '.($p->proveedor->nombre ?? 'sin proveedor').' a '.($nueva['proveedor_id'] ? Proveedor::whereKey($nueva['proveedor_id'])->value('nombre') : 'sin proveedor');
        }

        if (! $cambios) {
            $this->editandoId = null;
            $this->mensaje = 'No hubo cambios en la partida.';

            return;
        }

        $p->update($nueva);
        $this->pedido()->recalcularTotales();
        $this->editandoId = null;
        $this->mensaje = 'Partida actualizada.';
        if ($afectaOc && ($aviso = $this->avisoReenvio($p))) {
            $this->mensaje .= ' '.$aviso;
        }
        if ($cambioCosto) {
            $this->mensaje .= ' '.CostoOrden::avisoSicar($this->pedido());
        }
        if ($this->eOrigen) {
            $cambios[] = 'tomó el producto de la '.$this->eOrigen;
            $this->eOrigen = null;
        }
        $this->eSugerencias = [];
        foreach ($cambios as $c) {
            $this->actividad('partida', $c);
        }
    }

    public function quitarPartida(int $partidaId): void
    {
        abort_unless($this->verVenta(), 403);
        $pedido = $this->pedido();
        $p = $pedido->partidas()->findOrFail($partidaId);

        // Ya recogida, entregada o facturada: solo el admin (el material ya se movió o ya se cobró).
        if (! self::sePuedeQuitar($p, auth()->user()->esAdmin())) {
            $this->mensaje = 'No se puede quitar: la partida ya está '.($p->facturada ? 'facturada' : mb_strtolower($p->estado->etiqueta())).'. Solo el administrador puede quitarla.';

            return;
        }

        $descripcion = Str::limit($p->descripcion, 60);
        $ordenId = $p->orden_proveedor_id;
        $p->delete();
        // Si estaba en una OC a proveedor, la OC se recalcula (o se cancela si se quedó vacía).
        $avisoOc = $ordenId ? ReenvioOrden::alQuitarPartida($ordenId) : null;
        // Las fotos de evidencia guardan ids de partidas: se les quita la que ya no existe.
        PedidoArchivo::where('pedido_id', $pedido->id)->whereNotNull('partidas_ids')->get()->each(function ($a) use ($partidaId) {
            $ids = array_values(array_filter((array) $a->partidas_ids, fn ($i) => (int) $i !== $partidaId));
            $a->update(['partidas_ids' => $ids ?: null]);
        });
        $pedido->recalcularTotales();
        $this->seleccion = array_values(array_diff($this->seleccion, [$partidaId, (string) $partidaId]));
        if ($this->editandoId === $partidaId) {
            $this->editandoId = null;
        }
        $this->mensaje = 'Partida quitada.'.($avisoOc ? ' '.$avisoOc : '');
        $this->actividad('partida', 'quitó la partida "'.$descripcion.'"'.($avisoOc ? '. '.$avisoOc : ''));
    }

    /** Vendedores: mientras no esté recogida, entregada ni facturada (aunque ya tenga OC al proveedor). Admin: siempre. */
    public static function sePuedeQuitar(PedidoPartida $p, bool $esAdmin): bool
    {
        return $esAdmin || ($p->estado->nivel() < EstadoPartidaPedido::Recibida->nivel() && ! $p->facturada);
    }

    public function abrirBuscadorCotizacion(): void
    {
        abort_unless($this->verVenta(), 403);
        $this->buscandoCot = true;
        $this->agregando = false;
    }

    public function cerrarBuscadorCotizacion(): void
    {
        $this->buscandoCot = false;
        $this->cotSel = [];
        $this->cotCant = [];
    }

    /** Partidas cotizadas que coinciden con la búsqueda (del cliente del pedido, o de todas). */
    private function partidasCotizadas(Pedido $pedido)
    {
        $q = CotizacionPartida::query()
            ->whereHas('cotizacion')
            ->with(['cotizacion:id,folio,fecha,cliente_id', 'proveedor:id,nombre'])
            ->when(! $this->cotTodas, fn ($q) => $q->whereHas('cotizacion', fn ($c) => $c->where('cliente_id', $pedido->cliente_id)));

        $texto = trim($this->cotBusca);
        if ($texto !== '') {
            $q->where(function ($w) use ($texto) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $texto).'%';
                $w->where('descripcion', 'like', $like)->orWhere('modelo', 'like', $like);
                if (ctype_digit($texto)) {
                    $w->orWhereHas('cotizacion', fn ($c) => $c->where('folio', (int) $texto));
                }
            });
        }

        return $q->orderByDesc('cotizacion_id')->orderBy('orden')->limit(40)->get();
    }

    public function agregarDeCotizacion(): void
    {
        abort_unless($this->verVenta(), 403);
        $ids = array_values(array_unique(array_map('intval', $this->cotSel)));
        if (! $ids) {
            $this->mensaje = 'Marca primero las partidas que quieres agregar.';

            return;
        }

        $pedido = $this->pedido();
        $ya = $pedido->partidas()->whereNotNull('cotizacion_partida_id')->pluck('cotizacion_partida_id')->all();
        $orden = (int) $pedido->partidas()->max('orden');
        $creadas = [];
        foreach (CotizacionPartida::whereIn('id', $ids)->whereHas('cotizacion')->orderBy('cotizacion_id')->orderBy('orden')->get() as $cp) {
            if (in_array($cp->id, $ya)) {
                continue;
            }
            $cantidad = $this->cotCant[$cp->id] ?? $this->cotCant[(string) $cp->id] ?? $cp->cantidad;
            if (! is_numeric($cantidad) || (float) $cantidad <= 0) {
                $this->addError('cotCant.'.$cp->id, 'La cantidad debe ser mayor a 0.');

                return;
            }
            $creadas[] = PedidoPartida::desdeCotizacion($cp, $pedido, ++$orden, (float) $cantidad);
        }

        if (! $creadas) {
            $this->mensaje = 'Esas partidas ya estaban en este pedido.';

            return;
        }

        $pedido->recalcularTotales();
        $this->cerrarBuscadorCotizacion();
        $n = count($creadas);
        $this->mensaje = $n === 1 ? 'Se agregó 1 partida de la cotización.' : "Se agregaron {$n} partidas de cotizaciones.";
        foreach ($creadas as $c) {
            $folio = $c->cotizacionPartida?->cotizacion?->folio;
            $this->actividad('partida', 'agregó la partida "'.Str::limit($c->descripcion, 60).'"'.($folio ? ' de la cotización '.$folio : ''));
        }
    }

    public function agregarPartida(): void
    {
        abort_unless($this->verVenta(), 403);

        $d = $this->validate([
            'n_descripcion' => ['required', 'string', 'max:2000'],
            'n_cantidad' => ['required', 'numeric', 'gt:0'],
            'n_unidad' => ['required', 'string', 'max:10'],
            'n_modelo' => ['nullable', 'string', 'max:255'],
            'n_precio' => ['required', 'numeric', 'min:0'],
            'n_costo' => ['nullable', 'numeric', 'min:0'],
            'n_proveedor' => ['nullable', 'string', 'max:255'],
        ], [
            'n_descripcion.required' => 'Escribe la descripción.',
            'n_cantidad.gt' => 'La cantidad debe ser mayor a 0.',
            'n_cantidad.required' => 'Escribe la cantidad.',
            'n_precio.required' => 'Escribe el precio unitario.',
        ]);

        $provId = $this->proveedorPorNombre($d['n_proveedor'] ?? '');
        if ($provId === false) {
            $this->addError('n_proveedor', 'No encontré ese proveedor. Elige uno de la lista.');

            return;
        }

        $pedido = $this->pedido();
        PedidoPartida::create([
            'pedido_id' => $pedido->id,
            'orden' => ((int) $pedido->partidas()->max('orden')) + 1,
            'descripcion' => mb_strtoupper(trim($d['n_descripcion'])),
            'modelo' => filled($d['n_modelo']) ? $d['n_modelo'] : null,
            'unidad' => mb_strtoupper(trim($d['n_unidad'])),
            'cantidad' => $d['n_cantidad'],
            'costo' => filled($d['n_costo']) ? $d['n_costo'] : null,
            'precio' => $d['n_precio'],
            'proveedor_id' => $provId,
        ]);
        $pedido->recalcularTotales();

        $this->reset(['n_descripcion', 'n_modelo', 'n_precio', 'n_costo', 'n_proveedor']);
        $this->n_cantidad = '1';
        $this->n_unidad = 'PZA';
        $this->agregando = false;
        $this->mensaje = 'Partida agregada.';
        $this->actividad('partida', 'agregó la partida "'.Str::limit(mb_strtoupper(trim($d['n_descripcion'])), 60).'"');
    }

    /** El vendedor ya le mandó el WhatsApp a Daniel de sus cambios de precio directos. */
    public function marcarAvisoPrecio(): void
    {
        abort_unless($this->verVenta(), 403);
        CambioPrecio::where('pedido_id', $this->pedidoId)->where('estatus', 'directo')->whereNull('avisado_at')->update(['avisado_at' => now()]);
    }

    public function render()
    {
        $verCostos = $this->verCostos();
        $verVenta = $this->verVenta();
        $pedido = Pedido::with([Pedido::CLIENTE_REGLAS, 'facturaPortalPor:id,name', 'responsable:id,name,iniciales', 'ultimaConciliacion', 'sucursal'])->findOrFail($this->pedidoId);

        // El personal de campo nunca recibe precio de venta (sí ve costos y proveedor).
        $columnas = ['id', 'pedido_id', 'cotizacion_partida_id', 'orden', 'descripcion', 'marca', 'modelo', 'unidad', 'cantidad', 'estado', 'cantidad_recogida', 'facturada', 'fecha_entrega',
            'proveedor_id', 'sicar_alta_at', 'sicar_alta_firma'];
        if ($verCostos) {
            $columnas = array_merge($columnas, ['proveedor_vendedor', 'costo', 'link_compra', 'proveedor_telefono']);
        }
        if ($verVenta) {
            $columnas[] = 'precio';
            $columnas[] = 'orden_proveedor_id';
        }
        $partidas = $pedido->partidas()->select($columnas)
            ->when($verCostos, fn ($q) => $q->with('proveedor:id,nombre'))
            ->get();
        $pedido->setRelation('partidas', $partidas);

        // Cambios de precio hechos al dar de alta el pedido (solo quien ve precios de venta).
        $cambiosPrecio = $verVenta ? CambioPrecio::where('pedido_id', $pedido->id)->whereNotNull('pedido_partida_id')->where('estatus', '!=', 'rechazado')
            ->orderBy('id')->get()->keyBy('pedido_partida_id') : collect();
        $avisoPrecioWa = null;
        $avisoPrecioTexto = null;
        if ($verVenta && ! auth()->user()->esAdmin()) {
            $tel = Telefono::whatsapp(User::where('rol', 'admin')->where('activo', true)->whereNotNull('telefono')->orderBy('id')->value('telefono'));
            // Cambios hechos por vendedores sin autorización (modo actual) que todavía no se le avisaron a Daniel.
            $sinAvisar = $cambiosPrecio->filter(fn ($c) => $c->estatus === 'directo' && ! $c->avisado_at && $c->solicitado_por === $c->resuelto_por);
            if ($cambiosPrecio->contains('estatus', 'pendiente')) {
                $texto = 'Necesito autorización de precio en el pedido P-'.$pedido->folio.': '.route('cambios-precio.index');
                $avisoPrecioTexto = 'Hay cambios de precio por autorizar. Daniel tiene que aprobarlos.';
            } elseif ($sinAvisar->isNotEmpty()) {
                // Con un solo motivo para todo (el general), el motivo va una vez al final.
                $unMotivo = $sinAvisar->pluck('motivo')->unique()->count() === 1;
                $texto = 'Cambié precios en el pedido P-'.$pedido->folio.":\n".$sinAvisar->map(fn ($c) => '• '.mb_substr((string) $c->descripcion, 0, 60)
                    .': de $'.number_format((float) $c->precio_anterior, 2).' a $'.number_format((float) $c->precio_final, 2).($unMotivo ? '' : ' (motivo: '.$c->motivo.')'))->implode("\n")
                    .($unMotivo ? "\nMotivo: ".$sinAvisar->first()->motivo : '')
                    ."\nDetalle: ".route('cambios-precio.index');
                $avisoPrecioTexto = 'Cambiaste el precio de venta. Avísale a Daniel por WhatsApp.';
            }
            if (isset($texto)) {
                $avisoPrecioWa = 'https://wa.me/'.($tel ?? '').'?text='.rawurlencode($texto);
            }
        }

        $cotizaciones = collect();
        $ordenes = $verCostos ? $pedido->ordenesProveedor()->with('proveedor:id,nombre')->get() : collect();
        if ($verVenta) {
            $idsCot = CotizacionPartida::whereIn('id', $partidas->pluck('cotizacion_partida_id')->filter())->pluck('cotizacion_id')->unique();
            $cotizaciones = Cotizacion::whereIn('id', $idsCot)->orderBy('folio')->get(['id', 'folio']);
        }

        $proveedores = $verVenta && ($this->agregando || $this->editandoId) ? Proveedor::orderBy('nombre')->pluck('nombre') : collect();
        $cotizadas = $verVenta && $this->buscandoCot ? $this->partidasCotizadas($pedido) : collect();
        $yaEnPedido = $partidas->pluck('cotizacion_partida_id')->filter()->all();

        $verFacturas = $this->puedeFacturas();
        $ventasLigadas = $verFacturas ? $pedido->ventas()->orderBy('ventas.fecha')->orderBy('ventas.id')->get() : collect();
        $ventasPorConfirmar = $verFacturas ? LigasPorConfirmar::delPedido($pedido) : collect();

        // Comentarios y actividad mezclados en orden cronológico (lo más nuevo arriba), como Trello.
        $comentarios = $pedido->comentarios()->with('user:id,name,iniciales')->limit(200)->get()
            ->map(fn ($c) => ['tipo' => 'comentario', 'id' => $c->id, 'user' => $c->user, 'fecha' => $c->created_at, 'texto' => $c->texto,
                'puedeBorrar' => auth()->user()->esAdmin() || $c->user_id === auth()->id()]);
        $actividades = $pedido->actividades()->when(! $verVenta, fn ($q) => $q->whereNotIn('tipo', ['precio_cambiado', 'precio_pedido', 'precio_autorizado', 'precio_rechazado']))->with('user:id,name,iniciales')->limit(200)->get()
            ->map(fn ($a) => ['tipo' => 'actividad', 'id' => $a->id, 'user' => $a->user, 'fecha' => $a->created_at, 'texto' => $a->descripcion]);
        $historial = $comentarios->concat($actividades)->sortByDesc(fn ($x) => $x['fecha']?->getTimestamp().'-'.($x['tipo'] === 'comentario' ? 1 : 0).'-'.str_pad((string) $x['id'], 10, '0', STR_PAD_LEFT))->values();

        $sinEntregar = $partidas->filter(fn ($p) => $p->estado !== EstadoPartidaPedido::Entregada)->count();
        $sinFacturar = $partidas->where('facturada', false)->count();
        $venta = $partidas->sum(fn ($p) => (float) $p->precio * (float) $p->cantidad);
        $costo = $partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad);

        return view('livewire.pedidos.ver', [
            'pedido' => $pedido,
            'partidas' => $partidas,
            // Partidas que se agregaron o cambiaron después de dar de alta el pedido en SICAR.
            'faltanSicar' => AltaSicarPartidas::faltantes($pedido)->pluck('id')->all(),
            'estadoSicar' => $partidas->mapWithKeys(fn ($p) => [$p->id => AltaSicarPartidas::estado($p, $pedido)])->all(),
            'verCostos' => $verCostos,
            'verVenta' => $verVenta,
            'estados' => $this->estadosPermitidos(),
            'cotizaciones' => $cotizaciones,
            'ordenes' => $ordenes,
            'proveedores' => $proveedores,
            'cotizadas' => $cotizadas,
            'yaEnPedido' => $yaEnPedido,
            'cambiosPrecio' => $cambiosPrecio,
            'avisoPrecioWa' => $avisoPrecioWa,
            'avisoPrecioTexto' => $avisoPrecioTexto,
            'verFacturas' => $verFacturas,
            'ventasLigadas' => $ventasLigadas,
            'ventasPorConfirmar' => $ventasPorConfirmar,
            'totalVenta' => $venta,
            'totalCosto' => $costo,
            'sinEntregar' => $sinEntregar,
            'sinFacturar' => $sinFacturar,
            'historial' => $historial,
            'mostrarReglas' => ! auth()->user()->esCampo() && ($pedido->clienteEnviaEvidenciaPorCorreo() || $pedido->sucursal_id
                || ClienteSucursal::where('cliente_id', $pedido->cliente_id)->exists()),
            'numComentarios' => $comentarios->count(),
            'recogidas' => $partidas->filter(fn ($p) => $p->estado->nivel() >= EstadoPartidaPedido::Recibida->nivel())->count(),
            // Recogidas parciales: lo que el proveedor no entregó completo, y la partida de la ventanita "¿Cuántas recogiste?".
            'parciales' => $partidas->filter(fn ($p) => $p->recogidaParcial())->values(),
            'recogiendo' => $this->recogiendoIds ? $partidas->whereIn('id', $this->recogiendoIds)->values() : collect(),
            'entregadas' => $partidas->filter(fn ($p) => $p->estado === EstadoPartidaPedido::Entregada)->count(),
            'facturadas' => $partidas->where('facturada', true)->count(),
            'pedidas' => $partidas->filter(fn ($p) => $p->estado->nivel() >= EstadoPartidaPedido::Solicitada->nivel())->count(),
            'evidencias' => PedidoArchivo::where('pedido_id', $pedido->id)->where('tipo', 'evidencia')->count(),
            'responsables' => auth()->user()->esCampo() ? collect() : User::where('activo', true)->orderBy('name')->get(['id', 'name', 'iniciales']),
            'notasHtml' => filled($pedido->notas) ? Str::markdown($pedido->notas, ['html_input' => 'escape', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => "<br>\n"]]) : '',
        ]);
    }
}
