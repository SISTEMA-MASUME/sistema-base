<?php

namespace App\Livewire\Finanzas;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\Venta;
use App\Models\VentaCambio;
use App\Models\VentaVinculo;
use App\Support\ComplementosSicar;
use App\Support\CostoSicarVsPedido;
use App\Support\CuadreDeposito;
use App\Support\LigasPorConfirmar;
use App\Support\Nombres;
use App\Support\Precios;
use App\Support\Semanas;
use App\Support\Trazabilidad;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ventas (Finanzas, fase 1): lo que era la hoja VENTAS del Sheets. Lista de facturas y notas con filtros,
 * totales (sin canceladas), pagos y estatus del portal en lote, y edición con bitácora.
 *
 * Permisos: entra quien tenga el permiso "ventas" (o el admin). Marcar pagadas, regresar a pendiente y el
 * estatus del portal los puede hacer cualquiera de ellos; editar montos/datos, capturar una venta nueva y
 * cancelar son solo del admin. Todo se valida también aquí en el servidor (los botones ocultos no bastan).
 */
#[Layout('layouts.app')]
#[Title('Ventas')]
class Ventas extends Component
{
    use WithPagination;

    public const POR_PAGINA = 50;

    /** Valor del filtro de año para ver todo el historial (Cobranza enlaza aquí con ?anio= vacío). */
    public const TODOS_LOS_ANIOS = 'todos';

    /** Tope de ventas que se tocan de un jalón (las palomeadas). */
    private const MAX_LOTE = 500;

    /** Las fechas capturadas van del 2000 a un mes adelante: así un año mal escrito (2062, 0026) no se cuela. */
    private const FECHA_MINIMA = '2000-01-01';

    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Montos del formulario (todos sin IVA salvo el total) y cómo se nombran en los mensajes. */
    private const MONTOS = [
        'costo' => 'El costo', 'subtotal' => 'La venta', 'iva' => 'El IVA', 'retencion' => 'La retención', 'total' => 'El total',
    ];

    /** Campos que se editan a mano: cada cambio se anota en la bitácora de la venta. */
    private const CAMPOS_EDITABLES = [
        'tipo', 'folio', 'fecha', 'cliente_nombre', 'concepto', 'costo', 'subtotal', 'iva', 'retencion', 'total',
        'estatus_portal', 'factura_sustituta', 'notas',
    ];

    /** Nombre de cada campo en el historial de cambios. */
    private const ETIQUETAS = [
        'alta' => 'Alta', 'tipo' => 'Tipo', 'folio' => 'Folio', 'fecha' => 'Fecha de la factura', 'cliente_nombre' => 'Cliente',
        'concepto' => 'Concepto', 'costo' => 'Costo', 'subtotal' => 'Venta (sin IVA)', 'iva' => 'IVA', 'retencion' => 'Retención',
        'total' => 'Total', 'estatus' => 'Estatus', 'fecha_pago' => 'Fecha de pago', 'cuenta' => 'Cuenta',
        'estatus_portal' => 'Estatus del portal', 'factura_sustituta' => 'Factura sustituta', 'notas' => 'Notas',
        'liga' => 'Liga', 'pedido' => 'Pedido',
    ];

    /** Valor del filtro de tipo que muestra las notas que todavía no se ligan a una factura. */
    public const NOTAS_SIN_FACTURAR = 'nota_sin_facturar';

    /** Vinieron de SICAR: no estaban en el Sheets de Daniel. */
    public const DE_SICAR = 'de_sicar';

    /** Pagadas aquí pero SICAR dice que aún deben: revisar. */
    public const REVISAR_SICAR = 'revisar_sicar';

    // ===== Filtros (viven en la dirección para poder compartir o regresar a la misma vista) =====

    #[Url]
    public string $anio = '';

    #[Url]
    public string $mes = '';

    #[Url]
    public string $buscar = '';

    #[Url]
    public string $estatus = '';

    #[Url]
    public string $portal = '';

    #[Url]
    public string $tipo = '';

    #[Url]
    public string $semana = '';

    /** ?ver=ID abre el detalle de esa venta al entrar (lo usa el buscador de folios de Cobranza). */
    #[Url(as: 'ver')]
    public ?int $ver = null;

    // ===== Selección y acciones en lote =====

    /** @var list<int|string> ids de las ventas palomeadas */
    public array $seleccion = [];

    /** Ventana abierta de las acciones en lote: '' | 'pagar' | 'portal' | 'ligar'. */
    public string $panelLote = '';

    public string $fechaPago = '';

    public string|int|null $cuentaId = null;

    public string $cuentaNueva = '';

    /** Estatus del portal para el lote: una clave de Venta::PORTAL o 'sin' (quitarlo). */
    public string $portalLote = '';

    /** Folio de la factura a la que se ligan las seleccionadas (lote 'ligar'). */
    public string $folioDestino = '';

    // ===== Detalle (cualquiera con permiso 'ventas') y trazabilidad =====

    public ?int $detalleId = null;

    /** Folio de pedido que se escribe en el detalle ("P-60" o "60"). */
    public string $folioPedido = '';

    /** Resultado de la última acción del detalle (la ventana tapa los mensajes de la página). */
    public string $mensajeDetalle = '';

    // ===== Edición / captura (solo admin) =====

    public bool $mostrarForm = false;

    public ?int $editandoId = null;

    /** @var array<string, string> */
    public array $form = [];

    /** ¿Qué facturas suman este depósito? (con el cliente filtrado en el buscador) */
    public string $montoDeposito = '';

    public bool $cuadreConPagadas = false;

    /** Resultado del último cálculo (ids y sumas en centavos) o null. */
    #[Locked]
    public ?array $cuadre = null;

    public string $mensaje = '';

    public string $error = '';

    // ------------------------------------------------------------------------------------------------

    public function mount(): void
    {
        $this->autorizar();
        // Sin ?anio= se abre el año actual; con ?anio= vacío (así enlaza Cobranza) o "todos", todo el historial.
        $todos = $this->anio === self::TODOS_LOS_ANIOS || ($this->anio === '' && array_key_exists('anio', request()->query()));
        $this->anio = $todos ? self::TODOS_LOS_ANIOS : (string) ($this->anioActivo() ?? now()->year);
        $this->fechaPago = now()->toDateString();
        if ($this->ver && Venta::whereKey($this->ver)->exists()) {
            $this->verDetalle($this->ver);
        }
        $this->ver = null;
        $this->form = $this->formVacio();
    }

