<?php

namespace App\Livewire\Pedidos;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoBorrador;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\ConciliadorOc;
use App\Support\CambiosPrecio;
use App\Support\DocumentoPropio;
use App\Support\Folios;
use App\Support\LecturaOc;
use App\Support\Nombres;
use App\Support\Precios;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Nuevo pedido (llegó OC)')]
class Nuevo extends Component
{
    use WithFileUploads, WithPagination;

    /** Cliente al que se le crea el pedido. */
    public ?int $clienteId = null;

    /**
     * Clientes en cuyas cotizaciones se busca ("KAPPA MOLINOS" y "KAPPA PLANTA CENTRAL" cuando no se
     * recuerda a cuál se le cotizó). Siempre incluye a $clienteId.
     *
     * @var list<int>
     */
    public array $clientesIds = [];

    public string $buscarCliente = '';

    // Filtros del buscador de partidas
    public string $fFolios = '';

    public string $fTexto = '';

    public ?int $fProveedor = null;

    public ?int $fVendedor = null;

    public string $fDesde = '';

    public string $fHasta = '';

    /** IDs de partidas de cotización marcadas. */
    public array $seleccion = [];

    /** id de partida de cotización => cantidad pedida por el cliente. */
    public array $cantidades = [];

    /**
     * id de partida de cotización => precio de venta (sin IVA) para este pedido. Parte del cotizado; si la OC trae otro,
     * se escribe aquí con su motivo (el vendedor pide autorización; el admin lo aplica directo).
     */
    public array $precios = [];

    /** id de partida => motivo solo de esa partida (opcional si se escribe el motivo general). */
    public array $motivosPrecio = [];

    /** Un solo motivo para todos los cambios de precio del pedido (de $10 o más por pieza; los menores no lo piden). */
    public string $motivoGeneral = '';

    /** id de partida => costo del producto sustituto (opcional). */
    public array $costosPrecio = [];

    /** id de partida => nombre del proveedor del sustituto (opcional). */
    public array $proveedoresPrecio = [];

    // Datos del pedido
    public string $tipo = 'oc';

    public string $numeroOc = '';

    public ?int $responsableId = null;

    public string $fechaOc = '';

    public string $fechaCompromiso = '';

    public string $notas = '';

    public $archivo = null;

    /** Lista del tablero donde queda la tarjeta ("+ Añadir" al pie de cada columna). */
    public string $columna = 'oc_recibida';

    /**
     * Pasos en orden: 1 cliente → 2 material → 3 OC del cliente (se revisa con IA contra lo marcado) →
     * 4 datos y crear. Sin OC (o si la IA no pudo leerla) se sigue solo con una justificación.
     */
    public int $paso = 1;

    public bool $sinOc = false;

    public string $justificacion = '';

    /** OC del cliente ya guardada (ruta en el disco local). */
    #[Locked]
    public ?string $ocRuta = null;

    #[Locked]
    public ?string $ocNombre = null;

    /** Lectura con IA de esa OC (pedido_conciliaciones sin pedido hasta crearlo). */
    #[Locked]
    public ?int $conciliacionId = null;

    public bool $aceptoDiferencias = false;

    /**
     * Partidas que vienen en la OC y no estaban cotizadas: se agregan tal como vienen (sin proveedor ni
     * costo; se capturan después en la tarjeta).
     *
     * @var list<array{descripcion: string, marca: ?string, modelo: ?string, cantidad: float|string, unidad: string, precio: float}>
     */
    public array $extras = [];

    /** Fecha estimada de entrega por partida (como en Trello): 'c{id}' = partida cotizada, 'e{i}' = agregada tal como viene en la OC. */
    public array $fechasEntrega = [];

    /** Para poner la misma fecha estimada a todas de un jalón. */
    public string $fechaTodas = '';

    public const MIN_JUSTIFICACION = 10;

    /** Lo que se guarda del pedido a medio capturar para retomarlo (si se cierra la ventana o se sale por error). */
    private const CAMPOS_BORRADOR = [
        'clienteId', 'clientesIds', 'fFolios', 'fTexto', 'fProveedor', 'fVendedor', 'fDesde', 'fHasta',
        'seleccion', 'cantidades', 'precios', 'motivosPrecio', 'motivoGeneral', 'costosPrecio', 'proveedoresPrecio',
        'numeroOc', 'responsableId', 'fechaOc', 'fechaCompromiso', 'notas', 'columna', 'paso',
        'sinOc', 'justificacion', 'ocRuta', 'ocNombre', 'conciliacionId', 'extras', 'fechasEntrega',
    ];

    /** Hay un pedido sin terminar de antes: se pregunta si se retoma (no se carga solo). */
    #[Locked]
    public bool $ofrecerBorrador = false;

    /** Huella de lo último guardado, para no escribir si nada cambió. */
    #[Locked]
    public string $huellaBorrador = '';

    public function mount(): void
    {
        abort_if(auth()->user()->esCampo(), 403);

        $this->columna = ColumnaPedido::tryFrom((string) request()->query('columna'))?->value ?? ColumnaPedido::OcRecibida->value;

        $this->responsableId = auth()->id();
        $this->fechaOc = now()->toDateString();

        $cot = request()->integer('cotizacion') ? Cotizacion::find(request()->integer('cotizacion')) : null;
        if ($cot) {
            $this->clienteId = $cot->cliente_id;
            $this->clientesIds = [$cot->cliente_id];
            $this->fFolios = (string) $cot->folio;
            $this->paso = 2;
        } else {
            $this->fDesde = now()->subDays(60)->toDateString();
        }

        $this->ofrecerBorrador = PedidoBorrador::vigenteDe(auth()->id()) !== null;
    }

