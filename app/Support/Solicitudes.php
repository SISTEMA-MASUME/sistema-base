<?php

namespace App\Support;

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoOrdenProveedor;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionEnvio;
use App\Models\CotizacionPartida;
use App\Models\DocumentoEnvio;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bandeja "Por cotizar": tomar (atómico, para que dos vendedores no trabajen la misma), soltar, reasignar,
 * descartar, marcar cotizada y medir tiempos (llegada → tomada → primer envío al cliente).
 * Las reglas de negocio viven aquí; las pantallas solo las llaman. Los errores esperables son DomainException
 * con mensaje ya entendible.
 */
final class Solicitudes
{
    /** Semáforo de antigüedad de una pendiente: verde < 4 h, amarillo < 24 h, rojo desde 24 h. */
    public const HORAS_VERDE = 4;

    public const HORAS_AMARILLO = 24;

    public const MIN_MOTIVO = 5;

    // ------------------------------------------------------------------ permisos

    public static function puedeUsar(?User $u): bool
    {
        return $u !== null && in_array($u->rol, ['admin', 'vendedor'], true) && $u->activo !== false;
    }

    public static function puedeSoltar(User $u, SolicitudCotizacion $s): bool
    {
        return $s->estatus === 'en_proceso' && ($u->esAdmin() || $s->tomada_por === $u->id);
    }

    public static function puedeDescartar(User $u, SolicitudCotizacion $s): bool
    {
        return match ($s->estatus) {
            'pendiente' => self::puedeUsar($u),
            'en_proceso' => $u->esAdmin() || $s->tomada_por === $u->id,
            default => false,
        };
    }

    // ------------------------------------------------------------------ acciones

    /** Alta compartida por la pantalla y el MCP: solo una pendiente, todavía sin cotización. */
    public static function crear(array $datos, User $u, ?string $claveMcp = null, ?string $huellaMcp = null): SolicitudCotizacion
    {
        $u = $u->fresh();
        if (! self::puedeUsar($u)) {
            throw new DomainException('No tienes permiso para crear solicitudes.');
        }
        $datos = array_intersect_key($datos, array_flip(['cliente_id', 'cliente_texto', 'contacto_texto', 'canal', 'recibida_at', 'detalle', 'partidas']));
        $existente = fn () => $claveMcp ? SolicitudCotizacion::where('mcp_clave', $claveMcp)->first() : null;
        $comprobar = function (SolicitudCotizacion $s) use ($huellaMcp) {
            if ($s->mcp_huella !== $huellaMcp) {
                throw new DomainException('Esa clave ya se usó con otros datos. Consulta la solicitud existente; para otra solicitud usa una clave nueva.');
            }

            return $s;
        };
        if ($s = $existente()) {
            return $comprobar($s);
        }
        try {
            return DB::transaction(function () use ($datos, $u, $claveMcp, $huellaMcp) {
                $s = SolicitudCotizacion::create($datos + [
                    'folio' => Folios::siguiente('solicitud'), 'estatus' => 'pendiente', 'creada_por' => $u->id,
                    'mcp_clave' => $claveMcp, 'mcp_huella' => $huellaMcp,
                ]);
                $s->anotar('creada', $u->nombreReal().' anotó la solicitud ('.$s->canalTexto().').', $u->id);

                return $s;
            });
        } catch (UniqueConstraintViolationException $e) {
            // La otra llamada pudo ganar durante el alta. Se lee fuera de la transacción fallida.
            if ($s = $existente()) {
                return $comprobar($s);
            }
            throw $e;
        }
    }

    public static function pendientes(): int
    {
        return SolicitudCotizacion::where('estatus', 'pendiente')->count();
    }

