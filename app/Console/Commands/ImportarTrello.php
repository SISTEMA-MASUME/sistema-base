<?php

namespace App\Console\Commands;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoPartidaPedido;
use App\Enums\TipoPedido;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\User;
use App\Support\Bitacora;
use App\Support\Folios;
use App\Support\Nombres;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa las tarjetas del Trello "ORDENES DE COMPRA" (JSON exportado) como pedidos.
 * Idempotente: una tarjeta ya importada (trello_card_id) se salta. Con --simular todo se revierte.
 */
class ImportarTrello extends Command
{
    protected $signature = 'masume:importar-trello {archivo : JSON de tarjetas exportado de Trello} {--simular : Hace todo y revierte al final (solo imprime el resumen)}';

    protected $description = 'Importa las tarjetas de Trello (ORDENES DE COMPRA) como pedidos';

    private const CAMPOS = ['NUMERO OC CLIENTE', 'CLIENTE', 'RESPONSABLE', 'PROVEEDOR(ES)', 'PARTIDAS', 'TOTAL COSTO CON IVA', 'FECHA'];

    private const COLUMNAS = [
        'OCRECIBIDA' => ColumnaPedido::OcRecibida,
        'OCRECIBIDAENTRAMITEASIGNADA' => ColumnaPedido::EnTramite,
        'CONTIEMPODEENTREGA' => ColumnaPedido::ConTiempoEntrega,
        'CHECARALGUNTEMA' => ColumnaPedido::ChecarTema,
        'PORFACTURAR' => ColumnaPedido::PorFacturar,
        'OCFACTURADAS&NOTASPENDIENTESPORENTREGAR' => ColumnaPedido::FacturadasPendientesEntregar,
        'ENVIOSEMBARCADOS' => ColumnaPedido::EnviosEmbarcados,
        'NOTASDEVENTA&COTENTREGADOSPORFACTURAR' => ColumnaPedido::EntregadosPorFacturar,
        'OCENTREGADAS&COMPLETAS' => ColumnaPedido::EntregadasCompletas,
    ];

    private User $admin;

    /** @var array<string, int> siguiente posición por columna */
    private array $posiciones = [];

    private array $r = [];

    public function handle(): int
    {
        $tarjetas = json_decode((string) @file_get_contents($this->argument('archivo')), true);
        if (! is_array($tarjetas)) {
            $this->error('No se pudo leer el archivo.');

            return self::FAILURE;
        }
        $admin = User::where('rol', 'admin')->orderBy('id')->first();
        if (! $admin) {
            $this->error('No hay usuario administrador.');

            return self::FAILURE;
        }
        $this->admin = $admin;

        $this->r = [
            'creados' => 0, 'ya_importadas' => 0, 'oc_duplicada' => [], 'errores' => [],
            'ligadas' => 0, 'manuales' => 0, 'clientes_nuevos' => [], 'proveedores_nuevos' => [], 'advertencias' => [],
        ];

        // Orden dentro de la lista: pos si viene; si no, el orden del archivo (sortBy es estable).
        $tarjetas = collect($tarjetas)->values();
        if ($tarjetas->contains(fn ($t) => isset($t['pos']))) {
            $tarjetas = $tarjetas->sortBy(fn ($t) => $t['pos'] ?? PHP_INT_MAX)->values();
        }

        $simular = (bool) $this->option('simular');
        DB::beginTransaction();
        try {
            foreach ($tarjetas as $t) {
                try {
                    DB::transaction(fn () => Bitacora::sinRegistro(fn () => $this->importar($t)));
                } catch (OcDuplicada $e) {
                    $this->r['oc_duplicada'][] = $e->getMessage();
                } catch (Throwable $e) {
                    $this->r['errores'][] = ($t['name'] ?? '?').': '.$e->getMessage();
                }
            }
        } finally {
            $simular ? DB::rollBack() : DB::commit();
        }

        $this->resumen($simular, $tarjetas->count());

        return self::SUCCESS;
    }