    /** Después de cada cambio se guarda lo capturado, en cuanto ya hay material marcado. */
    public function dehydrate(): void
    {
        if ($this->huellaBorrador === 'creado' || ! $this->clienteId || ($this->seleccion === [] && $this->extras === [])) {
            return;
        }
        $datos = [];
        foreach (self::CAMPOS_BORRADOR as $campo) {
            $datos[$campo] = $this->{$campo};
        }
        $huella = md5(json_encode($datos));
        if ($huella === $this->huellaBorrador) {
            return;
        }
        PedidoBorrador::updateOrCreate(['user_id' => auth()->id()], [
            'cliente_id' => $this->clienteId,
            'paso' => $this->paso,
            'partidas' => count($this->seleccion) + count($this->extras),
            'datos' => $datos,
        ]);
        $this->huellaBorrador = $huella;
        $this->ofrecerBorrador = false;
    }

    /** "Continuar donde me quedé": carga lo guardado en el mismo paso. */
    public function retomarBorrador(): void
    {
        $b = PedidoBorrador::vigenteDe(auth()->id());
        $this->ofrecerBorrador = false;
        if (! $b) {
            return;
        }
        $datos = (array) $b->datos;
        foreach (self::CAMPOS_BORRADOR as $campo) {
            if (array_key_exists($campo, $datos)) {
                $this->{$campo} = $datos[$campo];
            }
        }
        $this->clienteId = Cliente::whereKey($this->clienteId)->exists() ? (int) $this->clienteId : null;
        $this->clientesIds = array_values(array_map('intval', (array) $this->clientesIds));
        $this->seleccion = array_values(array_map('intval', (array) $this->seleccion));
        $this->fechasEntrega = array_map(fn ($f) => is_scalar($f) ? (string) $f : '', (array) $this->fechasEntrega);
        $this->paso = max(1, min(4, (int) $this->paso));
        // La OC guardada solo vale si el archivo sigue ahí y es de las OC del cliente.
        if (! $this->ocRuta || ! str_starts_with((string) $this->ocRuta, 'pedidos/oc-cliente/') || ! Storage::disk('local')->exists($this->ocRuta)) {
            $this->reset(['ocRuta', 'ocNombre', 'conciliacionId']);
        }
        if ($this->conciliacionId && ! $this->conciliacion()) {
            $this->conciliacionId = null;
        }
        if (! $this->clienteId) {
            $this->paso = 1;
        }
        $this->aceptoDiferencias = false;
        $this->huellaBorrador = '';
        $this->resetPage();
    }

    /** "Empezar de nuevo": se tira el pedido sin terminar (y su OC subida, si nadie más la usa). */
    public function descartarBorrador(): void
    {
        $b = PedidoBorrador::where('user_id', auth()->id())->first();
        $ruta = $b ? (string) (((array) $b->datos)['ocRuta'] ?? '') : '';
        if ($ruta !== '' && $ruta !== $this->ocRuta && str_starts_with($ruta, 'pedidos/oc-cliente/')
            && ! Pedido::withTrashed()->where('oc_cliente_path', $ruta)->exists()) {
            Storage::disk('local')->delete($ruta);
        }
        $b?->delete();
        $this->ofrecerBorrador = false;
        $this->huellaBorrador = '';
    }

    public function updated($campo): void
    {
        // Cambió una cantidad o una partida: hay que volver a confirmar las diferencias con la OC.
        if (str_starts_with($campo, 'cantidades') || str_starts_with($campo, 'extras') || str_starts_with($campo, 'seleccion') || str_starts_with($campo, 'precios')) {
            $this->aceptoDiferencias = false;
        }
        // Precio vacío o que no es número: vuelve al cotizado.
        if (str_starts_with($campo, 'precios.')) {
            $id = (int) substr($campo, 8);
            $v = $this->precios[$id] ?? null;
            if ($v === null || trim((string) $v) === '' || ! is_numeric($v)) {
                $this->precios[$id] = (float) CotizacionPartida::whereKey($id)->value('precio');
            }
        }
        if (in_array($campo, ['fFolios', 'fTexto', 'fProveedor', 'fVendedor', 'fDesde', 'fHasta'], true)) {
            $this->resetPage();
        }
        // Al escribir folios se quitan las fechas para que no confundan en pantalla.
        if ($campo === 'fFolios' && trim($this->fFolios) !== '') {
            $this->fDesde = '';
            $this->fHasta = '';
        }
    }

    public function updatedSeleccion(): void
    {
        $ids = array_map('intval', $this->seleccion);
        $faltan = array_diff($ids, array_keys($this->cantidades));
        if ($faltan) {
            $base = CotizacionPartida::whereIn('id', $faltan)->pluck('cantidad', 'id');
            foreach ($base as $id => $cant) {
                $this->cantidades[$id] = (float) $cant;
            }
        }
        $this->prellenarPrecios($ids);
        // Si todo lo marcado es de un mismo cliente de la búsqueda, el pedido es de ese cliente.
        if (count($this->clientesIds) > 1 && $ids) {
            $de = Cotizacion::whereIn('id', CotizacionPartida::whereIn('id', $ids)->select('cotizacion_id'))->distinct()->pluck('cliente_id');
            if ($de->count() === 1) {
                $this->clienteId = (int) $de->first();
            }
        }
    }

    /** Cada partida marcada arranca con su precio cotizado. */
    private function prellenarPrecios(array $ids): void
    {
        $faltan = array_diff($ids, array_keys($this->precios));
        if ($faltan) {
            foreach (CotizacionPartida::whereIn('id', $faltan)->pluck('precio', 'id') as $id => $precio) {
                $this->precios[$id] = (float) $precio;
            }
        }
    }

    /** Precio de venta que se usará para la partida (el escrito, o el cotizado si no se tocó). */
    private function precioDe(object $p): float
    {
        $v = $this->precios[$p->id] ?? null;

        return $v !== null && trim((string) $v) !== '' && is_numeric($v) ? round((float) $v, 2) : (float) $p->precio;
    }

    private function cambioPrecio(object $p): bool
    {
        return abs($this->precioDe($p) - (float) $p->precio) > 0.004;
    }