    /**
     * La toma de forma atómica: el UPDATE solo afecta la fila si sigue pendiente y sin dueño, así que si dos
     * vendedores pulsan a la vez solo uno gana. El ganador recibe su cotización en borrador (sin costos).
     *
     * @throws DomainException
     */
    public static function tomar(SolicitudCotizacion $s, User $u): Cotizacion
    {
        if (! self::puedeUsar($u)) {
            throw new DomainException('No tienes permiso para tomar solicitudes.');
        }
        // El cotizador pide cliente desde el principio (cotizaciones.cliente_id es obligatorio).
        $cliente = $s->cliente_id ? Cliente::withTrashed()->find($s->cliente_id)?->vigente() : null;
        if (! $cliente) {
            throw new DomainException('Escoge el cliente de la solicitud (o dalo de alta) antes de tomarla.');
        }

        return DB::transaction(function () use ($s, $u, $cliente) {
            $filas = SolicitudCotizacion::whereKey($s->id)
                ->where('estatus', 'pendiente')->whereNull('tomada_por')
                ->update(['estatus' => 'en_proceso', 'tomada_por' => $u->id, 'tomada_at' => now(), 'updated_at' => now()]);

            if ($filas === 0) {
                $actual = SolicitudCotizacion::with('tomadaPor')->find($s->id);
                throw new DomainException($actual?->tomadaPor
                    ? 'Ya la está trabajando '.$actual->tomadaPor->nombreReal().'.'
                    : 'Esta solicitud ya no está pendiente ('.mb_strtolower(SolicitudCotizacion::ESTATUS[$actual?->estatus] ?? 'no existe').').');
            }

            $s->refresh();
            // Ojo: las notas de la cotización salen en el PDF del cliente, por eso no llevan datos de la solicitud.
            $cot = Cotizacion::create([
                'folio' => Folios::siguiente(),
                'cliente_id' => $cliente->id,
                'vendedor_id' => $u->id,
                'fecha' => now()->toDateString(),
                'vigencia_dias' => config('masume.cotizacion.vigencia_dias', 15),
                'condiciones_entrega' => config('masume.cotizacion.condiciones_entrega'),
                'estado' => EstadoCotizacion::Borrador,
            ]);
            $orden = 0;
            foreach (self::partidasValidas($s->partidas) as $p) {
                CotizacionPartida::create(self::datosPartida($p) + ['cotizacion_id' => $cot->id, 'orden' => ++$orden, 'incluir' => true]);
            }
            $cot->recalcularTotales();

            $s->update(['cotizacion_id' => $cot->id]);
            $s->anotar('tomada', $u->nombreReal().' la tomó y creó la cotización '.$cot->folio.'.', $u->id);

            return $cot;
        });
    }

    /** @throws DomainException */
    public static function soltar(SolicitudCotizacion $s, User $u): void
    {
        $s->refresh();
        if (! self::puedeSoltar($u, $s)) {
            throw new DomainException($s->estatus === 'en_proceso'
                ? 'Solo quien la tomó o un administrador puede soltarla.' : 'Esta solicitud no está en proceso.');
        }
        $folio = $s->cotizacion?->folio;
        $s->update(['estatus' => 'pendiente', 'tomada_por' => null, 'tomada_at' => null, 'cotizacion_id' => null]);
        $s->anotar('soltada', $u->nombreReal().' la soltó; vuelve a pendientes.'
            .($folio ? " La cotización {$folio} (borrador) se conserva pero ya no está ligada a esta solicitud." : ''), $u->id);
    }

    /** @throws DomainException */
    public static function reasignar(SolicitudCotizacion $s, User $admin, User $nuevo): void
    {
        $s->refresh();
        if (! $admin->esAdmin()) {
            throw new DomainException('Solo el administrador puede reasignar.');
        }
        if ($s->estatus !== 'en_proceso') {
            throw new DomainException('Solo se reasignan solicitudes en proceso.');
        }
        if (! self::puedeUsar($nuevo)) {
            throw new DomainException('Esa persona no puede cotizar.');
        }
        $antes = $s->tomadaPor?->nombreReal() ?? 'nadie';
        DB::transaction(function () use ($s, $nuevo) {
            $s->update(['tomada_por' => $nuevo->id]);
            $s->cotizacion?->update(['vendedor_id' => $nuevo->id]);
        });
        $s->anotar('reasignada', "{$admin->nombreReal()} la reasignó de {$antes} a {$nuevo->nombreReal()}.", $admin->id);
    }