    /** Cada petición de Livewire vuelve a comprobar el permiso (si se lo quitan, ya no puede ni mover filtros). */
    public function hydrate(): void
    {
        $this->autorizar();
    }

    public function updated(string $propiedad): void
    {
        if (! in_array($propiedad, ['anio', 'mes', 'buscar', 'estatus', 'portal', 'tipo', 'semana'], true)) {
            return;
        }
        if ($propiedad === 'anio') {
            $this->anio = $this->anio === '' ? self::TODOS_LOS_ANIOS : $this->anio;
            // Mes y semana son de un año: con "todos los años" no aplican; y una semana que el año nuevo no tiene (la 53) se quita.
            $anio = $this->anioActivo();
            if ($anio === null) {
                $this->reset(['mes', 'semana']);
            } elseif ($this->semana !== '' && (int) $this->semana > Semanas::delAnio($anio)) {
                $this->semana = '';
            }
        }
        $this->seleccion = [];
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->autorizar();
        $this->reset(['buscar', 'mes', 'estatus', 'portal', 'tipo', 'semana']);
        $this->anio = (string) now()->year;
        $this->seleccion = [];
        $this->resetPage();
    }

    private function autorizar(bool $soloAdmin = false): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->puede('ventas'), 403);
        abort_if($soloAdmin && ! $usuario->esAdmin(), 403);
    }

    // ===== Filtros: valores ya validados =====

    /** El año elegido; null = todos los años. Un valor raro cuenta como el año actual. */
    private function anioActivo(): ?int
    {
        if ($this->anio === '' || $this->anio === self::TODOS_LOS_ANIOS) {
            return null;
        }
        $anio = (int) $this->anio;

        return $anio >= 2000 && $anio <= 2100 ? $anio : (int) now()->year;
    }

    private function mesActivo(): ?int
    {
        $mes = (int) $this->mes;

        return $mes >= 1 && $mes <= 12 ? $mes : null;
    }

    /** La semana elegida (necesita un año). */
    private function semanaActiva(): ?int
    {
        $anio = $this->anioActivo();
        if ($this->semana === '' || $anio === null) {
            return null;
        }
        $semana = (int) $this->semana;

        return $semana >= 1 && $semana <= Semanas::delAnio($anio) ? $semana : null;
    }

    /** Ventas que cumplen los filtros (sin orden ni relaciones). */
    private function consulta(): Builder
    {
        $q = Venta::query();
        $anio = $this->anioActivo();

        if (($semana = $this->semanaActiva()) !== null) {
            // Cobradas en la semana de comisión: sin importar el mes, el estatus ni cuándo se facturaron.
            $q->pagadasEnSemana($anio, $semana);
        } else {
            if ($anio !== null) {
                $mes = $this->mesActivo();
                $desde = Carbon::create($anio, $mes ?? 1, 1)->startOfDay();
                $hasta = $mes ? $desde->copy()->endOfMonth() : Carbon::create($anio, 12, 31);
                // ' 23:59:59': en SQLite las fechas se guardan con hora y '2026-09-30 00:00:00' > '2026-09-30'.
                $q->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']);
            }
            $q->when(isset(Venta::ESTATUS[$this->estatus]), fn ($q) => $q->where('estatus', $this->estatus));
        }

        // Cada palabra debe aparecer en el cliente, el folio o el concepto.
        $palabras = array_filter(preg_split('/\s+/u', trim(str_replace(['%', '_'], '', $this->buscar))) ?: []);
        foreach (array_slice($palabras, 0, 6) as $palabra) {
            $q->where(fn ($w) => $w->where('cliente_nombre', 'like', "%{$palabra}%")
                ->orWhere('folio', 'like', "%{$palabra}%")
                ->orWhere('concepto', 'like', "%{$palabra}%"));
        }

        if ($this->portal === 'sin') {
            $q->whereNull('estatus_portal');
        } elseif (isset(Venta::PORTAL[$this->portal])) {
            $q->where('estatus_portal', $this->portal);
        }

        if ($this->tipo === self::NOTAS_SIN_FACTURAR) {
            $q->where('tipo', 'nota')->where('estatus', '!=', 'cancelada')->whereDoesntHave('reemplazadaPor');
        } elseif ($this->tipo === self::DE_SICAR) {
            $q->where('origen', 'sicar')->where('estatus', '!=', 'cancelada');
        } elseif ($this->tipo === self::REVISAR_SICAR) {
            $q->where('estatus', 'pagada')->where('saldo_sicar', '>', 0.5);
        } else {
            $q->when(isset(Venta::TIPOS[$this->tipo]), fn ($q) => $q->where('tipo', $this->tipo));
        }

        return $q;
    }

    /**
     * Totales de TODO el filtro (no solo de la página) sin las canceladas, con sumas que valen igual en SQLite y
     * MariaDB. Margen = utilidad ÷ costo, como la columna PORCENTAJE del Sheets.
     *
     * @return array{n: int, venta: float, costo: float, utilidad: float, margen: ?float, total: float, canceladas: int}
     */
    private function totales(Builder $base): array
    {
        $t = (clone $base)->vigentes()->toBase()
            ->selectRaw('COUNT(*) as n, SUM(subtotal) as venta, SUM(costo) as costo, SUM(total) as total')
            ->first();

        $venta = round((float) ($t->venta ?? 0), 2);
        $costo = round((float) ($t->costo ?? 0), 2);
        $utilidad = round($venta - $costo, 2);

        return [
            'n' => (int) ($t->n ?? 0),
            'venta' => $venta,
            'costo' => $costo,
            'utilidad' => $utilidad,
            'margen' => $costo > 0 ? $utilidad / $costo : null,
            'total' => round((float) ($t->total ?? 0), 2),
            'canceladas' => (clone $base)->where('estatus', 'cancelada')->count(),
        ];
    }

    /** @return list<int> años con ventas más el actual (y el elegido), del más nuevo al más viejo */
    private function aniosDisponibles(): array
    {
        return Venta::query()->selectRaw('SUBSTR(fecha, 1, 4) as anio')->distinct()->pluck('anio')
            ->map(fn ($a) => (int) $a)->filter(fn (int $a) => $a >= 2000)
            ->push((int) now()->year, $this->anioActivo() ?? (int) now()->year)
            ->unique()->sortDesc()->values()->all();
    }

    // ===== Selección y acciones en lote =====

    /** @return list<int> */
    private function idsSeleccionados(): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->seleccion))));

        return array_slice($ids, 0, self::MAX_LOTE);
    }

    /** Casilla "seleccionar los de esta página": si ya están todas las quita, si no las agrega. */
    public function alternarPagina(): void
    {
        $this->autorizar();
        $ids = $this->consulta()->orderByDesc('fecha')->orderByDesc('id')
            ->forPage($this->getPage(), self::POR_PAGINA)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $actuales = $this->idsSeleccionados();

        $this->seleccion = $ids && ! array_diff($ids, $actuales)
            ? array_values(array_diff($actuales, $ids))
            : array_values(array_unique(array_merge($actuales, $ids)));
    }

    /**
     * Calcula qué facturas/notas del filtro actual suman exacto el depósito (o lo más cercano). Aritmética en PHP,
     * sin IA. Toma el saldo de SICAR si la factura está parcialmente pagada.
     */
    public function calcularDeposito(): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $this->cuadre = null;
        $monto = (float) str_replace([',', '$', ' '], '', $this->montoDeposito);
        if ($monto <= 0) {
            $this->error = 'Escribe el monto del depósito, por ejemplo 43477.17.';

            return;
        }
        if (trim($this->buscar) === '') {
            $this->error = 'Primero escribe el cliente en el buscador (por ejemplo PLAZA NORTE) para que solo se combinen sus facturas.';

            return;
        }
        $estatus = $this->cuadreConPagadas ? ['pendiente', 'pagada'] : ['pendiente'];
        $candidatas = $this->consulta()->whereIn('estatus', $estatus)->orderBy('fecha')->orderBy('id')
            ->limit(CuadreDeposito::MAX_DOCUMENTOS + 1)->get(['id', 'total', 'saldo_sicar', 'estatus']);
        if ($candidatas->isEmpty()) {
            $this->error = 'Con estos filtros no hay facturas '.($this->cuadreConPagadas ? 'pendientes ni pagadas' : 'pendientes').'. Revisa el año (puedes poner "Todos").';

            return;
        }
        if ($candidatas->count() > CuadreDeposito::MAX_DOCUMENTOS) {
            $this->error = 'Hay más de '.CuadreDeposito::MAX_DOCUMENTOS.' facturas con estos filtros: escribe el cliente completo o elige el año o el mes.';

            return;
        }
        $importes = [];
        foreach ($candidatas as $v) {
            $importes[$v->id] = (int) round(self::importeACobrar($v) * 100);
        }
        $r = CuadreDeposito::buscar($importes, (int) round($monto * 100));
        $this->cuadre = ['objetivo' => (int) round($monto * 100), 'candidatas' => count($importes)] + $r;
    }

    /** Lo que falta por cobrar de una venta: el saldo de SICAR si es parcial; si no, el total. */
    private static function importeACobrar(Venta $v): float
    {
        $saldo = $v->saldo_sicar !== null ? (float) $v->saldo_sicar : null;

        return $v->estatus === 'pendiente' && $saldo !== null && $saldo > 0.005 && $saldo < (float) $v->total - 0.005 ? $saldo : (float) $v->total;
    }

    /** Palomea las facturas de una combinación para usar "Marcar pagadas" (o revisarlas). */
    public function seleccionarCuadre(string $cual, int $indice = 0): void
    {
        $this->autorizar();
        $ids = match ($cual) {
            'exacta' => $this->cuadre['exactas'][$indice] ?? [],
            'abajo' => $this->cuadre['abajo']['ids'] ?? [],
            'arriba' => $this->cuadre['arriba']['ids'] ?? [],
            default => [],
        };
        $this->seleccion = array_values(array_map('intval', $ids));
        $this->mensaje = count($ids).' '.(count($ids) === 1 ? 'factura palomeada' : 'facturas palomeadas').'. Revísalas abajo y, si son, usa "Marcar pagadas".';
    }

    /** @return list<int> */
    private function idsDelCuadre(): array
    {
        $ids = [];
        foreach ($this->cuadre['exactas'] ?? [] as $combo) {
            $ids = array_merge($ids, $combo);
        }
        foreach (['abajo', 'arriba'] as $k) {
            $ids = array_merge($ids, $this->cuadre[$k]['ids'] ?? []);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function cerrarCuadre(): void
    {
        $this->cuadre = null;
    }

    public function abrirLote(string $panel): void
    {
        $this->autorizar();
        abort_unless(in_array($panel, ['pagar', 'portal', 'ligar'], true), 422);
        $this->mensaje = '';
        $this->error = '';
        $this->resetValidation();
        if (! $this->idsSeleccionados()) {
            $this->error = 'Marca primero las ventas que quieres cambiar.';

            return;
        }
        if ($panel === 'pagar') {
            $this->fechaPago = now()->toDateString();
        }
        if ($panel === 'ligar') {
            $this->folioDestino = '';
        }
        $this->panelLote = $panel;
    }

    public function cerrarLote(): void
    {
        $this->panelLote = '';
        $this->resetValidation();
    }

    /** Pendientes → pagadas con fecha y cuenta. Las que ya estaban pagadas o canceladas no se tocan. */
    public function marcarPagadas(): void
    {
        $this->autorizar();
        $this->validate([
            'fechaPago' => ['required', 'date_format:Y-m-d', ...$this->limitesDeFecha()],
            'cuentaId' => ['nullable', Rule::exists('cuentas', 'id')],
            'cuentaNueva' => ['nullable', 'string', 'max:80'],
        ], [
            'fechaPago.required' => 'Elige la fecha en que se pagó.',
            'fechaPago.date_format' => 'La fecha de pago no es válida.',
            'fechaPago.after_or_equal' => 'La fecha de pago parece mal escrita: revisa el año.',
            'fechaPago.before_or_equal' => 'La fecha de pago parece mal escrita: revisa el año.',
            'cuentaId.exists' => 'Esa cuenta ya no existe; elige otra.',
            'cuentaNueva.max' => 'El nombre de la cuenta es muy largo (máximo 80 letras).',
        ]);

        $ids = $this->idsSeleccionados();
        if (! $ids) {
            $this->terminarLote(0, 0, '', '', '');

            return;
        }

        $ventas = Venta::query()->whereIn('id', $ids)->where('estatus', 'pendiente')->get();
        $razon = 'ya estaban pagadas o canceladas';
        if ($ventas->isEmpty()) {
            $this->terminarLote(0, count($ids), '', '', $razon);

            return;
        }

        $cuenta = filled($this->cuentaNueva) ? Cuenta::obtener($this->cuentaNueva)
            : (filled($this->cuentaId) ? Cuenta::find((int) $this->cuentaId) : null);
        $fecha = $this->fechaPago;

        DB::transaction(function () use ($ventas, $fecha, $cuenta) {
            foreach ($ventas as $venta) {
                $venta = Venta::lockForUpdate()->findOrFail($venta->id);
                if ($venta->deposito_cobro_id || $venta->estatus !== 'pendiente') {
                    ComplementosSicar::error('Una factura cambió o pertenece a un depósito. Revisa Depósitos antes de marcarla pagada.');
                }
                $venta->update(['estatus' => 'pagada', 'fecha_pago' => $fecha, 'cuenta_id' => $cuenta?->id, 'pago_registrado_at' => now()]);
                $venta->anotar('estatus', 'pendiente', 'pagada');
                $venta->anotar('fecha_pago', null, $fecha);
                $venta->anotar('cuenta', null, $cuenta?->nombre);
            }
        });

        $cuando = 'el '.Carbon::parse($fecha)->format('d/m/Y').($cuenta ? ' en '.$cuenta->nombre : '');
        $this->terminarLote($ventas->count(), count($ids), "venta quedó pagada {$cuando}", "ventas quedaron pagadas {$cuando}", $razon);
        $this->reset(['cuentaId', 'cuentaNueva']);
    }

    /**
     * Pagadas → pendientes (quita fecha de pago y cuenta). Las canceladas solo las revive el admin, porque
     * cancelar también es solo del admin.
     */
    public function regresarPendiente(): void
    {
        $this->autorizar();
        $ids = $this->idsSeleccionados();
        if (! $ids) {
            $this->terminarLote(0, 0, '', '', '');

            return;
        }

        $esAdmin = auth()->user()->esAdmin();
        $ventas = Venta::query()->with('cuenta:id,nombre')->whereIn('id', $ids)
            ->whereIn('estatus', $esAdmin ? ['pagada', 'cancelada'] : ['pagada'])->get();

        DB::transaction(function () use ($ventas) {
            foreach ($ventas as $venta) {
                $venta = Venta::lockForUpdate()->findOrFail($venta->id);
                if ($venta->deposito_cobro_id) {
                    ComplementosSicar::error('Corrige esta factura desde Depósitos para conservar la asignación y las comisiones.');
                }
                $antes = ['estatus' => $venta->estatus, 'fecha_pago' => $venta->fecha_pago?->toDateString(), 'cuenta' => $venta->cuenta?->nombre];
                $venta->update(['estatus' => 'pendiente', 'fecha_pago' => null, 'cuenta_id' => null, 'pago_registrado_at' => null]);
                $venta->anotar('estatus', $antes['estatus'], 'pendiente');
                $venta->anotar('fecha_pago', $antes['fecha_pago'], null);
                $venta->anotar('cuenta', $antes['cuenta'], null);
            }
        });

        $this->terminarLote($ventas->count(), count($ids), 'venta regresó a pendiente', 'ventas regresaron a pendiente',
            $esAdmin ? 'ya estaban pendientes' : 'ya estaban pendientes o canceladas');
    }

    /** Cambia el estatus del portal de UNA venta desde su lista en la tabla ('' lo quita). */
    public function cambiarPortal(int $id, string $valor): void
    {
        $this->autorizar();
        $nuevo = $valor === '' ? null : $valor;
        if ($nuevo !== null && ! isset(Venta::PORTAL[$nuevo])) {
            return;
        }
        $venta = Venta::findOrFail($id);
        $antes = $venta->estatus_portal;
        if ($antes === $nuevo) {
            return;
        }
        $venta->update(['estatus_portal' => $nuevo]);
        $venta->anotar('estatus_portal', $antes, $nuevo);
        $this->error = '';
        $this->mensaje = $venta->folio.': portal '.($nuevo ? '"'.Venta::PORTAL[$nuevo].'"' : 'sin estatus').'.';
    }

    /** Estatus del portal del cliente para todas las palomeadas ('sin' lo quita). */
    public function aplicarPortal(): void
    {
        $this->autorizar();
        $this->validate([
            'portalLote' => ['required', Rule::in(['sin', ...array_keys(Venta::PORTAL)])],
        ], [
            'portalLote.required' => 'Elige el estatus del portal.',
            'portalLote.in' => 'Elige un estatus de la lista.',
        ]);

        $ids = $this->idsSeleccionados();
        if (! $ids) {
            $this->terminarLote(0, 0, '', '', '');

            return;
        }

        $nuevo = $this->portalLote === 'sin' ? null : $this->portalLote;
        $ventas = Venta::query()->whereIn('id', $ids)->get()->filter(fn (Venta $v) => $v->estatus_portal !== $nuevo);

        DB::transaction(function () use ($ventas, $nuevo) {
            foreach ($ventas as $venta) {
                $antes = $venta->estatus_portal;
                $venta->update(['estatus_portal' => $nuevo]);
                $venta->anotar('estatus_portal', $antes, $nuevo);
            }
        });

        $queSePuso = $nuevo ? 'con el estatus de portal "'.Venta::PORTAL[$nuevo].'"' : 'sin estatus de portal';
        $this->terminarLote($ventas->count(), count($ids), "venta quedó {$queSePuso}", "ventas quedaron {$queSePuso}", 'ya tenían ese estatus');
        $this->portalLote = '';
    }

    /**
     * Cierra la ventana del lote, limpia las palomitas y deja el resultado en $mensaje (o en $error si no se
     * cambió nada).
     */
    private function terminarLote(int $cambiadas, int $pedidas, string $singular, string $plural, string $razon): void
    {
        $this->panelLote = '';
        $this->seleccion = [];
        $this->mensaje = '';
        $this->error = '';

        if ($pedidas === 0) {
            $this->error = 'Marca primero las ventas que quieres cambiar.';
        } elseif ($cambiadas === 0) {
            $this->error = 'No se cambió ninguna: todas las seleccionadas '.$razon.'.';
        } else {
            $resto = $pedidas - $cambiadas;
            $this->mensaje = $cambiadas.' '.($cambiadas === 1 ? $singular : $plural).'.'
                .($resto > 0 ? ' Se dejaron igual '.$resto.' que '.$razon.'.' : '');
        }
    }

    // ===== Trazabilidad: ligar a una factura (lote) =====

    /** El folio escrito → la factura (o, si no hay, la nota: Trazabilidad avisa que a una nota no se liga). */
    private function buscarDestino(string $folio): ?Venta
    {
        return Venta::where('folio_llave', Venta::llave('factura', $folio))->first()
            ?? Venta::where('folio_llave', Venta::llave('nota', $folio))->first();
    }

    /** Las palomeadas quedan reemplazadas por la factura escrita (se cancelan y la factura puede heredar el cobro). */
    public function ligarSeleccion(): void
    {
        $this->autorizar();
        $this->validate(['folioDestino' => ['required', 'string', 'max:40']], [
            'folioDestino.required' => 'Escribe el folio de la factura.',
            'folioDestino.max' => 'El folio es muy largo (máximo 40 caracteres).',
        ]);

        $ids = $this->idsSeleccionados();
        if (! $ids) {
            $this->terminarLote(0, 0, '', '', '');

            return;
        }

        $folio = trim($this->folioDestino);
        $destino = $this->buscarDestino($folio);
        if (! $destino) {
            $this->addError('folioDestino', 'No encontré el folio "'.$folio.'" en Ventas. Revisa que esté bien escrito.');

            return;
        }

        // Lo que ya está ligado a otra factura se deja igual (primero hay que deshacer esa liga desde su detalle).
        $origenes = Venta::query()->whereIn('id', $ids)->where('id', '!=', $destino->id)->whereDoesntHave('reemplazadaPor')->get();
        $omitidas = count($ids) - $origenes->count();
        if ($origenes->isEmpty()) {
            $this->addError('folioDestino', 'Las seleccionadas ya están ligadas a otra factura (o son la misma factura). Deshaz esa liga desde su detalle.');

            return;
        }

        $estatusAntes = $destino->estatus;
        try {
            $n = Trazabilidad::ligar($origenes, $destino);
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
            $this->addError('folioDestino', $e->getMessage());

            return;
        }

        $destino->refresh();
        $this->panelLote = '';
        $this->seleccion = [];
        $this->folioDestino = '';
        $this->error = '';
        $this->resetValidation();
        $this->mensaje = $n === 0
            ? 'Esas ya estaban ligadas a '.$destino->folio.'. No se cambió nada.'
            : $n.($n === 1 ? ' documento quedó ligado a ' : ' documentos quedaron ligados a ').$destino->folio.'.'
                .($estatusAntes === 'pendiente' && $destino->estatus === 'pagada' && $destino->fecha_pago
                    ? ' La factura tomó el cobro del '.$destino->fecha_pago->format('d/m/Y').'.' : '')
                .($omitidas > 0 ? ' Se dejaron igual '.$omitidas.' que ya estaban ligadas a otra factura.' : '');
    }

    /** Facturas vigentes para elegir destino: las del mismo cliente que las palomeadas primero; por folio o cliente; máximo 10. */
    private function sugerenciasDestino(): Collection
    {
        $ids = $this->idsSeleccionados();
        $clientes = Venta::query()->whereIn('id', $ids)->pluck('cliente_nombre')->unique()->values()->all();

        $q = Venta::query()->where('tipo', 'factura')->vigentes()->whereNotIn('id', $ids);
        $palabras = array_filter(preg_split('/\s+/u', trim(str_replace(['%', '_'], '', $this->folioDestino))) ?: []);
        foreach (array_slice($palabras, 0, 4) as $palabra) {
            $q->where(fn ($w) => $w->where('folio', 'like', "%{$palabra}%")->orWhere('cliente_nombre', 'like', "%{$palabra}%"));
        }
        if ($clientes) {
            $q->orderByRaw('CASE WHEN cliente_nombre IN ('.implode(',', array_fill(0, count($clientes), '?')).') THEN 0 ELSE 1 END', $clientes);
        }

        return $q->orderByDesc('fecha')->orderByDesc('id')->limit(10)->get(['id', 'folio', 'fecha', 'cliente_nombre', 'total', 'estatus'])
            ->each(fn (Venta $v) => $v->setAttribute('mismo_cliente', in_array($v->cliente_nombre, $clientes, true)));
    }

    /** Clic en una sugerencia: pone su folio en el campo. */
    public function elegirDestino(int $id): void
    {
        $this->autorizar();
        $v = Venta::query()->where('tipo', 'factura')->find($id);
        if ($v) {
            $this->folioDestino = $v->folio;
            $this->resetValidation('folioDestino');
        }
    }

    // ===== Detalle y trazabilidad de una venta =====

    public function verDetalle(int $id): void
    {
        $this->autorizar();
        Venta::findOrFail($id);
        $this->resetValidation();
        $this->mensajeDetalle = '';
        $this->folioPedido = '';
        $this->detalleId = $id;
    }

    public function cerrarDetalle(): void
    {
        $this->detalleId = null;
        $this->mensajeDetalle = '';
        $this->folioPedido = '';
        $this->resetValidation();
    }

    /** Deshace una liga de la venta abierta (la de lo que reemplaza o la de por quién fue reemplazada). */
    public function deshacerLiga(int $vinculoId): void
    {
        $this->autorizar();
        abort_unless($this->detalleId, 422);
        $v = VentaVinculo::query()->with(['origen', 'destino'])
            ->where(fn ($q) => $q->where('origen_id', $this->detalleId)->orWhere('destino_id', $this->detalleId))
            ->findOrFail($vinculoId);

        Trazabilidad::desligar($v);
        $this->resetValidation();
        $this->mensajeDetalle = 'Se deshizo la liga: '.($v->origen?->folio ?? 'el documento').' ya no está reemplazado por '.($v->destino?->folio ?? 'la factura').'.';
    }

    /** "P-60" o "60" → el pedido con ese folio. */
    public function ligarAPedido(): void
    {
        $this->autorizar();
        abort_unless($this->detalleId, 422);
        $this->validate(['folioPedido' => ['required', 'string', 'max:20']], [
            'folioPedido.required' => 'Escribe el folio del pedido, por ejemplo P-60.',
            'folioPedido.max' => 'El folio es muy largo.',
        ]);

        $venta = Venta::findOrFail($this->detalleId);
        $this->mensajeDetalle = '';
        if (! preg_match('/^\s*(?:P\s*-?\s*)?(\d+)\s*$/i', $this->folioPedido, $m)) {
            $this->addError('folioPedido', 'Escribe el folio del pedido como P-60 o solo 60.');

            return;
        }
        $pedido = Pedido::with('cliente:id,nombre,nombre_llave')->where('folio', (int) $m[1])->first();
        if (! $pedido) {
            $this->addError('folioPedido', 'No encontré el pedido P-'.(int) $m[1].'.');

            return;
        }
        if ($pedido->ventas()->whereKey($venta->id)->exists()) {
            $this->mensajeDetalle = 'Ya estaba ligada al pedido P-'.$pedido->folio.'.';
            $this->folioPedido = '';

            return;
        }

        Trazabilidad::ligarPedido($pedido, $venta);
        PedidoActividad::registrar($pedido, 'factura_ligada', 'ligó '.($venta->tipo === 'nota' ? 'la nota ' : 'la factura ').$venta->folio.' a esta tarjeta (desde Ventas)');

        $otroCliente = $venta->cliente_id
            ? (int) $venta->cliente_id !== (int) $pedido->cliente_id
            : Nombres::llave($venta->cliente_nombre) !== (string) $pedido->cliente?->nombre_llave;
        $this->mensajeDetalle = 'Quedó ligada al pedido P-'.$pedido->folio.'.'
            .($otroCliente ? ' Ojo: el pedido es de otro cliente ('.($pedido->cliente?->nombre ?? 'sin cliente').').' : '');
        $this->folioPedido = '';
    }

    public function quitarPedido(int $pedidoId): void
    {
        $this->autorizar();
        abort_unless($this->detalleId, 422);
        $venta = Venta::findOrFail($this->detalleId);
        $pedido = $venta->pedidos()->where('pedidos.id', $pedidoId)->first();
        if (! $pedido) {
            return;
        }

        Trazabilidad::desligarPedido($pedido, $venta);
        PedidoActividad::registrar($pedido, 'factura_ligada', 'quitó '.($venta->tipo === 'nota' ? 'la nota ' : 'la factura ').$venta->folio.' de esta tarjeta (desde Ventas)');
        $this->mensajeDetalle = 'Se quitó el pedido P-'.$pedido->folio.' de esta venta.';
    }

    // ===== Edición y captura manual (solo admin) =====

    /** @return array<string, string> */
    private function formVacio(): array
    {
        return [
            'tipo' => 'factura', 'folio' => '', 'fecha' => now()->toDateString(), 'cliente_nombre' => '', 'concepto' => '',
            'costo' => '', 'subtotal' => '', 'iva' => '', 'retencion' => '', 'total' => '',
            'estatus_portal' => '', 'factura_sustituta' => '', 'notas' => '',
        ];
    }

    public function nueva(): void
    {
        $this->autorizar(true);
        $this->resetValidation();
        $this->mensaje = '';
        $this->error = '';
        $this->form = $this->formVacio();
        $this->editandoId = null;
        $this->mostrarForm = true;
    }

    public function editar(int $id): void
    {
        $this->autorizar(true);
        $venta = Venta::findOrFail($id);

        $this->cerrarDetalle();
        $this->mensaje = '';
        $this->error = '';
        $this->form = [
            'tipo' => $venta->tipo, 'folio' => $venta->folio, 'fecha' => $venta->fecha->format('Y-m-d'),
            'cliente_nombre' => $venta->cliente_nombre, 'concepto' => (string) $venta->concepto,
            'costo' => $this->monto($venta->costo), 'subtotal' => $this->monto($venta->subtotal), 'iva' => $this->monto($venta->iva),
            'retencion' => $this->monto($venta->retencion), 'total' => $this->monto($venta->total),
            'estatus_portal' => (string) $venta->estatus_portal, 'factura_sustituta' => (string) $venta->factura_sustituta,
            'notas' => (string) $venta->notas,
        ];
        $this->editandoId = $venta->id;
        $this->mostrarForm = true;
    }

    public function cerrarForm(): void
    {
        $this->mostrarForm = false;
        $this->editandoId = null;
        $this->resetValidation();
    }

    /** IVA = 16% de la venta y total = venta + IVA − retención, con lo que haya en el formulario. */
    public function recalcularMontos(): void
    {
        $this->autorizar(true);
        $f = $this->formCompleto();
        $subtotal = $this->numero($f['subtotal']);
        $iva = round($subtotal * Precios::iva(), 2);
        $this->form['iva'] = $this->monto($iva);
        $this->form['total'] = $this->monto(max(0, round($subtotal + $iva - $this->numero($f['retencion']), 2)));
    }

    /** @return list<string> */
    private function limitesDeFecha(): array
    {
        return ['after_or_equal:'.self::FECHA_MINIMA, 'before_or_equal:'.now()->addMonth()->toDateString()];
    }

    protected function rules(): array
    {
        $monto = ['nullable', 'numeric', 'min:0', 'max:999999999999.99'];

        return [
            'form.tipo' => ['required', Rule::in(array_keys(Venta::TIPOS))],
            'form.folio' => ['required', 'string', 'max:40'],
            'form.fecha' => ['required', 'date_format:Y-m-d', ...$this->limitesDeFecha()],
            'form.cliente_nombre' => ['required', 'string', 'max:255'],
            'form.concepto' => ['nullable', 'string', 'max:5000'],
            'form.costo' => $monto,
            'form.subtotal' => $monto,
            'form.iva' => $monto,
            'form.retencion' => $monto,
            'form.total' => $monto,
            'form.estatus_portal' => ['nullable', Rule::in(array_keys(Venta::PORTAL))],
            'form.factura_sustituta' => ['nullable', 'string', 'max:40'],
            'form.notas' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function messages(): array
    {
        $mensajes = [
            'form.tipo.required' => 'Elige si es factura o nota de venta.',
            'form.tipo.in' => 'Elige si es factura o nota de venta.',
            'form.folio.required' => 'Escribe el folio.',
            'form.folio.max' => 'El folio es muy largo (máximo 40 caracteres).',
            'form.fecha.required' => 'Elige la fecha de la factura.',
            'form.fecha.date_format' => 'La fecha no es válida.',
            'form.fecha.after_or_equal' => 'La fecha parece mal escrita: revisa el año.',
            'form.fecha.before_or_equal' => 'La fecha parece mal escrita: revisa el año.',
            'form.cliente_nombre.required' => 'Escribe el nombre del cliente.',
            'form.cliente_nombre.max' => 'El nombre del cliente es muy largo.',
            'form.concepto.max' => 'El concepto es muy largo (máximo 5,000 letras).',
            'form.estatus_portal.in' => 'Elige un estatus de la lista.',
            'form.factura_sustituta.max' => 'El folio de la factura sustituta es muy largo (máximo 40 caracteres).',
            'form.notas.max' => 'Las notas son muy largas (máximo 5,000 letras).',
        ];
        foreach (self::MONTOS as $campo => $nombre) {
            $mensajes["form.{$campo}.numeric"] = "{$nombre} debe ser un número, por ejemplo 1250.50.";
            $mensajes["form.{$campo}.min"] = "{$nombre} no puede ser negativo.";
            $mensajes["form.{$campo}.max"] = "{$nombre} es demasiado grande.";
        }

        return $mensajes;
    }

    /** El formulario con todas sus claves (un navegador alterado podría mandarlo incompleto). */
    private function formCompleto(): array
    {
        return array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_merge($this->formVacio(), $this->form));
    }

    /**
     * Guarda la venta (nueva o la que se edita) y anota en la bitácora cada campo que cambió. Si cambia la venta
     * (subtotal) y no se tocaron IVA ni total, los recalcula solos.
     */
    public function guardar(): void
    {
        $this->autorizar(true);
        $this->validate();

        $venta = $this->editandoId ? Venta::findOrFail($this->editandoId) : null;
        $f = $this->formCompleto();

        // Solo se normaliza lo que se escribió: lo que ya estaba guardado se respeta tal cual.
        $folio = $venta && $f['folio'] === $venta->folio ? $venta->folio : mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $f['folio'])));
        $cliente = $venta && $f['cliente_nombre'] === $venta->cliente_nombre ? $venta->cliente_nombre : Nombres::limpiar($f['cliente_nombre']);
        foreach (['form.folio' => [$folio, 'Escribe el folio.'], 'form.cliente_nombre' => [$cliente, 'Escribe el nombre del cliente.']] as $campo => [$valor, $texto]) {
            if ($valor === '') {
                $this->addError($campo, $texto);

                return;
            }
        }

        $repetida = Venta::withTrashed()->where('folio_llave', Venta::llave($f['tipo'], $folio))
            ->when($venta, fn ($q) => $q->where('id', '!=', $venta->id))->exists();
        if ($repetida) {
            $this->addError('form.folio', 'Ya existe otra venta con ese folio.');

            return;
        }

        [$costo, $subtotal, $iva, $retencion, $total] = array_map(fn ($c) => $this->numero($f[$c]), ['costo', 'subtotal', 'iva', 'retencion', 'total']);
        $recalcular = $venta
            ? ! $this->iguales($subtotal, $venta->subtotal) && $this->iguales($iva, $venta->iva) && $this->iguales($total, $venta->total)
            : $iva == 0 && $total == 0 && $subtotal > 0;
        if ($recalcular) {
            $iva = round($subtotal * Precios::iva(), 2);
            $total = max(0, round($subtotal + $iva - $retencion, 2));
        }

        $datos = [
            'tipo' => $f['tipo'],
            'folio' => $folio,
            'fecha' => $f['fecha'],
            'cliente_nombre' => $cliente,
            'concepto' => filled($f['concepto']) ? trim($f['concepto']) : null,
            'costo' => $costo,
            'subtotal' => $subtotal,
            'iva' => $iva,
            'retencion' => $retencion,
            'total' => $total,
            'estatus_portal' => filled($f['estatus_portal']) ? $f['estatus_portal'] : null,
            'factura_sustituta' => filled($f['factura_sustituta']) ? trim($f['factura_sustituta']) : null,
            'notas' => filled($f['notas']) ? trim($f['notas']) : null,
        ];

        // Liga con el cliente del sistema por su nombre (de ahí salen los días de crédito).
        $clienteId = (! $venta || $cliente !== $venta->cliente_nombre)
            ? Cliente::where('nombre_llave', Nombres::llave($cliente))->value('id')
            : $venta->cliente_id;

        $guardada = DB::transaction(function () use ($venta, $datos, $clienteId) {
            if (! $venta) {
                $nueva = Venta::create($datos + ['cliente_id' => $clienteId, 'origen' => 'manual', 'estatus' => 'pendiente']);
                $nueva->anotar('alta', null, 'Captura manual');

                return $nueva;
            }

            $antes = $venta->only(self::CAMPOS_EDITABLES);
            $venta = Venta::lockForUpdate()->findOrFail($venta->id);
            if ($venta->deposito_cobro_id) {
                foreach (['tipo', 'folio', 'cliente_nombre', 'subtotal', 'iva', 'retencion', 'total', 'factura_sustituta'] as $campo) {
                    if ($this->textoComparable($campo, $venta->{$campo}) !== $this->textoComparable($campo, $datos[$campo])) {
                        ComplementosSicar::error('Esta factura pertenece a un depósito. No cambies su cliente, identidad o importe fuera de la conciliación.');
                    }
                }
            }
            $venta->fill($datos + ['cliente_id' => $clienteId])->save();
            foreach (self::CAMPOS_EDITABLES as $campo) {
                $venta->anotar($campo, $this->textoComparable($campo, $antes[$campo]), $this->textoComparable($campo, $datos[$campo]));
            }

            return $venta;
        });

        $this->mensaje = ($venta ? 'Se guardaron los cambios de la venta ' : 'Se agregó la venta ').$guardada->folio.'.';
        if (! $this->consulta()->whereKey($guardada->id)->exists()) {
            $this->mensaje .= ' Con los filtros de arriba no aparece en la lista: presiona "Limpiar filtros" para verla.';
        }
        $this->error = '';
        $this->cerrarForm();
        if (! $venta) {
            $this->resetPage();
        }
    }

    /** Solo el admin: la venta deja de contar en los totales y en las comisiones. */
    public function cancelarVenta(): void
    {
        $this->autorizar(true);
        $venta = Venta::findOrFail($this->editandoId);
        if ($venta->estatus === 'cancelada') {
            $this->cerrarForm();

            return;
        }

        DB::transaction(function () use ($venta) {
            $venta = Venta::lockForUpdate()->findOrFail($venta->id);
            if ($venta->deposito_cobro_id) {
                ComplementosSicar::error('Esta factura pertenece a un depósito. Revisa su conciliación antes de cancelarla.');
            }
            $antes = $venta->estatus;
            $venta->update(['estatus' => 'cancelada']);
            $venta->anotar('estatus', $antes, 'cancelada');
        });

        $this->mensaje = 'La venta '.$venta->folio.' quedó cancelada: ya no cuenta en los totales ni en las comisiones.';
        $this->error = '';
        $this->cerrarForm();
    }

    // ===== Números y bitácora =====

    private function numero(mixed $valor): float
    {
        return is_numeric($valor) ? round((float) $valor, 2) : 0.0;
    }

    private function monto(mixed $valor): string
    {
        return number_format($this->numero($valor), 2, '.', '');
    }

    private function iguales(float $a, mixed $b): bool
    {
        return abs($a - (float) $b) < 0.005;
    }

    /** Valor en texto igual para "antes" y "después" (montos con 2 decimales, fechas Y-m-d) y así anotar solo lo que cambió. */
    private function textoComparable(string $campo, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (isset(self::MONTOS[$campo])) {
            return $this->monto($valor);
        }

        return $valor instanceof DateTimeInterface ? $valor->format('Y-m-d') : (string) $valor;
    }

    /** Un renglón del historial listo para mostrar. */
    private function filaBitacora(VentaCambio $c): array
    {
        $mostrar = function (?string $valor) use ($c) {
            if ($valor === null || $valor === '') {
                return '—';
            }

            return match ($c->campo) {
                'costo', 'subtotal', 'iva', 'retencion', 'total' => is_numeric($valor) ? '$'.number_format((float) $valor, 2) : $valor,
                'fecha', 'fecha_pago' => preg_match('/^\d{4}-\d{2}-\d{2}/', $valor) ? Carbon::parse(substr($valor, 0, 10))->format('d/m/Y') : $valor,
                'estatus' => Venta::ESTATUS[$valor] ?? $valor,
                'estatus_portal' => Venta::PORTAL[$valor] ?? $valor,
                'tipo' => Venta::TIPOS[$valor] ?? $valor,
                default => mb_strlen($valor) > 140 ? mb_substr($valor, 0, 140).'…' : $valor,
            };
        };

        return [
            'cuando' => $c->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            'quien' => $c->user?->name ?? ($c->origen === 'sicar' ? 'SICAR' : 'Usuario eliminado'),
            'campo' => self::ETIQUETAS[$c->campo] ?? $c->campo,
            'antes' => $mostrar($c->antes),
            'despues' => $mostrar($c->despues),
        ];
    }

    // ===== Pantalla =====

    /** "dom 20 sep": para decir qué cobros cuentan en una semana. */
    private function diaCorto(Carbon $f): string
    {
        return ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][$f->dayOfWeek].' '.$f->day.' '
            .['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][$f->month - 1];
    }

    public function render()
    {
        $esAdmin = auth()->user()->esAdmin();
        $anio = $this->anioActivo();
        $semana = $this->semanaActiva();

        $base = $this->consulta();
        $ventas = (clone $base)
            ->with(['cliente:id,nombre,dias_credito', 'cuenta:id,nombre', 'depositoCobro:id,estatus', 'pedidos' => fn ($q) => $q->select('pedidos.id', 'pedidos.folio')])
            ->withCount('reemplaza')
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate(self::POR_PAGINA);
        $totales = $this->totales($base);
        $idsPagina = $ventas->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Con una semana elegida: de qué días son los cobros y cuánto toca de comisión (10% de la utilidad cobrada).
        $semanaInfo = null;
        if ($semana !== null) {
            [$desde, $hasta] = Semanas::rangoCobro($anio, $semana);
            $semanaInfo = [
                'etiqueta' => Semanas::etiqueta($anio, $semana),
                'cobros' => $this->diaCorto($desde).' al '.$this->diaCorto($hasta),
                'utilidad' => $totales['utilidad'],
                'comision' => round($totales['utilidad'] * 0.10, 2),
            ];
        }

        $editando = $esAdmin && $this->mostrarForm && $this->editandoId ? Venta::with('cuenta:id,nombre')->find($this->editandoId) : null;

        $detalle = $this->detalleId
            ? Venta::with([
                'cuenta:id,nombre', 'cliente:id,nombre,dias_credito',
                'reemplaza.origen', 'reemplaza.user:id,name', 'reemplazadaPor.destino', 'reemplazadaPor.user:id,name',
                'pedidos' => fn ($q) => $q->select('pedidos.id', 'pedidos.folio')->orderBy('pedidos.folio'),
            ])->find($this->detalleId)
            : null;
        if ($this->detalleId && ! $detalle) {
            $this->detalleId = null;   // la borraron mientras estaba abierta
        }

        return view('livewire.finanzas.ventas', [
            'ventas' => $ventas,
            'ligasPorConfirmar' => LigasPorConfirmar::cuantas(),
            'totales' => $totales,
            'anios' => $this->aniosDisponibles(),
            'meses' => self::MESES,
            'todosLosAnios' => $anio === null,
            'semanas' => $anio === null ? [] : collect(range(1, Semanas::delAnio($anio)))->mapWithKeys(fn ($n) => [$n => Semanas::etiqueta($anio, $n)])->all(),
            'semanaInfo' => $semanaInfo,
            'cuentas' => $this->panelLote === 'pagar' ? Cuenta::where('activa', true)->whereIn('tipo', ['debito', 'efectivo', 'inversion', 'apartado'])->orderBy('nombre')->get(['id', 'nombre']) : collect(),
            'paginaCompleta' => $idsPagina && ! array_diff($idsPagina, $this->idsSeleccionados()),
            'esAdmin' => $esAdmin,
            'puedeImportar' => $esAdmin && Route::has('finanzas.importar'),
            'editando' => $editando,
            'detalle' => $detalle,
            'costoSicar' => $detalle && $esAdmin ? CostoSicarVsPedido::deVenta($detalle) : null,
            'cambiosDetalle' => $detalle ? $detalle->cambios()->with('user:id,name')->limit(100)->get()->map(fn ($c) => $this->filaBitacora($c)) : collect(),
            'sugerencias' => $this->panelLote === 'ligar' ? $this->sugerenciasDestino() : collect(),
            'cuadreVentas' => $this->cuadre ? Venta::whereIn('id', $this->idsDelCuadre())
                ->get(['id', 'folio', 'fecha', 'total', 'saldo_sicar', 'estatus', 'cliente_nombre'])->keyBy('id') : collect(),
            'cambios' => $editando ? $editando->cambios()->with('user:id,name')->limit(100)->get()->map(fn ($c) => $this->filaBitacora($c)) : collect(),
            'clientes' => $esAdmin && $this->mostrarForm ? Cliente::orderBy('nombre')->pluck('nombre') : collect(),
        ]);
    }
}