    /** Cambio de menos de $10 por pieza: no pide motivo ni aviso. */
    private function cambioMenor(object $p): bool
    {
        return $this->cambioPrecio($p) && CambiosPrecio::esMenor((float) $p->precio, $this->precioDe($p));
    }

    /** Motivo de la partida: el suyo si lo escribieron; si no, el general. */
    private function motivoDe(object $p): string
    {
        $propio = trim((string) ($this->motivosPrecio[$p->id] ?? ''));

        return $propio !== '' ? $propio : trim($this->motivoGeneral);
    }

    /**
     * Errores de los precios cambiados (precio ≥ 0, motivo de al menos 10 letras —el de la partida o el general— en los
     * cambios de $10 o más por pieza, costo y proveedor válidos).
     *
     * @return array<string, string> campo => mensaje
     */
    private function erroresPrecios(): array
    {
        $errores = [];
        if ($this->seleccion === []) {
            return $errores;
        }
        foreach (CotizacionPartida::whereIn('id', array_map('intval', $this->seleccion))->get(['id', 'precio']) as $p) {
            $v = $this->precios[$p->id] ?? null;
            if ($v !== null && is_numeric($v) && (float) $v < 0) {
                $errores['precios.'.$p->id] = 'El precio no puede ser negativo.';
            }
            if (! $this->cambioPrecio($p)) {
                continue;
            }
            $propio = trim((string) ($this->motivosPrecio[$p->id] ?? ''));
            if ($propio !== '' && mb_strlen($propio) < self::MIN_JUSTIFICACION) {
                $errores['motivosPrecio.'.$p->id] = 'El motivo es muy corto (mínimo '.self::MIN_JUSTIFICACION.' letras). Si es el mismo para todo, déjalo vacío y usa el motivo general.';
            } elseif (! $this->cambioMenor($p) && mb_strlen($this->motivoDe($p)) < self::MIN_JUSTIFICACION) {
                $errores['motivoGeneral'] = 'Escribe el motivo del cambio de precio (mínimo '.self::MIN_JUSTIFICACION.' letras). Uno solo sirve para todas las partidas.';
            }
            $costo = $this->costosPrecio[$p->id] ?? '';
            if (trim((string) $costo) !== '' && (! is_numeric($costo) || (float) $costo < 0)) {
                $errores['costosPrecio.'.$p->id] = 'El costo debe ser un número de 0 en adelante.';
            }
            if (CambiosPrecio::proveedorPorNombre($this->proveedoresPrecio[$p->id] ?? '') === false) {
                $errores['proveedoresPrecio.'.$p->id] = 'No encontré ese proveedor. Elige uno de la lista.';
            }
        }

        return $errores;
    }

    /** Pone los errores en pantalla y regresa al paso 2 donde se corrigen. */
    private function mostrarErroresPrecios(array $errores): void
    {
        foreach ($errores as $campo => $mensaje) {
            $this->addError($campo, $mensaje);
        }
        $this->paso = 2;
        $ids = collect(array_keys($errores))->filter(fn ($k) => str_contains($k, '.'))->map(fn ($k) => (int) substr((string) strrchr($k, '.'), 1))->unique()->take(3)->all();
        if ($ids === []) {
            $this->addError('paso', 'Falta el motivo del cambio de precio. Escríbelo una sola vez en "Motivo del cambio de precio" aquí en el paso 2.');

            return;
        }
        $nombres = CotizacionPartida::whereIn('id', $ids)->pluck('descripcion')->map(fn ($d) => '"'.mb_substr((string) $d, 0, 40).'"')->implode(', ');
        $this->addError('paso', 'Falta revisar el precio de: '.$nombres.'. Se corrige aquí en el paso 2 (puede estar en otra página del listado).');
    }

    /** Se subió la OC del cliente: se guarda y se manda a leer con IA para compararla con lo marcado. */
    public function updatedArchivo(): void
    {
        $this->validateOnly('archivo', $this->reglasArchivo(), $this->mensajesArchivo());
        if (! $this->archivo) {
            return;
        }
        if ($motivo = DocumentoPropio::motivoNoEsOc((string) file_get_contents($this->archivo->getRealPath()), $this->archivo->getClientOriginalName())) {
            $this->archivo = null;
            $this->addError('archivo', $motivo);

            return;
        }
        $this->ocNombre = $this->archivo->getClientOriginalName();
        $this->ocRuta = $this->archivo->store('pedidos/oc-cliente', 'local');
        $this->archivo = null;
        $this->sinOc = false;
        $this->aceptoDiferencias = false;
        $this->leerOc();
    }

    private function leerOc(): void
    {
        $this->resetErrorBag('ocRuta');
        $disco = Storage::disk('local');
        if (! $this->ocRuta || ! $disco->exists($this->ocRuta)) {
            return;
        }
        $ruta = $disco->path($this->ocRuta);
        $hash = hash_file('sha256', $ruta);

        // El mismo archivo ya se leyó antes: se reutiliza la lectura (no se paga otra vez).
        $previa = PedidoConciliacion::where('archivo_hash', $hash)->where('estado', 'completada')->whereNotNull('extraccion')->latest('id')->first();
        if ($previa) {
            $c = PedidoConciliacion::create([
                'pedido_id' => null, 'user_id' => auth()->id(), 'archivo_hash' => $hash, 'proveedor_ia' => $previa->proveedor_ia,
                'modelo' => $previa->modelo, 'response_id' => $previa->response_id, 'estado' => 'completada',
                'extraccion' => $previa->extraccion, 'duracion_seg' => 0,
            ]);
            $this->conciliacionId = $c->id;
            $this->prellenarDeLaOc($c);

            return;
        }

        $mime = LecturaOc::mime($ruta);
        $modelo = $mime ? LecturaOc::modelo($mime) : '';
        $aviso = ! $mime ? 'El archivo no es PDF ni imagen; no se puede revisar con IA.' : LecturaOc::sinPresupuesto($modelo);
        $datos = [
            'pedido_id' => null, 'user_id' => auth()->id(), 'archivo_hash' => $hash, 'proveedor_ia' => 'openai',
            'modelo' => $modelo ?: 'ninguno', 'estado' => $aviso ? 'error' : 'procesando', 'error' => $aviso,
        ];
        try {
            $c = $aviso ? PedidoConciliacion::create($datos) : LecturaOc::reservar($datos);
        } catch (\DomainException $e) {
            $aviso = $e->getMessage();
            $c = PedidoConciliacion::create(array_replace($datos, ['estado' => 'error', 'error' => $aviso]));
        }
        $this->conciliacionId = $c->id;
        if (! $aviso) {
            $cliente = Cliente::find($this->clienteId);
            LecturaOc::iniciar($c, $ruta, $mime, 'Cliente capturado por el vendedor: '.($cliente->nombre ?? 'desconocido').'. '
                .'Número de OC capturado: '.(trim($this->numeroOc) ?: 'no capturado').'. Extrae los renglones del documento adjunto.');
        }
    }