    private function importar(array $t): void
    {
        $id = (string) ($t['id'] ?? '');
        $titulo = trim((string) ($t['name'] ?? ''));
        if ($id !== '' && Pedido::withTrashed()->where('trello_card_id', $id)->exists()) {
            $this->r['ya_importadas']++;

            return;
        }

        $adv = [];       // advertencias de esta tarjeta
        $refs = [];      // notas de referencia / etiquetas
        [$campos, $libre] = $this->leerDescripcion((string) ($t['desc'] ?? ''));
        $etiquetas = collect($t['labels'] ?? [])->map(fn ($l) => $this->norm($l['name'] ?? ''))->filter()->all();
        $tiene = fn (string $e) => in_array($e, $etiquetas, true);

        // --- Cliente
        $cliente = $this->cliente($campos, $titulo, $adv, $nuevoCliente);

        // --- Número de OC
        [$numero, $referencia] = $this->numeroOc($campos['NUMERO OC CLIENTE'] ?? null, $titulo);
        if ($numero === null) {
            $refs[] = 'Referencia en Trello: '.($referencia !== '' ? $referencia : $titulo);
            $adv[] = 'sin número de OC';
        } else {
            $llave = Nombres::llave(preg_replace('/^\s*(OC|O\.C\.|PO)\s*[:#-]?\s*/i', '', $numero));
            $dup = Pedido::withTrashed()->where('cliente_id', $cliente->id)->where('numero_oc_llave', $llave)->first();
            if ($dup) {
                if ($nuevoCliente) {
                    $this->r['clientes_nuevos'] = array_values(array_diff($this->r['clientes_nuevos'], [$cliente->nombre]));
                }
                throw new OcDuplicada("$titulo (ya existe P-{$dup->folio})");
            }
        }

        $tipo = ($tiene('NOTA DE VENTA') || Str::startsWith($this->norm($titulo), 'NOTA DE VENTA')) ? TipoPedido::NotaVenta : TipoPedido::Oc;

        // --- Responsable
        $responsable = $this->responsable($campos['RESPONSABLE'] ?? null, $titulo, $adv);

        // --- Fechas
        $comentarios = collect($t['comentarios'] ?? [])->sortBy('fecha')->values();
        $fechaOc = $this->fecha($campos['FECHA'] ?? null)
            ?? ($comentarios->isNotEmpty() ? $this->aLocal($comentarios[0]['fecha'] ?? null) : null)
            ?? now();
        $compromiso = ! empty($t['due']) ? $this->aLocal($t['due'])?->toDateString() : null;

        // --- Columna
        $columna = self::COLUMNAS[$this->llaveLista($t['lista'] ?? '')] ?? null;
        if (! $columna) {
            $columna = ColumnaPedido::OcRecibida;
            $adv[] = 'lista desconocida "'.($t['lista'] ?? '').'": quedó en OC recibida';
        }
        $this->posiciones[$columna->value] ??= (int) (Pedido::where('columna', $columna->value)->max('posicion') ?? -1) + 1;

        // --- Etiquetas → banderas y notas
        $solicitada = $tiene('MATERIAL SOLICITADO');
        $facturadaCompleta = $tiene('FACTURADA COMPLETA');
        if ($tiene('FACTURA PARCIAL')) {
            if ($facturadaCompleta) {
                $adv[] = 'tenía FACTURA PARCIAL y FACTURADA COMPLETA: se marcó completa';
            } else {
                $refs[] = 'En Trello tenía FACTURA PARCIAL: revisar qué partidas se facturaron';
            }
        }
        foreach (['COTIZACION SISTEMA', 'RECIBIDO', 'MATERIAL ENTREGADO', 'EMBARCADO/PENDIENTE RECEPCION'] as $e) {
            if ($tiene($e)) {
                $refs[] = "En Trello tenía la etiqueta $e";
            }
        }

        $pedido = Pedido::create([
            'folio' => Folios::siguiente('pedido'),
            'tipo' => $tipo,
            'cliente_id' => $cliente->id,
            'numero_oc_cliente' => $numero,
            'responsable_id' => $responsable->id,
            'fecha_oc' => $fechaOc->toDateString(),
            'fecha_compromiso' => $compromiso,
            'columna' => $columna,
            'posicion' => $this->posiciones[$columna->value]++,
            'alta_sicar' => $tiene('MAT DADO DE ALTA'),
            'revisar' => $tiene('REVISAR/DAR SEGUIMIENTO'),
            'modificar_oc' => $tiene('SE ENVIA A MODIFICAR OC'),
            'cancelado_at' => $tiene('OC CANCELADA POR CLIENTE') ? now() : null,
            'trello_card_id' => $id !== '' ? $id : null,
            'trello_url' => $t['shortUrl'] ?? null,
        ]);

        // --- Partidas
        $items = collect($t['checklists'] ?? [])->flatMap(fn ($c) => collect($c['checkItems'] ?? [])->sortBy('pos'))->values();
        if ($items->isEmpty()) {
            $refs[] = 'Sin partidas en Trello';
        }
        $orden = 0;
        foreach ($items as $item) {
            $orden++;
            $this->partida($pedido, $item, $orden, $solicitada, $facturadaCompleta, $adv, $refs);
        }
        $esperadas = isset($campos['PARTIDAS']) ? (int) $campos['PARTIDAS'] : null;
        if ($esperadas !== null && $esperadas !== $items->count()) {
            $adv[] = "la descripción decía {$esperadas} partidas y el checklist trae {$items->count()}";
        }

        // --- Notas
        $notas = [];
        if ($libre !== '') {
            $notas[] = $libre;
        }
        if ($comentarios->isNotEmpty()) {
            $lineas = $comentarios->map(function ($c) {
                $f = $this->aLocal($c['fecha'] ?? null)?->format('d/m/Y') ?? 's/f';

                return trim("$f ".($c['autor'] ?? '').': '.trim((string) ($c['texto'] ?? '')));
            })->all();
            $notas[] = "Comentarios de Trello:\n".implode("\n", $lineas);
        }
        if ($refs) {
            $notas[] = implode("\n", $refs);
        }
        if (! empty($t['shortUrl'])) {
            $notas[] = 'Tarjeta original: '.$t['shortUrl'];
        }
        $pedido->notas = $notas ? implode("\n\n", $notas) : null;
        $pedido->save();
        $pedido->recalcularTotales();

        $this->r['creados']++;
        if ($adv) {
            $this->r['advertencias'][] = $titulo.': '.implode('; ', array_unique($adv));
        }
    }