    /** @throws DomainException */
    public static function descartar(SolicitudCotizacion $s, User $u, string $motivo): void
    {
        $s->refresh();
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MIN_MOTIVO) {
            throw new DomainException('Escribe el motivo (mínimo '.self::MIN_MOTIVO.' letras).');
        }
        if (! self::puedeDescartar($u, $s)) {
            throw new DomainException('No puedes descartar esta solicitud.');
        }
        $s->update(['estatus' => 'descartada', 'motivo_descarte' => mb_substr($motivo, 0, 500)]);
        $s->anotar('descartada', $u->nombreReal().' la descartó: '.$motivo, $u->id);
    }

    /** Se llama cuando una cotización se envía al cliente: la solicitud que la originó queda "cotizada". */
    public static function alEnviarCotizacion(Cotizacion $cot): void
    {
        $s = SolicitudCotizacion::where('cotizacion_id', $cot->id)->where('estatus', 'en_proceso')->first();
        if (! $s) {
            return;
        }
        $s->update(['estatus' => 'cotizada']);
        $s->anotar('cotizada', 'La cotización '.$cot->folio.' se envió al cliente.');
    }

    /**
     * Campos de la partida de cotización a partir de un renglón de la solicitud. Sin liga: sin costo ni proveedor.
     * Con liga: copia costo, proveedor (y su vendedor/teléfono), utilidad, tiempo de entrega, link de compra e
     * imagen del producto ya cotizado (se lee la partida de origen tal como está hoy) y recalcula el precio.
     */
    private static function datosPartida(array $p): array
    {
        $cantidad = $p['cantidad'] > 0 ? $p['cantidad'] : 1;
        $datos = [
            'descripcion' => mb_strtoupper(mb_substr($p['descripcion'], 0, 2000)),
            'modelo' => $p['modelo'] !== '' ? mb_substr($p['modelo'], 0, 250) : null,
            'unidad' => mb_substr(mb_strtoupper($p['unidad']) ?: 'PZA', 0, 10),
            'cantidad' => $cantidad,
            'precio' => 0,
            'importe' => 0,
        ];
        $origen = $p['ligada'] ? CotizacionPartida::find((int) $p['ligada']['partida_id']) : null;
        if ($origen) {
            $costo = $origen->costo !== null && (float) $origen->costo > 0 ? (float) $origen->costo : null;
            $utilidad = $origen->utilidad_pct !== null ? (float) $origen->utilidad_pct : null;
            $precio = $costo !== null && $utilidad !== null
                ? Precios::precioDesdeUtilidad($costo, $utilidad)
                : (float) $origen->precio;
            $datos = array_merge($datos, [
                'marca' => $origen->marca,
                'proveedor_id' => $origen->proveedor_id,
                'proveedor_vendedor' => $origen->proveedor_vendedor,
                'proveedor_telefono' => $origen->proveedor_telefono,
                'link_compra' => $origen->link_compra,
                'costo' => $costo,
                'utilidad_pct' => $utilidad,
                'tiempo_entrega' => $origen->tiempo_entrega,
                'entrega_lenta' => (bool) $origen->entrega_lenta,
                'precio' => round(max(0, $precio), 2),
            ]);
            $datos['importe'] = Precios::importe($datos['precio'], $cantidad);
            if ($origen->imagen_path && Storage::disk('public')->exists($origen->imagen_path)) {
                $datos['imagen_path'] = $origen->imagen_path;
            }
        }
        // Igual que el cotizador: si el producto ya tiene imagen en el catálogo, se pone sola.
        $datos['imagen_path'] ??= CatalogoImagenes::imagenPara($datos['modelo'], $datos['descripcion']);

        return $datos;
    }

    /** El cliente con ese nombre (compara nombre_llave) o, si no existe, lo da de alta. */
    public static function asegurarCliente(string $nombre): Cliente
    {
        $nombre = Nombres::limpiar($nombre);
        if (Nombres::llave($nombre) === '') {
            throw new DomainException('Escribe el nombre del cliente.');
        }

        if ($c = Cliente::porNombre($nombre)) {
            return $c;
        }
        if ($borrado = Cliente::borradoConNombre($nombre)) {
            throw new DomainException("El cliente {$borrado->nombre} fue borrado; escoge el correcto de la lista.");
        }

        return Cliente::create(['nombre' => $nombre]);
    }

    // ------------------------------------------------------------------ partidas

    /** @return list<array{cantidad: float, unidad: string, descripcion: string, modelo: string}> */
    public static function partidasValidas(?array $partidas): array
    {
        $salida = [];
        foreach ((array) $partidas as $p) {
            $desc = trim((string) ($p['descripcion'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $cant = $p['cantidad'] ?? null;
            $cant = is_numeric($cant) ? (float) $cant : (is_string($cant) && is_numeric(str_replace(',', '', $cant)) ? (float) str_replace(',', '', $cant) : 0.0);
            $salida[] = [
                'cantidad' => max(0, $cant),
                'unidad' => trim((string) ($p['unidad'] ?? '')),
                'descripcion' => $desc,
                'modelo' => trim((string) ($p['modelo'] ?? '')),
                'ligada' => is_array($p['ligada'] ?? null) && (int) ($p['ligada']['partida_id'] ?? 0) > 0 ? $p['ligada'] : null,
            ];
        }

        return $salida;
    }

    // ------------------------------------------------------------------ tiempos

    /**
     * Primer envío exitoso de cada cotización (correo, WhatsApp o descarga; abrir el enlace no cuenta).
     * Si por algo no hay registro de envío, cae a cotizaciones.enviada_at.
     *
     * @param  iterable<int>  $ids
     * @return array<int, Carbon>
     */
    public static function primerosEnvios(iterable $ids): array
    {
        return array_map(fn (array $e) => $e['fecha'], self::primerosEnviosConQuien($ids));
    }

    /**
     * Igual que primerosEnvios(), pero también con quién lo envió (el perfil con el que se inició sesión).
     * Si solo hay cotizaciones.enviada_at, no se sabe quién: user_id nulo.
     *
     * @param  iterable<int>  $ids
     * @return array<int, array{fecha: Carbon, user_id: ?int}>
     */
    public static function primerosEnviosConQuien(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();
        if ($ids === []) {
            return [];
        }
        $primeros = [];
        $acumular = function ($id, $fecha, $userId) use (&$primeros) {
            $f = Carbon::parse($fecha);
            if (! isset($primeros[$id]) || $f->lt($primeros[$id]['fecha'])) {
                $primeros[$id] = ['fecha' => $f, 'user_id' => $userId !== null ? (int) $userId : null];
            }
        };
        foreach (array_chunk($ids, 500) as $lote) {
            foreach (CotizacionEnvio::whereIn('cotizacion_id', $lote)->where('exito', true)->get(['cotizacion_id', 'user_id', 'created_at']) as $e) {
                $acumular($e->cotizacion_id, $e->created_at, $e->user_id);
            }
            foreach (DocumentoEnvio::where('documento_type', Cotizacion::class)->whereIn('documento_id', $lote)
                ->where('exito', true)->where('canal', '!=', 'enlace')->get(['documento_id', 'user_id', 'created_at']) as $e) {
                $acumular($e->documento_id, $e->created_at, $e->user_id);
            }
        }
        foreach (array_chunk(array_values(array_diff($ids, array_keys($primeros))), 500) as $lote) {
            foreach (Cotizacion::withTrashed()->whereIn('id', $lote)->whereNotNull('enviada_at')->get(['id', 'enviada_at']) as $c) {
                $acumular($c->id, $c->enviada_at, null);
            }
        }

        return $primeros;
    }

    public static function minutosHastaTomar(SolicitudCotizacion $s): ?int
    {
        return $s->tomada_at ? self::minutos($s->recibida_at, $s->tomada_at) : null;
    }

    public static function minutosHastaEnviar(SolicitudCotizacion $s): ?int
    {
        $envio = $s->enviadaAt();

        return $envio ? self::minutos($s->recibida_at, $envio) : null;
    }

    /** Minutos enteros entre dos momentos (nunca negativos: a veces la hora de llegada se captura mal). */
    private static function minutos(Carbon $desde, Carbon $hasta): int
    {
        return max(0, (int) round($desde->diffInSeconds($hasta, false) / 60));
    }

    public static function semaforo(SolicitudCotizacion $s): string
    {
        $horas = $s->recibida_at->diffInMinutes(now(), false) / 60;

        return match (true) {
            $horas < self::HORAS_VERDE => 'verde',
            $horas < self::HORAS_AMARILLO => 'amarillo',
            default => 'rojo',
        };
    }

    /** "2 h 15 min", "1 día 3 h", "45 min". */
    public static function humano(?float $minutos): string
    {
        if ($minutos === null) {
            return '—';
        }
        $m = (int) round($minutos);
        if ($m < 1) {
            return 'menos de 1 min';
        }
        if ($m < 60) {
            return "{$m} min";
        }
        if ($m < 1440) {
            $h = intdiv($m, 60);
            $r = $m % 60;

            return $r ? "{$h} h {$r} min" : "{$h} h";
        }
        $d = intdiv($m, 1440);
        $h = intdiv($m % 1440, 60);

        return $d.($d === 1 ? ' día' : ' días').($h ? " {$h} h" : '');
    }

    /** "hace 20 min", "hace 3 h", "hace 2 d". */
    public static function hace(Carbon $fecha): string
    {
        $m = max(0, (int) $fecha->diffInMinutes(now(), false));

        return match (true) {
            $m < 1 => 'hace un momento',
            $m < 60 => "hace {$m} min",
            $m < 1440 => 'hace '.intdiv($m, 60).' h',
            default => 'hace '.intdiv($m, 1440).' d',
        };
    }

    /**
     * Métricas del mes (por fecha de llegada): conteos, promedio y mediana llegada→tomada y llegada→envío,
     * y por vendedor cuántas cotizó y su promedio. También las cotizaciones hechas directo en Cotizaciones
     * (creadas en el mes y enviadas; tiempo de creada a primer envío).
     */
    public static function metricas(string $mes): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth();
        $filas = SolicitudCotizacion::with('tomadaPor:id,name')
            ->whereBetween('recibida_at', [$inicio, $inicio->copy()->endOfMonth()])->get();
        $envios = self::primerosEnvios($filas->pluck('cotizacion_id'));

        $hastaTomar = [];
        $hastaEnviar = [];
        $porVendedor = [];
        $cotizacionesDe = [];   // vendedor => ids de cotizaciones enviadas (para partidas, monto y utilidad)
        foreach ($filas as $s) {
            if ($s->tomada_at) {
                $hastaTomar[] = self::minutos($s->recibida_at, $s->tomada_at);
            }
            $envio = $s->cotizacion_id ? ($envios[$s->cotizacion_id] ?? null) : null;
            if ($envio && $s->estatus === 'cotizada') {
                $m = self::minutos($s->recibida_at, $envio);
                $hastaEnviar[] = $m;
                $quien = $s->tomadaPor?->nombreReal() ?? 'Sin asignar';
                $porVendedor[$quien][] = $m;
                $cotizacionesDe[$quien][] = $s->cotizacion_id;
            }
        }
        // Cotizaciones que se hicieron directo en Cotizaciones (sin pasar por Por cotizar): no tienen hora de llegada,
        // se mide de cuando se creó la cotización a su primer envío. Cuentan en el mes en que se crearon.
        $directas = Cotizacion::with('vendedor:id,name')->where('origen', 'sistema')
            ->whereBetween('created_at', [$inicio, $inicio->copy()->endOfMonth()])
            ->whereNotIn('id', SolicitudCotizacion::whereNotNull('cotizacion_id')->select('cotizacion_id'))
            ->get(['id', 'vendedor_id', 'created_at']);
        // Se cuentan al perfil que la envió (quien inició sesión), aunque "Elaboró" diga otro nombre; si no se sabe, a "Elaboró".
        $enviosDirectas = self::primerosEnviosConQuien($directas->pluck('id'));
        $nombres = self::nombres(array_column($enviosDirectas, 'user_id'));
        $hastaEnviarDirectas = [];
        $porVendedorDirectas = [];
        foreach ($directas as $c) {
            if ($envio = $enviosDirectas[$c->id] ?? null) {
                $m = self::minutos($c->created_at, $envio['fecha']);
                $hastaEnviarDirectas[] = $m;
                $quien = $nombres[$envio['user_id']] ?? $c->vendedor?->nombreReal() ?? 'Sin asignar';
                $porVendedorDirectas[$quien][] = $m;
                $cotizacionesDe[$quien][] = $c->id;
            }
        }

        $prom = fn (array $v) => $v ? array_sum($v) / count($v) : null;
        $med = function (array $v) {
            if (! $v) {
                return null;
            }
            sort($v);
            $n = count($v);

            return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
        };

        // Tamaño de lo cotizado: partidas incluidas, monto (subtotal sin IVA) y utilidad (precio − costo de las que tienen costo).
        $volumen = self::volumen(array_merge(...array_values($cotizacionesDe ?: [[]])));

        $pedidos = self::pedidosPorPerfil($inicio, $inicio->copy()->endOfMonth());

        $vendedores = [];
        foreach (array_unique(array_merge(array_keys($porVendedor), array_keys($porVendedorDirectas), array_keys($pedidos))) as $nombre) {
            $v = $porVendedor[$nombre] ?? [];
            $d = $porVendedorDirectas[$nombre] ?? [];
            $suma = ['partidas' => 0, 'monto' => 0.0, 'utilidad' => 0.0, 'sin_costo' => 0];
            foreach (array_unique($cotizacionesDe[$nombre] ?? []) as $id) {
                foreach ($suma as $k => $_) {
                    $suma[$k] += $volumen[$id][$k] ?? 0;
                }
            }
            $vendedores[] = [
                'nombre' => $nombre,
                'cotizadas' => count($v), 'promedio' => $prom($v),                      // desde Por cotizar (llegada → envío)
                'directas' => count($d), 'promedio_directas' => $prom($d),              // directo en Cotizaciones (creada → envío)
                'total' => count($v) + count($d),
                'partidas' => (int) $suma['partidas'], 'monto' => round($suma['monto'], 2), 'utilidad' => round($suma['utilidad'], 2),
                'partidas_sin_costo' => (int) $suma['sin_costo'],
                'pedidos' => $pedidos[$nombre]['pedidos'] ?? 0,
                'pedidos_partidas' => $pedidos[$nombre]['partidas'] ?? 0,
                'pedidos_monto' => round($pedidos[$nombre]['monto'] ?? 0, 2),
                'ordenes' => $pedidos[$nombre]['ordenes'] ?? 0,
            ];
        }
        usort($vendedores, fn ($a, $b) => [$b['monto'], $b['total'], $b['cotizadas'], $b['pedidos']] <=> [$a['monto'], $a['total'], $a['cotizadas'], $a['pedidos']]);

        return [
            'llegaron' => $filas->count(),
            'cotizadas' => $filas->where('estatus', 'cotizada')->count(),
            'pendientes' => $filas->where('estatus', 'pendiente')->count(),
            'en_proceso' => $filas->where('estatus', 'en_proceso')->count(),
            'descartadas' => $filas->where('estatus', 'descartada')->count(),
            'promedio_tomar' => $prom($hastaTomar),
            'mediana_tomar' => $med($hastaTomar),
            'promedio_enviar' => $prom($hastaEnviar),
            'mediana_enviar' => $med($hastaEnviar),
            'directas' => count($hastaEnviarDirectas),
            'promedio_directas' => $prom($hastaEnviarDirectas),
            'mediana_directas' => $med($hastaEnviarDirectas),
            'vendedores' => $vendedores,
            'partidas' => array_sum(array_column($vendedores, 'partidas')),
            'monto' => round(array_sum(array_column($vendedores, 'monto')), 2),
            'utilidad' => round(array_sum(array_column($vendedores, 'utilidad')), 2),
            'pedidos' => array_sum(array_column($vendedores, 'pedidos')),
        ];
    }

    /**
     * Pedidos dados de alta entre dos fechas, por el perfil que los creó (quien inició sesión, según la actividad
     * "creó la tarjeta"; si no quedó anotado, el responsable), con sus partidas y venta sin IVA; y las OC a
     * proveedor generadas (sin canceladas) por el perfil que las generó.
     *
     * @return array<string, array{pedidos: int, partidas: int, monto: float, ordenes: int}>
     */
    private static function pedidosPorPerfil(Carbon $desde, Carbon $hasta): array
    {
        $salida = [];
        $vacio = ['pedidos' => 0, 'partidas' => 0, 'monto' => 0.0, 'ordenes' => 0];
        foreach (self::pedidosConQuien($desde, $hasta) as $p) {
            $salida[$p['quien']] ??= $vacio;
            $salida[$p['quien']]['pedidos']++;
            $salida[$p['quien']]['partidas'] += $p['partidas'];
            $salida[$p['quien']]['monto'] += $p['monto'];
        }
        $ordenes = OrdenProveedor::whereBetween('created_at', [$desde, $hasta])
            ->where('estado', '!=', EstadoOrdenProveedor::Cancelada->value)->get(['user_id']);
        $nombres = self::nombres($ordenes->pluck('user_id')->all());
        foreach ($ordenes as $o) {
            $quien = $nombres[$o->user_id] ?? 'Sin asignar';
            $salida[$quien] ??= $vacio;
            $salida[$quien]['ordenes']++;
        }

        return $salida;
    }

    /**
     * Cada pedido dado de alta entre dos fechas con quién lo creó, cuándo, cuántas partidas trae y su venta sin IVA.
     *
     * @return list<array{quien: string, fecha: Carbon, partidas: int, monto: float}>
     */
    private static function pedidosConQuien(Carbon $desde, Carbon $hasta): array
    {
        // Sin los traídos de Trello (no se procesaron aquí) ni los cancelados.
        $pedidos = Pedido::whereBetween('created_at', [$desde, $hasta])->whereNull('trello_card_id')->whereNull('cancelado_at')->get(['id', 'responsable_id', 'total_venta', 'created_at']);
        if ($pedidos->isEmpty()) {
            return [];
        }
        $creadores = [];
        $partidas = [];
        foreach ($pedidos->pluck('id')->chunk(500)->map->values() as $lote) {
            $creadores += PedidoActividad::whereIn('pedido_id', $lote)->where('tipo', 'crear')->whereNotNull('user_id')
                ->orderByDesc('id')->pluck('user_id', 'pedido_id')->all();
            $partidas += PedidoPartida::whereIn('pedido_id', $lote)->toBase()->selectRaw('pedido_id, COUNT(*) AS n')
                ->groupBy('pedido_id')->pluck('n', 'pedido_id')->all();
        }
        $nombres = self::nombres(array_merge(array_values($creadores), $pedidos->pluck('responsable_id')->all()));
        $salida = [];
        foreach ($pedidos as $p) {
            $salida[] = [
                'quien' => $nombres[$creadores[$p->id] ?? $p->responsable_id] ?? 'Sin asignar',
                'fecha' => $p->created_at,
                'partidas' => (int) ($partidas[$p->id] ?? 0),
                'monto' => (float) $p->total_venta,
            ];
        }

        return $salida;
    }

    /**
     * Trabajo de cada perfil por día del mes (para el admin): cotizaciones enviadas por primera vez ese día, por quien
     * las envió (o "Elaboró" si no se sabe), y pedidos dados de alta ese día, por quien los creó. Salen los días de lunes
     * a sábado hasta hoy y cualquier otro día con trabajo; el más reciente primero.
     *
     * @return array{personas: list<string>, dias: list<array{fecha: Carbon, por: array<string, array{cot: int, ped: int}>}>}
     */
    public static function porDia(string $mes): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay()->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();
        $rango = [$inicio, $fin];

        $ids = CotizacionEnvio::whereBetween('created_at', $rango)->where('exito', true)->distinct()->pluck('cotizacion_id')
            ->merge(DocumentoEnvio::where('documento_type', Cotizacion::class)->whereBetween('created_at', $rango)
                ->where('exito', true)->where('canal', '!=', 'enlace')->distinct()->pluck('documento_id'))
            ->merge(Cotizacion::whereBetween('enviada_at', $rango)->pluck('id'))
            ->unique()->values();
        // Solo cotizaciones del sistema que siguen existiendo (las importadas del Excel no son trabajo aquí).
        $vigentes = collect();
        foreach ($ids->chunk(500) as $lote) {
            $vigentes = $vigentes->merge(Cotizacion::whereIn('id', $lote->all())->where('origen', 'sistema')->get(['id', 'vendedor_id']));
        }
        $envios = self::primerosEnviosConQuien($vigentes->pluck('id'));
        $elaboro = $vigentes->pluck('vendedor_id', 'id')->all();
        $nombres = self::nombres(array_merge(array_column($envios, 'user_id'), array_values($elaboro)));

        $conteo = [];
        foreach ($envios as $id => $e) {
            if ($e['fecha']->lt($inicio) || $e['fecha']->gt($fin)) {
                continue;   // su primer envío fue en otro mes: este fue un reenvío
            }
            $quien = $nombres[$e['user_id'] ?? $elaboro[$id] ?? 0] ?? 'Sin asignar';
            $dia = $e['fecha']->format('Y-m-d');
            $conteo[$dia][$quien]['cot'] = ($conteo[$dia][$quien]['cot'] ?? 0) + 1;
        }
        foreach (self::pedidosConQuien($inicio, $fin) as $p) {
            $dia = $p['fecha']->format('Y-m-d');
            $conteo[$dia][$p['quien']]['ped'] = ($conteo[$dia][$p['quien']]['ped'] ?? 0) + 1;
        }

        $totales = [];
        foreach ($conteo as $porPersona) {
            foreach ($porPersona as $quien => $n) {
                $totales[$quien] = ($totales[$quien] ?? 0) + ($n['cot'] ?? 0) + ($n['ped'] ?? 0);
            }
        }
        arsort($totales);

        $dias = [];
        $hasta = $fin->copy()->min(now()->endOfDay());
        for ($d = $inicio->copy(); $d->lte($hasta); $d->addDay()) {
            if (! $d->isSunday()) {
                $conteo[$d->format('Y-m-d')] ??= [];
            }
        }
        krsort($conteo);
        foreach ($conteo as $dia => $porPersona) {
            $por = [];
            foreach ($porPersona as $quien => $n) {
                $por[$quien] = ['cot' => $n['cot'] ?? 0, 'ped' => $n['ped'] ?? 0];
            }
            $dias[] = ['fecha' => Carbon::parse($dia), 'por' => $por];
        }

        return ['personas' => array_keys($totales), 'dias' => $dias];
    }

    /**
     * Nombre real de cada usuario (para agrupar igual en todas las métricas).
     *
     * @param  array<int|null>  $ids
     * @return array<int, string>
     */
    private static function nombres(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : User::whereIn('id', $ids)->get()->mapWithKeys(fn (User $u) => [$u->id => $u->nombreReal()])->all();
    }

    /**
     * Por cotización: partidas incluidas, monto (suma de importes sin IVA), utilidad (importe − costo × cantidad de las
     * partidas con costo) y cuántas no tienen costo (su utilidad no se puede calcular).
     *
     * @param  list<int>  $ids
     * @return array<int, array{partidas: int, monto: float, utilidad: float, sin_costo: int}>
     */
    private static function volumen(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $salida = [];
        foreach (array_chunk($ids, 500) as $lote) {
            $filas = CotizacionPartida::whereIn('cotizacion_id', $lote)->where('incluir', true)
                ->selectRaw('cotizacion_id, COUNT(*) AS partidas, SUM(importe) AS monto,'
                    .' SUM(CASE WHEN costo IS NOT NULL AND costo > 0 THEN importe - costo * cantidad ELSE 0 END) AS utilidad,'
                    .' SUM(CASE WHEN costo IS NULL OR costo <= 0 THEN 1 ELSE 0 END) AS sin_costo')
                ->groupBy('cotizacion_id')->get();
            foreach ($filas as $f) {
                $salida[(int) $f->cotizacion_id] = ['partidas' => (int) $f->partidas, 'monto' => (float) $f->monto,
                    'utilidad' => (float) $f->utilidad, 'sin_costo' => (int) $f->sin_costo];
            }
        }

        return $salida;
    }
}