    /** Sondeo mientras la IA lee la OC (wire:poll). */
    public function consultarOc(): void
    {
        $c = $this->conciliacion();
        if ($c && ($c->estado === 'completada' || ($c->estado === 'procesando' && LecturaOc::consultar($c)))) {
            $this->prellenarDeLaOc($c->fresh());
        }
    }

    public function reintentarLectura(): void
    {
        $this->conciliacionId = null;
        $this->leerOc();
    }

    public function quitarOc(): void
    {
        if ($this->ocRuta) {
            Storage::disk('local')->delete($this->ocRuta);
        }
        $this->reset(['ocRuta', 'ocNombre', 'conciliacionId', 'aceptoDiferencias']);
    }

    /** Número y fecha de la OC leídos del archivo (si no se habían escrito). */
    private function prellenarDeLaOc(PedidoConciliacion $c): void
    {
        $e = (array) $c->extraccion;
        if (trim($this->numeroOc) === '' && filled($e['numero_oc'] ?? null)) {
            $this->numeroOc = mb_substr((string) $e['numero_oc'], 0, 80);
        }
        if (filled($e['fecha_oc'] ?? null)) {
            try {
                $this->fechaOc = Carbon::parse($e['fecha_oc'])->toDateString();
            } catch (\Throwable) {
            }
        }
    }

    private function conciliacion(): ?PedidoConciliacion
    {
        return $this->conciliacionId ? PedidoConciliacion::whereNull('pedido_id')->where('user_id', auth()->id())->find($this->conciliacionId) : null;
    }

    /** Resultado de comparar la OC leída contra las partidas marcadas (con las cantidades capturadas). */
    private function resultado(?PedidoConciliacion $c): ?array
    {
        if (! $c || $c->estado !== 'completada') {
            return null;
        }
        $partidas = CotizacionPartida::whereIn('id', array_map('intval', $this->seleccion))->orderBy('cotizacion_id')->orderBy('orden')
            ->get(['id', 'descripcion', 'modelo', 'cantidad', 'unidad', 'precio'])
            ->map(fn ($p) => ['id' => $p->id, 'descripcion' => $p->descripcion, 'modelo' => $p->modelo,
                'cantidad' => (float) ($this->cantidades[$p->id] ?? $p->cantidad), 'unidad' => $p->unidad, 'precio' => $this->precioDe($p)]);
        // Las agregadas desde la OC llevan id negativo (-1, -2…).
        foreach ($this->extras as $i => $e) {
            $partidas->push(['id' => -($i + 1), 'descripcion' => $e['descripcion'], 'modelo' => $e['modelo'] ?? null,
                'cantidad' => (float) $e['cantidad'], 'unidad' => $e['unidad'], 'precio' => $e['precio']]);
        }

        return app(ConciliadorOc::class)->conciliar((array) $c->extraccion, $partidas, trim($this->numeroOc) ?: null);
    }