    // ---------------------------------------------------------------- descripción

    /** @return array{0: array<string,string>, 1: string} campos estructurados y texto libre */
    private function leerDescripcion(string $desc): array
    {
        $campos = [];
        $libre = [];
        foreach (preg_split('/\R/u', $desc) as $linea) {
            $linea = trim(str_replace(["\u{200C}", "\u{A0}"], ['', ' '], $linea));
            if (preg_match('/^\*\*([^*:]+?)\s*:\*\*\s*(.*)$/u', $linea, $m) && in_array(Str::upper(trim($m[1])), self::CAMPOS, true)) {
                $campos[Str::upper(trim($m[1]))] = trim($m[2]);

                continue;
            }
            if ($linea === '' || $linea === '---' || Str::contains($linea, 'Creado desde Google Sheets')) {
                $libre[] = '';

                continue;
            }
            $linea = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $linea);
            $linea = str_replace(['\\*', '**'], '', $linea);
            $linea = trim(preg_replace('/^(#+|\+)\s*/u', '', $linea));
            $libre[] = $linea;
        }
        $texto = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $libre)));

        return [$campos, $texto];
    }

    private function cliente(array $campos, string $titulo, array &$adv, ?bool &$nuevo): Cliente
    {
        $nombre = trim($campos['CLIENTE'] ?? '');
        if ($nombre === '') {
            $partes = preg_split('/\s+-\s+OC\b/iu', $titulo, 2);
            if (count($partes) === 2) {
                $nombre = $partes[0];
            } else {
                $nombre = trim(preg_replace('/\s+-\s*[A-Za-z]{2,5}$/u', '', $titulo));
                $nombre = trim(preg_replace('/^NOTA DE VENTA\s*/iu', '', $nombre));
                $adv[] = "cliente tomado del título ($nombre)";
            }
        }
        $nombre = mb_substr(Nombres::limpiar($nombre), 0, 200);
        $cliente = Cliente::withTrashed()->where('nombre_llave', Nombres::llave($nombre))->first();
        $nuevo = false;
        if (! $cliente) {
            $cliente = Cliente::create(['nombre' => $nombre]);
            $this->r['clientes_nuevos'][] = $cliente->nombre;
            $nuevo = true;
        }

        return $cliente;
    }

    /** @return array{0: ?string, 1: string} número limpio (o null) y el texto original */
    private function numeroOc(?string $campo, string $titulo): array
    {
        $crudo = trim((string) $campo);
        if ($crudo === '' && preg_match('/\s-\s+OC\s+(.*)$/iu', $titulo, $m)) {
            $crudo = trim($m[1]);
        }
        $t = trim(preg_replace('/\s+-\s*[A-Za-z]{2,5}\s*$/u', '', $crudo));
        for ($i = 0; $i < 2; $i++) {
            $t = trim(preg_replace('/^(?:OC\b|N\s*O?\s*[°º.\x{FFFD}])\s*[:#]?\s*/iu', '', $t));
        }
        if ($t === '' || preg_match('/^COT/iu', $t) || ! preg_match('/\d/', $t)) {
            return [null, $crudo];
        }

        return [mb_substr($t, 0, 80), $crudo];
    }

    private function responsable(?string $nombre, string $titulo, array &$adv): User
    {
        if ($nombre && ($u = User::where('name', $nombre)->first())) {
            return $u;
        }
        if (preg_match('/-\s*([A-Za-z]{2,5})\s*$/u', $titulo, $m) && ($u = User::where('iniciales', Str::upper($m[1]))->first())) {
            if ($nombre) {
                $adv[] = "responsable \"$nombre\" no existe: se usó ".$u->name.' por iniciales';
            }

            return $u;
        }
        $adv[] = 'responsable no identificado: se asignó al administrador';

        return $this->admin;
    }

    private function fecha(?string $texto): ?Carbon
    {
        if ($texto && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($texto), $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function aLocal(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso)->setTimezone('America/Mexico_City') : null;
    }

    private function norm(string $s): string
    {
        return Str::upper(Str::squish(Str::ascii($s)));
    }

    private function llaveLista(string $s): string
    {
        return preg_replace('/[^A-Z0-9&]/', '', $this->norm($s));
    }

    // ---------------------------------------------------------------- partidas

    private function partida(Pedido $pedido, array $item, int $orden, bool $solicitada, bool $facturada, array &$adv, array &$refs): void
    {
        $txt = trim(str_replace("\r", '', (string) ($item['name'] ?? '')));
        $uid = $nn = null;
        if (preg_match('/^\[(\d+)\.(\d+)\]\s*/', $txt, $m)) {
            $uid = (int) $m[1];
            $nn = (int) $m[2];
            $txt = substr($txt, strlen($m[0]));
        }

        // total y proveedor: desde el último segmento "$1,234.50"
        $seg = preg_split('/\s+\|\s+/u', $txt);
        $total = null;
        $proveedor = null;
        $modelo = null;
        $t = null;
        foreach ($seg as $i => $s) {
            if (preg_match('/^\$\s*([\d,]+(?:\.\d+)?)$/', trim($s), $m)) {
                $t = $i;
                $total = (float) str_replace(',', '', $m[1]);
            }
        }
        if ($t !== null) {
            $proveedor = trim(implode(' | ', array_slice($seg, $t + 1)));
            $antes = array_slice($seg, 0, $t);
            if (count($antes) >= 2) {
                $modelo = trim(array_pop($antes));
            }
            $cuerpo = implode(' | ', $antes);
        } else {
            $cuerpo = $txt;
        }

        $cantidad = 1.0;
        $unidad = 'PZA';
        $desc = trim($cuerpo);
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s+(\S+)\s+-\s+(.*)$/su', $desc, $m) || preg_match('/^(\d+(?:[.,]\d+)?)\s+(\S+)\s+(.*)$/su', $desc, $m)) {
            $cantidad = (float) str_replace(',', '.', $m[1]);
            $unidad = mb_substr(Str::upper($m[2]), 0, 10);
            $desc = trim($m[3]);
        } else {
            $adv[] = "partida $orden sin cantidad/unidad reconocible";
        }
        if ($cantidad <= 0) {
            $cantidad = 1.0;
        }

        if ($modelo !== null && preg_match('#^https?://#i', $modelo)) {
            $desc .= "\n".$modelo;
            $modelo = null;
        }
        $modelo = $modelo ? mb_substr(Str::squish($modelo), 0, 190) : null;

        if ($proveedor && preg_match('/^(.*?)\.?\s{2,}(\S.*)$/su', $proveedor, $m)) {
            $proveedor = trim($m[1]);
            $refs[] = "Partida $orden: ".Str::squish($m[2]);
        }

        $estado = ($item['state'] ?? '') === 'complete'
            ? EstadoPartidaPedido::Recibida
            : ($solicitada ? EstadoPartidaPedido::Solicitada : EstadoPartidaPedido::Pendiente);

        $cp = null;
        if ($uid) {
            $cot = Cotizacion::withTrashed()->where('folio', $uid)->first();
            $cp = $cot?->partidas()->where('orden', $nn)->first();
            if ($cp && ! $this->parecidas($cp, $desc, $modelo)) {
                $adv[] = "partida $orden: la partida ".sprintf('%d.%02d', $uid, $nn).' de la cotización no se parece; quedó manual';
                $cp = null;
            } elseif (! $cp) {
                $adv[] = "partida $orden: cotización ".sprintf('%d.%02d', $uid, $nn).' no encontrada; quedó manual';
            }
        }

        if ($cp) {
            $partida = PedidoPartida::desdeCotizacion($cp, $pedido, $orden, $cantidad);
            $partida->update(['estado' => $estado, 'facturada' => $facturada]);
            $this->r['ligadas']++;

            return;
        }

        $proveedorId = null;
        if ($proveedor && Nombres::llave($proveedor) !== '') {
            $nombreProv = mb_substr(Nombres::limpiar($proveedor), 0, 200);
            $prov = Proveedor::withTrashed()->where('nombre_llave', Nombres::llave($nombreProv))->first();
            if (! $prov) {
                $prov = Proveedor::create(['nombre' => $nombreProv]);
                $this->r['proveedores_nuevos'][] = $prov->nombre;
            }
            $proveedorId = $prov->id;
        }

        PedidoPartida::create([
            'pedido_id' => $pedido->id,
            'orden' => $orden,
            'descripcion' => $desc,
            'modelo' => $modelo,
            'unidad' => $unidad,
            'cantidad' => $cantidad,
            'proveedor_id' => $proveedorId,
            'costo' => $total !== null ? round($total / $cantidad / 1.16, 4) : null,
            'precio' => 0,
            'estado' => $estado,
            'facturada' => $facturada,
        ]);
        $this->r['manuales']++;
    }

    /** ¿La partida de la cotización es la misma que la del checklist? (≥2 palabras significativas o el modelo). */
    private function parecidas(CotizacionPartida $cp, string $desc, ?string $modelo): bool
    {
        $palabras = fn (string $s) => collect(preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($s)), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($w) => strlen($w) >= 4)->unique()->all();
        if (count(array_intersect($palabras((string) $cp->descripcion), $palabras($desc))) >= 2) {
            return true;
        }
        $m = Nombres::llave((string) $modelo);

        return strlen($m) >= 3 && str_contains(Nombres::llave($cp->modelo.' '.$cp->descripcion), $m);
    }

    // ---------------------------------------------------------------- resumen

    private function resumen(bool $simular, int $total): void
    {
        $r = $this->r;
        $this->newLine();
        $this->info(($simular ? 'SIMULACIÓN (no se guardó nada)' : 'IMPORTACIÓN').' — tarjetas en el archivo: '.$total);
        $this->line('Pedidos creados: '.$r['creados']);
        $this->line('Saltadas, ya importadas: '.$r['ya_importadas']);
        $this->line('Saltadas, OC duplicada: '.count($r['oc_duplicada']));
        foreach ($r['oc_duplicada'] as $d) {
            $this->line('   - '.$d);
        }
        $this->line("Partidas ligadas a cotización: {$r['ligadas']} · manuales: {$r['manuales']}");
        $this->line('Clientes nuevos ('.count($r['clientes_nuevos']).'): '.implode(', ', $r['clientes_nuevos']));
        $this->line('Proveedores nuevos ('.count($r['proveedores_nuevos']).'): '.implode(', ', $r['proveedores_nuevos']));
        if ($r['errores']) {
            $this->error('Errores ('.count($r['errores']).'):');
            foreach ($r['errores'] as $e) {
                $this->line('   - '.$e);
            }
        }
        $this->line('Tarjetas con advertencias ('.count($r['advertencias']).'):');
        foreach ($r['advertencias'] as $a) {
            $this->line('   - '.$a);
        }
    }
}

/** Señal interna: la OC de este cliente ya existe; se salta la tarjeta y se deshace lo que ella creó. */
class OcDuplicada extends RuntimeException {}