    /** Botón "Usar la de la OC" (también para las agregadas desde la OC). */
    public function usarCantidadOc(int $partidaId, float $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }
        if ($partidaId < 0 && isset($this->extras[-$partidaId - 1])) {
            $this->extras[-$partidaId - 1]['cantidad'] = $cantidad;
        } elseif (in_array($partidaId, array_map('intval', $this->seleccion), true)) {
            $this->cantidades[$partidaId] = $cantidad;
        }
        $this->aceptoDiferencias = false;
    }

    /** Botón "Quitarla" (marcada pero no viene en la OC). */
    public function desmarcar(int $partidaId): void
    {
        if ($partidaId < 0) {
            unset($this->extras[-$partidaId - 1]);
            $this->extras = array_values($this->extras);
        } else {
            $this->seleccion = array_values(array_filter($this->seleccion, fn ($id) => (int) $id !== $partidaId));
        }
        $this->aceptoDiferencias = false;
    }

    /** Renglón de la OC que falta en lo marcado: se usa una partida de las cotizaciones del cliente. */
    public function usarCotizada(int $partidaId, float $cantidad): void
    {
        $ok = CotizacionPartida::whereKey($partidaId)
            ->whereIn('cotizacion_id', Cotizacion::select('id')->whereIn('cliente_id', $this->clientes()))->exists();
        if (! $ok) {
            return;
        }
        if (! in_array($partidaId, array_map('intval', $this->seleccion), true)) {
            $this->seleccion[] = (string) $partidaId;
        }
        $this->cantidades[$partidaId] = $cantidad > 0 ? $cantidad : (float) CotizacionPartida::whereKey($partidaId)->value('cantidad');
        $this->prellenarPrecios([$partidaId]);
        $this->aceptoDiferencias = false;
    }

    /** Renglón de la OC que no se cotizó: se agrega tal como viene en la OC (precio sin IVA). */
    public function agregarDeLaOc(string $clave): void
    {
        $c = $this->conciliacion();
        $fila = collect($this->resultado($c)['filas'] ?? [])->firstWhere('clave', $clave);
        if (! $fila || ! $fila['renglon'] || $fila['partida']) {
            return;
        }
        $r = $fila['renglon'];
        $precio = (float) ($r['precio_unitario'] ?? 0);
        if ($precio > 0 && (($c->extraccion['precios_incluyen_iva'] ?? false) === true)) {
            $precio = round($precio / (1 + Precios::iva()), 2);
        }
        $this->extras[] = [
            'descripcion' => mb_strtoupper(trim((string) $r['descripcion'])),
            'marca' => filled($r['marca']) ? (string) $r['marca'] : null,
            'modelo' => filled($r['modelo']) ? (string) $r['modelo'] : null,
            'cantidad' => (float) $r['cantidad'],
            'unidad' => mb_substr(mb_strtoupper(trim((string) ($r['unidad'] ?: 'PZA'))), 0, 10),
            'precio' => $precio,
        ];
        $this->aceptoDiferencias = false;
    }

    /**
     * Partidas de las cotizaciones del cliente que se parecen a un renglón de la OC (para "Usar esta").
     *
     * @return Collection<int, CotizacionPartida>
     */
    private function parecidasA(array $renglon)
    {
        $tokR = ConciliadorOc::tokens($renglon['descripcion'] ?? '');
        if ($tokR === []) {
            return collect();
        }
        $marcadas = array_map('intval', $this->seleccion);
        $q = CotizacionPartida::query()->with('cotizacion:id,folio,fecha')
            ->whereIn('cotizacion_id', Cotizacion::select('id')->whereIn('cliente_id', $this->clientes())->where('fecha', '>=', now()->subYear()))
            ->where('incluir', true)->whereNotIn('id', $marcadas ?: [0])
            ->where(function ($w) use ($tokR) {
                foreach ($tokR as $t) {
                    if (mb_strlen($t) >= 3) {
                        $w->orWhere('descripcion', 'like', '%'.addcslashes($t, '%_\\').'%');
                    }
                }
            })
            ->orderByDesc('id')->limit(200)->get(['id', 'cotizacion_id', 'descripcion', 'cantidad', 'unidad', 'precio']);

        return $q->map(fn ($p) => [$p, ConciliadorOc::contencion($tokR, ConciliadorOc::tokens($p->descripcion)) + ConciliadorOc::jaccard($tokR, ConciliadorOc::tokens($p->descripcion))])
            ->filter(fn ($x) => $x[1] >= 0.5)->sortByDesc(fn ($x) => $x[1])->take(3)->map(fn ($x) => $x[0])->values();
    }

    /** ¿Ya se puede pasar a los datos del pedido? Devuelve el motivo si no. */
    private function faltaParaDatos(): ?string
    {
        if (! $this->clienteId) {
            return 'Elige el cliente.';
        }
        if ($this->seleccion === [] && $this->extras === []) {
            return 'Marca al menos una partida.';
        }
        if (mb_strlen(trim($this->justificacion)) >= self::MIN_JUSTIFICACION) {
            return null;   // sin OC, o la IA no pudo leerla: se sigue con justificación
        }
        if ($this->sinOc) {
            return 'Escribe por qué no hay OC del cliente (mínimo '.self::MIN_JUSTIFICACION.' letras).';
        }
        $c = $this->conciliacion();
        if (! $this->ocRuta || ! $c) {
            return 'Sube la OC del cliente, o marca "No tengo OC" y escribe por qué.';
        }
        if ($c->estado === 'procesando') {
            return 'Espera a que termine la revisión de la OC.';
        }
        if ($c->estado === 'error') {
            return 'No se pudo revisar la OC. Reintenta o escribe por qué sigues sin revisión.';
        }

        return null;
    }

    public function irAPaso(int $paso): void
    {
        $paso = max(1, min(4, $paso));
        $this->resetErrorBag('paso');
        if ($paso >= 2 && ! $this->clienteId) {
            $this->addError('paso', 'Elige el cliente.');

            return;
        }
        if ($paso >= 3) {
            $this->updatedSeleccion();
            if ($this->seleccion === [] && $this->extras === []) {
                $this->addError('paso', 'Marca al menos una partida del material que pidió el cliente.');

                return;
            }
        }
        if ($paso >= 3 && ($errores = $this->erroresPrecios())) {
            $this->mostrarErroresPrecios($errores);

            return;
        }
        if ($paso === 4 && ($falta = $this->faltaParaDatos())) {
            $this->addError('paso', $falta);

            return;
        }
        $this->paso = $paso;
    }

    public function seleccionarCliente(int $id): void
    {
        $this->cambiarCliente($id);
        $this->buscarCliente = '';
    }

    /** Buscar también en las cotizaciones de otro cliente. */
    public function agregarCliente(int $id): void
    {
        if (Cliente::whereKey($id)->exists() && ! in_array($id, $this->clientesIds, true)) {
            $this->clientesIds[] = $id;
            $this->resetPage();
        }
        $this->buscarCliente = '';
    }

    public function quitarCliente(int $id): void
    {
        if (count($this->clientesIds) <= 1) {
            return;
        }
        $this->clientesIds = array_values(array_filter($this->clientesIds, fn ($c) => $c !== $id));
        if ($this->clienteId === $id) {
            $this->clienteId = $this->clientesIds[0];
        }
        // Se quitan las marcas de partidas de ese cliente.
        $quedan = CotizacionPartida::whereIn('id', array_map('intval', $this->seleccion))
            ->whereIn('cotizacion_id', Cotizacion::select('id')->whereIn('cliente_id', $this->clientesIds))->pluck('id')->all();
        $this->seleccion = array_values(array_map('intval', $quedan));
        $this->resetPage();
    }

    public function otroCliente(): void
    {
        $this->cambiarCliente(null);
        $this->fFolios = '';
        $this->fDesde = now()->subDays(60)->toDateString();
    }

    private function cambiarCliente(?int $id): void
    {
        $this->clienteId = $id;
        $this->clientesIds = $id ? [$id] : [];
        $this->seleccion = [];
        $this->cantidades = [];
        $this->reset(['precios', 'motivosPrecio', 'motivoGeneral', 'costosPrecio', 'proveedoresPrecio']);
        $this->paso = $id ? 2 : 1;
        $this->resetPage();
    }

    public function limpiarFiltros(): void
    {
        $this->reset(['fFolios', 'fTexto', 'fProveedor', 'fVendedor', 'fHasta']);
        $this->fDesde = now()->subDays(60)->toDateString();
        $this->resetPage();
    }

    /**
     * Partidas marcadas (de todas las páginas) y su importe con las cantidades capturadas: sirve para cuadrar contra la OC.
     *
     * @return array{partidas: int, subtotal: float, iva: float, total: float}
     */
    private function sumaMarcadas(): array
    {
        $subtotal = 0.0;
        $n = 0;
        if ($this->seleccion !== []) {
            foreach (CotizacionPartida::whereIn('id', array_map('intval', $this->seleccion))->get(['id', 'cantidad', 'precio']) as $p) {
                $subtotal += (float) ($this->cantidades[$p->id] ?? $p->cantidad) * $this->precioDe($p);
                $n++;
            }
        }
        $subtotal = round($subtotal, 2);
        $iva = round($subtotal * Precios::iva(), 2);

        return ['partidas' => $n, 'subtotal' => $subtotal, 'iva' => $iva, 'total' => round($subtotal + $iva, 2)];
    }

    public function seleccionarVisibles(): void
    {
        $this->seleccion = array_values(array_unique(array_merge(
            array_map('intval', $this->seleccion),
            $this->partidasQuery()->paginate(25)->pluck('id')->all(),
        )));
        $this->updatedSeleccion();
    }

    public function quitarSeleccion(): void
    {
        $this->seleccion = [];
    }

    /** Llave de la OC del cliente, igual que la calcula el modelo. */
    private function llaveOc(string $numero): string
    {
        return Nombres::llave(preg_replace('/^\s*(OC|O\.C\.|PO)\s*[:#-]?\s*/i', '', trim($numero)));
    }

    private function duplicado(): ?Pedido
    {
        if ($this->tipo !== 'oc' || ! $this->clienteId || trim($this->numeroOc) === '') {
            return null;
        }
        $llave = $this->llaveOc($this->numeroOc);

        return $llave === '' ? null : Pedido::withTrashed()->where('cliente_id', $this->clienteId)->where('numero_oc_llave', $llave)->first();
    }

    private function reglasArchivo(): array
    {
        return ['archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480']];
    }

    private function mensajesArchivo(): array
    {
        return [
            'archivo.mimes' => 'La OC debe ser PDF o foto (JPG, PNG o WEBP).',
            'archivo.max' => 'El archivo pesa más de 20 MB.',
            'archivo.file' => 'Sube un archivo PDF o una foto.',
        ];
    }

    private function partidasQuery()
    {
        $cotizaciones = Cotizacion::query()->select('id')->whereIn('cliente_id', $this->clientes());

        // Si se buscan folios concretos, las fechas no aplican: la cotización puede ser vieja
        // (p. ej. cot. 9218 de junio buscada con el filtro por omisión de "últimos 60 días").
        $porFolio = (bool) preg_match_all('/\d+/', $this->fFolios, $m);
        if ($porFolio) {
            $cotizaciones->whereIn('folio', array_map('intval', $m[0]));
        }
        if ($this->fVendedor) {
            $cotizaciones->where('vendedor_id', $this->fVendedor);
        }
        if (! $porFolio && $this->fDesde !== '') {
            $cotizaciones->whereDate('fecha', '>=', $this->fDesde);
        }
        if (! $porFolio && $this->fHasta !== '') {
            $cotizaciones->whereDate('fecha', '<=', $this->fHasta);
        }

        $q = CotizacionPartida::query()
            ->whereIn('cotizacion_id', $cotizaciones)
            ->where('incluir', true)
            ->with(['cotizacion:id,folio,fecha,cliente_id', 'cotizacion.cliente:id,nombre', 'proveedor:id,nombre'])
            ->orderByDesc('cotizacion_id')->orderBy('orden');

        foreach (preg_split('/\s+/', trim($this->fTexto), -1, PREG_SPLIT_NO_EMPTY) as $palabra) {
            $q->where('descripcion', 'like', '%'.addcslashes($palabra, '%_\\').'%');
        }
        if ($this->fProveedor) {
            $q->where('proveedor_id', $this->fProveedor);
        }

        return $q;
    }

    /** @return list<int> */
    private function clientes(): array
    {
        $ids = array_map('intval', $this->clientesIds);
        if ($this->clienteId && ! in_array($this->clienteId, $ids, true)) {
            $ids[] = $this->clienteId;
        }

        return $ids;
    }

    public function crear()
    {
        abort_if(auth()->user()->esCampo(), 403);

        $this->seleccion = array_values(array_unique(array_map('intval', $this->seleccion)));

        // El trabajo va en orden: sin OC revisada (o justificación) no se crea el pedido.
        if ($falta = $this->faltaParaDatos()) {
            $this->addError('paso', $falta);

            return null;
        }
        $conciliacion = $this->conciliacion();
        $resultado = $this->resultado($conciliacion);
        if ($resultado && $resultado['hay_diferencias'] && ! $this->aceptoDiferencias && mb_strlen(trim($this->justificacion)) < self::MIN_JUSTIFICACION) {
            $this->addError('aceptoDiferencias', 'La OC tiene diferencias con lo que marcaste: corrígelas o palomea que las revisaste.');

            return null;
        }
        // Con número de OC es orden de compra; sin número, nota de venta (queda "Esperando OC del cliente").
        $this->tipo = ($this->ocRuta || trim($this->numeroOc) !== '') ? 'oc' : 'nota_venta';

        $this->validate([
            'clienteId' => ['required', 'exists:clientes,id'],
            'seleccion' => [Rule::requiredIf($this->extras === []), 'array'],
            'extras.*.descripcion' => ['required', 'string', 'max:2000'],
            'extras.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'tipo' => ['required', Rule::in(['oc', 'nota_venta'])],
            'numeroOc' => [Rule::requiredIf($this->tipo === 'oc'), 'nullable', 'string', 'max:80',
                fn ($a, $v, $falla) => Pedido::esFolioDeCotizacion($v) ? $falla(Pedido::AVISO_OC_ES_COTIZACION) : null],
            'responsableId' => ['required', Rule::exists('users', 'id')->where('activo', true)],
            'fechaOc' => ['required', 'date'],
            'fechaCompromiso' => ['nullable', 'date'],
            'fechasEntrega.*' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:5000'],
            ...$this->reglasArchivo(),
        ], [
            'clienteId.required' => 'Elige el cliente.',
            'seleccion.required' => 'Marca al menos una partida.',
            'seleccion.min' => 'Marca al menos una partida.',
            'numeroOc.required' => 'Escribe el número de la OC del cliente.',
            'responsableId.required' => 'Elige al responsable.',
            'responsableId.exists' => 'Elige un responsable activo.',
            'fechaOc.required' => 'Escribe la fecha de la OC.',
            'fechasEntrega.*.date' => 'Una fecha estimada de entrega no es válida.',
        ] + $this->mensajesArchivo());

        if ($dup = $this->duplicado()) {
            $this->addError('numeroOc', 'Ya existe el pedido de la OC '.$dup->numero_oc_cliente.' de este cliente.');

            return null;
        }

        $partidas = CotizacionPartida::whereIn('id', $this->seleccion)
            ->whereIn('cotizacion_id', Cotizacion::select('id')->whereIn('cliente_id', $this->clientes()))
            ->orderBy('cotizacion_id')->orderBy('orden')->get();
        if ($partidas->isEmpty() && $this->extras === []) {
            $this->addError('seleccion', 'Marca al menos una partida.');

            return null;
        }
        foreach ($partidas as $p) {
            if ((float) ($this->cantidades[$p->id] ?? $p->cantidad) <= 0) {
                $this->addError('seleccion', 'Todas las cantidades deben ser mayores a 0.');

                return null;
            }
        }

        if ($errores = $this->erroresPrecios()) {
            $this->mostrarErroresPrecios($errores);

            return null;
        }

        $ruta = $this->ocRuta;
        $notas = trim($this->notas);
        if (mb_strlen(trim($this->justificacion)) >= self::MIN_JUSTIFICACION) {
            $notas = trim(($this->ocRuta ? 'Sin revisión de la OC: ' : 'Sin OC del cliente: ').trim($this->justificacion)."\n".$notas);
        }

        $pedido = DB::transaction(function () use ($partidas, $ruta, $notas, $conciliacion) {
            $columna = ColumnaPedido::tryFrom($this->columna) ?? ColumnaPedido::OcRecibida;
            Pedido::where('columna', $columna->value)->increment('posicion');

            $pedido = Pedido::create([
                'folio' => Folios::siguiente('pedido'),
                'tipo' => $this->tipo,
                'cliente_id' => $this->clienteId,
                'numero_oc_cliente' => $this->numeroOc,
                'responsable_id' => $this->responsableId,
                'fecha_oc' => $this->fechaOc,
                'fecha_compromiso' => $this->fechaCompromiso !== '' ? $this->fechaCompromiso : null,
                'columna' => $columna,
                'posicion' => 0,
                'oc_cliente_path' => $ruta,
                'notas' => $notas !== '' ? $notas : null,
            ]);

            $creadas = [];
            foreach ($partidas as $i => $p) {
                $creadas[$p->id] = PedidoPartida::desdeCotizacion($p, $pedido, $i + 1, (float) ($this->cantidades[$p->id] ?? $p->cantidad));
                if ($fecha = $this->fechaEntrega('c'.$p->id)) {
                    $creadas[$p->id]->update(['fecha_entrega' => $fecha]);
                }
            }
            // Las que venían en la OC y no estaban cotizadas: sin proveedor ni costo (se capturan en la tarjeta).
            $orden = $partidas->count();
            foreach ($this->extras as $i => $e) {
                PedidoPartida::create([
                    'pedido_id' => $pedido->id, 'orden' => ++$orden, 'descripcion' => mb_substr($e['descripcion'], 0, 2000),
                    'marca' => $e['marca'] ?? null, 'modelo' => $e['modelo'] ?? null, 'unidad' => $e['unidad'] ?: 'PZA',
                    'cantidad' => (float) $e['cantidad'], 'costo' => null, 'precio' => (float) $e['precio'],
                    'fecha_entrega' => $this->fechaEntrega('e'.$i),
                ]);
            }
            if ($this->extras !== []) {
                PedidoActividad::registrar($pedido, 'partidas_de_la_oc', 'agregó '.count($this->extras).' '.(count($this->extras) === 1 ? 'partida' : 'partidas')
                    .' tal como vienen en la OC (sin cotización): falta capturar proveedor y costo');
            }
            $pedido->recalcularTotales();

            // Cotización cuyas partidas ya quedaron todas colocadas: pasa a aceptada.
            foreach ($partidas->pluck('cotizacion_id')->unique() as $cotId) {
                $sinColocar = CotizacionPartida::where('cotizacion_id', $cotId)->where('incluir', true)
                    ->whereDoesntHave('pedidoPartidas')->exists();
                if (! $sinColocar) {
                    $cot = Cotizacion::find($cotId);
                    if ($cot && in_array($cot->estado, [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada], true)) {
                        $cot->update(['estado' => EstadoCotizacion::Aceptada]);
                    }
                }
            }

            PedidoActividad::registrar($pedido, 'crear', 'creó la tarjeta en "'.$columna->etiqueta().'"');

            // Precio distinto al cotizado: admin (o cambio menor a $10) lo aplica directo; vendedor según el interruptor.
            foreach ($partidas as $p) {
                if (! $this->cambioPrecio($p)) {
                    continue;
                }
                $costo = trim((string) ($this->costosPrecio[$p->id] ?? ''));
                CambiosPrecio::registrar($pedido, $creadas[$p->id], [
                    'precio' => $this->precioDe($p),
                    'motivo' => $this->motivoDe($p),
                    'costo' => $costo !== '' ? (float) $costo : null,
                    'proveedor_id' => CambiosPrecio::proveedorPorNombre($this->proveedoresPrecio[$p->id] ?? '') ?: null,
                ], auth()->user());
            }

            // La revisión de la OC hecha al darlo de alta queda en la tarjeta (con las partidas ya del pedido).
            if ($conciliacion && $conciliacion->estado === 'completada') {
                $r = app(ConciliadorOc::class)->conciliar((array) $conciliacion->extraccion,
                    $pedido->partidas()->get(['id', 'descripcion', 'modelo', 'cantidad', 'unidad', 'precio']), $pedido->numero_oc_cliente);
                $conciliacion->update(['pedido_id' => $pedido->id, 'resultado' => $r, 'resumen' => $r['resumen'], 'hay_diferencias' => $r['hay_diferencias']]);
                PedidoActividad::registrar($pedido, 'conciliacion', $r['hay_diferencias']
                    ? 'revisó la OC contra lo cotizado y la dio por buena con diferencias: '.$r['resumen']
                    : 'revisó la OC contra lo cotizado: todo coincide');
            } elseif (mb_strlen(trim($this->justificacion)) >= self::MIN_JUSTIFICACION) {
                PedidoActividad::registrar($pedido, 'sin_oc', ($this->ocRuta ? 'siguió sin revisión de la OC: ' : 'creó el pedido sin OC del cliente: ').trim($this->justificacion));
            }

            return $pedido;
        });

        // Ya se creó: el pedido sin terminar ya no hace falta.
        PedidoBorrador::where('user_id', auth()->id())->delete();
        $this->huellaBorrador = 'creado';

        // Como en Trello: vuelve al tablero con la tarjeta nueva abierta.
        return $this->redirectRoute('pedidos.index', ['pedido' => $pedido->id]);
    }

    /** Pone la misma fecha estimada de entrega a todas las partidas marcadas (y a las agregadas de la OC). */
    public function aplicarFechaTodas(): void
    {
        $this->resetErrorBag('fechaTodas');
        if ($this->fechaTodas !== '' && strtotime($this->fechaTodas) === false) {
            $this->addError('fechaTodas', 'Elige una fecha válida.');

            return;
        }
        foreach (array_map('intval', $this->seleccion) as $id) {
            $this->fechasEntrega['c'.$id] = $this->fechaTodas;
        }
        foreach (array_keys($this->extras) as $i) {
            $this->fechasEntrega['e'.$i] = $this->fechaTodas;
        }
    }

    private function fechaEntrega(string $clave): ?string
    {
        $f = trim((string) ($this->fechasEntrega[$clave] ?? ''));

        return $f !== '' && strtotime($f) !== false ? date('Y-m-d', strtotime($f)) : null;
    }

    public function render()
    {
        $texto = trim($this->buscarCliente);
        $partidas = null;
        $enPedido = collect();
        if ($this->clienteId) {
            $partidas = $this->partidasQuery()->paginate(25);
            $enPedido = PedidoPartida::whereIn('cotizacion_partida_id', $partidas->pluck('id'))
                ->whereHas('pedido')
                ->with('pedido:id,folio')
                ->get(['id', 'pedido_id', 'cotizacion_partida_id'])
                ->groupBy('cotizacion_partida_id');
        }

        return view('livewire.pedidos.nuevo', [
            'cliente' => $this->clienteId ? Cliente::find($this->clienteId, ['id', 'nombre']) : null,
            'clientesBusqueda' => Cliente::whereIn('id', $this->clientes())->orderBy('nombre')->get(['id', 'nombre']),
            'resultados' => $texto !== ''
                ? Cliente::where('nombre', 'like', '%'.mb_strtoupper($texto).'%')->whereNotIn('id', $this->clientes())->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'partidas' => $partidas,
            'enPedido' => $enPedido,
            'proveedores' => $this->clienteId ? Proveedor::orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'usuarios' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'duplicado' => $this->duplicado(),
            'conciliacion' => $c = $this->conciliacion(),
            'resultadoOc' => $res = $this->resultado($c),
            'diferencias' => $dif = ($res ? LecturaOc::explicar($res) : []),
            // Para cada renglón de la OC que falta: partidas parecidas de las cotizaciones del cliente.
            'parecidas' => collect($dif)->where('tipo', 'falta_en_pedido')->mapWithKeys(fn ($d) => [$d['clave'] => $this->parecidasA($d['renglon'])])->all(),
            'preciosUsados' => CotizacionPartida::whereIn('id', array_map('intval', $this->seleccion))->get(['id', 'precio'])
                ->mapWithKeys(fn ($p) => [$p->id => ['usado' => $this->precioDe($p), 'cambio' => $this->cambioPrecio($p), 'menor' => $this->cambioMenor($p)]])->all(),
            'esAdmin' => auth()->user()->esAdmin(),
            'precioConAutorizacion' => CambiosPrecio::requiereAutorizacion(),
            'marcadas' => $this->seleccion !== [] ? CotizacionPartida::with('cotizacion:id,folio')->whereIn('id', array_map('intval', $this->seleccion))
                ->orderBy('cotizacion_id')->orderBy('orden')->get(['id', 'cotizacion_id', 'descripcion', 'cantidad', 'unidad', 'precio']) : collect(),
            'faltaParaDatos' => $this->faltaParaDatos(),
            'sumaMarcadas' => $this->sumaMarcadas(),
            'minJustificacion' => self::MIN_JUSTIFICACION,
            'borrador' => $this->ofrecerBorrador ? PedidoBorrador::with('cliente:id,nombre')->where('user_id', auth()->id())->first() : null,
        ]);
    }
}
