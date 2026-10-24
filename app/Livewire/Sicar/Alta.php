<?php

namespace App\Livewire\Sicar;

use App\Enums\ColumnaPedido;
use App\Models\Ajuste;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SicarProveedor;
use App\Models\SicarSincronizacion;
use App\Support\AltaSicar;
use App\Support\AltaSicarPartidas;
use App\Support\CatalogoSat;
use App\Support\ClavesSatConIa;
use App\Support\HojaAltasSicar;
use App\Support\PuenteSicar;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Alta en SICAR')]
class Alta extends Component
{
    /** Columna del tablero cuyos pedidos se dan de alta (vacío = todas). */
    #[Url(as: 'columna')]
    public string $columna = '';

    /** Un solo pedido (botón de la tarjeta). */
    #[Url(as: 'pedido')]
    public ?int $pedido = null;

    /** @var list<int|string> pedidos con la casilla marcada */
    public array $seleccion = [];

    public string $mensaje = '';

    public string $error = '';

    /** Lote recién mandado (para el enlace). */
    public ?int $loteCreado = null;

    /** @var array<int, string> proveedor web → pro_id de SICAR elegido en el select */
    public array $proveedorSicar = [];

    public function mount(): void
    {
        $this->autorizar();
        if (! ColumnaPedido::tryFrom($this->columna)) {
            $this->columna = '';
        }
        // Se eligen las tarjetas una por una; si viene desde una tarjeta, ya va marcada.
        $this->seleccion = $this->pedido ? [(int) $this->pedido] : [];
    }

    public function marcarTodas(): void
    {
        $this->seleccion = $this->pedidosListados()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function quitarTodas(): void
    {
        $this->seleccion = [];
    }

    public function updatedColumna(): void
    {
        if (! ColumnaPedido::tryFrom($this->columna)) {
            $this->columna = '';
        }
        $this->pedido = null;
        $this->seleccion = [];
    }

    private function autorizar(): void
    {
        abort_if(! auth()->user() || auth()->user()->esCampo() || auth()->user()->esNed(), 403);
    }

    /**
     * Pedidos que se pueden dar de alta: no archivados y sin alta, o ya dados de alta con partidas que faltan en SICAR
     * (se agregaron o cambiaron después); de éstos solo cuentan las que faltan. O el pedido puntual de la tarjeta.
     */
    private function pedidosListados(): Collection
    {
        $q = Pedido::query()->whereNull('archivado_at');
        if ($this->pedido) {
            $q->whereKey($this->pedido);
        } else {
            $conFaltantes = Pedido::where('alta_sicar', true)->whereNull('archivado_at')->with('partidas')->get()
                ->filter(fn (Pedido $p) => AltaSicarPartidas::faltantes($p)->isNotEmpty())->pluck('id');
            $q->where(fn ($w) => $w->where('alta_sicar', false)->orWhereIn('id', $conFaltantes));
            if ($this->columna !== '') {
                $q->where('columna', $this->columna);
            }
        }

        $pedidos = $q->with(['cliente:id,nombre', 'partidas.proveedor:id,nombre,sicar_pro_id'])->orderBy('columna')->orderBy('posicion')->orderBy('id')->get();
        foreach ($pedidos as $p) {
            if ($p->alta_sicar) {
                $p->setRelation('partidas', AltaSicarPartidas::faltantes($p));
            }
        }

        return $pedidos;
    }

    /** Decisión "es este artículo de SICAR". */
    public function esEste(int $partidaId, int $artId): void
    {
        $this->autorizar();
        $p = $this->partida($partidaId);
        $art = (new AltaSicar)->emparejador->porId($artId);
        abort_unless($art, 404);
        $p->update(['sicar_art_id' => $art->art_id, 'sicar_clave' => null, 'sicar_clave_sat' => null, 'sicar_sat_ia' => null, 'sicar_unidad' => null, 'sicar_categoria' => null]);
    }

    /** Decisión "es un artículo nuevo": guarda la propuesta para poder editarla. */
    public function esNuevo(int $partidaId): void
    {
        $this->autorizar();
        $p = $this->partida($partidaId);
        $fila = $this->filaDe($p);
        $p->update(['sicar_art_id' => null, 'sicar_clave' => $fila['clave'], 'sicar_clave_sat' => $fila['sat'] ?: null,
            'sicar_unidad' => $fila['unidad'], 'sicar_categoria' => $fila['categoria']]);
    }

    /** Quita la decisión guardada (vuelve a proponerse solo). */
    public function deshacer(int $partidaId): void
    {
        $this->autorizar();
        $this->partida($partidaId)->update(['sicar_art_id' => null, 'sicar_clave' => null, 'sicar_clave_sat' => null, 'sicar_sat_ia' => null, 'sicar_unidad' => null, 'sicar_categoria' => null]);
    }

    /** Edita un dato del artículo nuevo (clave, unidad, categoría o clave SAT) y guarda toda la propuesta. */
    public function guardarCampo(int $partidaId, string $campo, string $valor): void
    {
        $this->autorizar();
        abort_unless(in_array($campo, ['clave', 'unidad', 'categoria', 'sat'], true), 422);
        $p = $this->partida($partidaId);
        $fila = $this->filaDe($p);
        $valor = Str::squish($valor);
        $fila[$campo] = match ($campo) {
            'clave', 'unidad', 'categoria' => Str::upper($valor),
            default => preg_replace('/\D/', '', $valor) ?? '',
        };

        if ($campo === 'clave' && $fila['clave'] === '') {
            $this->error = 'La clave no puede quedar vacía.';

            return;
        }
        if ($campo === 'clave' && mb_strlen($fila['clave']) > 45) {
            $this->error = 'La clave de SICAR admite máximo 45 caracteres.';

            return;
        }
        if ($campo === 'clave' && (new AltaSicar)->emparejador->porClave($fila['clave'])) {
            $this->error = 'Esa clave ya existe en SICAR: elige "Es este" en lugar de crearla de nuevo.';

            return;
        }
        $this->error = '';
        $p->update(['sicar_art_id' => null, 'sicar_clave' => $fila['clave'], 'sicar_clave_sat' => $fila['sat'] !== '' ? $fila['sat'] : null,
            'sicar_unidad' => mb_substr($fila['unidad'] ?: 'PZA', 0, 20), 'sicar_categoria' => mb_substr($fila['categoria'] ?: AltaSicar::CATEGORIA, 0, 80)]);
    }

    private function partida(int $id): PedidoPartida
    {
        return PedidoPartida::whereIn('pedido_id', $this->pedidosListados()->pluck('id'))->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function filaDe(PedidoPartida $p): array
    {
        $pedido = Pedido::with(['cliente:id,nombre', 'partidas.proveedor:id,nombre,sicar_pro_id'])->findOrFail($p->pedido_id);

        return (new AltaSicar)->filas(collect([$pedido]))->first(fn ($f) => $f['partida']->id === $p->id);
    }

    /** @return Collection<int, array<string, mixed>> filas de los pedidos con casilla marcada */
    private function filasSeleccionadas(): Collection
    {
        $ids = array_map('intval', $this->seleccion);
        $pedidos = $this->pedidosListados()->whereIn('id', $ids)->values();

        return (new AltaSicar)->filas($pedidos);
    }

    /** La IA elige la clave SAT (catálogo oficial) de las partidas marcadas que aún no revisa. */
    public function sugerirSat(): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        try {
            $r = (new ClavesSatConIa)->aplicar($this->filasSeleccionadas());
        } catch (Throwable $e) {
            report($e);
            $this->error = 'No se pudo consultar la IA: '.$e->getMessage();

            return;
        }
        if ($r['revisadas'] + $r['sin_respuesta'] === 0) {
            $this->mensaje = 'La IA ya revisó la clave SAT de todos los artículos marcados.';

            return;
        }
        $this->mensaje = "La IA eligió la clave SAT de {$r['revisadas']} ".($r['revisadas'] === 1 ? 'artículo' : 'artículos').'.'
            .($r['sin_respuesta'] ? " En {$r['sin_respuesta']} no encontró una clave segura: captúrala a mano." : '')
            .($r['faltan'] ? " Faltan {$r['faltan']}: vuelve a presionar el botón." : '')
            .' Revisa que el nombre de cada clave corresponda al artículo.';
    }

    /** Clave SAT capturada a mano (debe existir en el catálogo del SAT). En un artículo existente queda como corrección propuesta. */
    public function guardarSat(int $partidaId, string $valor): void
    {
        $this->autorizar();
        $clave = preg_replace('/\D/', '', $valor) ?? '';
        $p = $this->partida($partidaId);
        if ($clave === '') {
            $p->update(['sicar_clave_sat' => null, 'sicar_sat_ia' => null]);

            return;
        }
        if ($clave === CatalogoSat::GENERICA || (CatalogoSat::cargado() && ! (new CatalogoSat)->nombre($clave))) {
            $this->error = "La clave SAT {$clave} no existe en el catálogo del SAT (o es la genérica 01010101).";

            return;
        }
        $this->error = '';
        $p->update(['sicar_clave_sat' => $clave, 'sicar_sat_ia' => 'manual']);
    }

    public function descargar()
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $filas = $this->filasSeleccionadas();
        if ($filas->isEmpty()) {
            $this->error = 'Marca al menos una tarjeta con partidas.';

            return null;
        }

        $ruta = (new HojaAltasSicar)->generar($filas);

        return response()->streamDownload(function () use ($ruta) {
            readfile($ruta);
            @unlink($ruta);
        }, HojaAltasSicar::nombre(), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Recuerda para siempre a qué proveedor de SICAR corresponde un proveedor de la web. */
    public function guardarProveedorSicar(int $proveedorId): void
    {
        $this->autorizar();
        $proId = (int) ($this->proveedorSicar[$proveedorId] ?? 0);
        $sicar = SicarProveedor::where('pro_id', $proId)->first();
        if (! $sicar) {
            $this->error = 'Elige un proveedor de SICAR de la lista.';

            return;
        }
        Proveedor::findOrFail($proveedorId)->update(['sicar_pro_id' => $sicar->pro_id]);
        unset($this->proveedorSicar[$proveedorId]);
        $this->error = '';
        $this->mensaje = 'Listo: se recordará ese proveedor de SICAR.';
    }

    /** Manda a la PC de Daniel las tarjetas marcadas (simulacro → confirmar en Lotes SICAR). */
    public function mandar(): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $this->loteCreado = null;

        $ids = array_map('intval', $this->seleccion);
        $pedidos = $this->pedidosListados()->whereIn('id', $ids)->values();
        $alta = new AltaSicar;
        $filas = $alta->filas($pedidos);
        $puente = new PuenteSicar;
        $bloqueos = $puente->bloqueos($pedidos, $filas);
        if ($bloqueos !== []) {
            $this->error = 'No se puede mandar todavía: '.implode(' ', $bloqueos);

            return;
        }

        try {
            $lote = $puente->crearLote($puente->armarDatos($pedidos, $filas, $alta), (int) auth()->id(), AltaSicarPartidas::firmasDe($filas));
        } catch (\DomainException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->loteCreado = $lote->id;
        $this->seleccion = [];
        $this->mensaje = "Lote {$lote->id} mandado. En unos minutos aparece el simulacro en Lotes SICAR.";
    }

    public function marcarAlta(): void
    {
        $this->autorizar();
        $ids = array_map('intval', $this->seleccion);
        $pedidos = $this->pedidosListados()->whereIn('id', $ids);
        $n = 0;
        foreach ($pedidos as $p) {
            // Ya dado de alta: solo quedaban las partidas que faltaban (las que trae la lista); se dan por dadas de alta.
            $faltaban = $p->alta_sicar ? $p->partidas : collect();
            if ($p->alta_sicar && $faltaban->isEmpty()) {
                continue;
            }
            AltaSicarPartidas::marcar($p->alta_sicar ? $faltaban : $p->partidas);
            $p->update(['alta_sicar' => true]);
            PedidoActividad::registrar($p, 'alta_sicar', $faltaban->isNotEmpty()
                ? 'Dio de alta en SICAR a mano lo que faltaba ('.$faltaban->pluck('descripcion')->map(fn ($d) => Str::limit((string) $d, 40))->implode('; ').') — '.auth()->user()->name
                : 'Dado de alta en SICAR por '.auth()->user()->name);
            $n++;
        }
        $this->seleccion = array_values(array_diff($this->seleccion, $pedidos->pluck('id')->all()));
        $this->error = '';
        $this->mensaje = $n === 0 ? 'No había pedidos por marcar.' : ($n === 1 ? 'Se marcó 1 pedido como dado de alta en SICAR.' : "Se marcaron {$n} pedidos como dados de alta en SICAR.");
    }

    public function render()
    {
        $pedidos = $this->pedidosListados();
        $ids = array_map('intval', $this->seleccion);
        $alta = new AltaSicar;
        $filas = $alta->filas($pedidos);
        $unidadesSicar = array_values($alta->unidadesSicar()) ?: ['PZA'];
        sort($unidadesSicar);
        $sync = SicarSincronizacion::ultimaCompleta();
        $seleccionadas = $filas->filter(fn ($f) => in_array($f['pedido']->id, $ids, true))
            ->sortBy(fn ($f) => ['nuevo' => 0, 'posible' => 1, 'existe' => 2][$f['estado']] ?? 9)->values();

        $puente = new PuenteSicar;
        $pedidosSel = $pedidos->whereIn('id', $ids)->values();
        $filasSel = $filas->filter(fn ($f) => in_array($f['pedido']->id, $ids, true))->values();
        $bloqueos = $puente->bloqueos($pedidosSel, $filasSel);
        $catalogoSicar = SicarProveedor::where('status', 1)->orderBy('nombre')->get();
        $faltantes = $puente->proveedoresSinSicar($filasSel)->map(function ($prov) use ($catalogoSicar) {
            $sug = PuenteSicar::sugerido($prov, $catalogoSicar);

            return ['proveedor' => $prov, 'sugerido' => $sug,
                'opciones' => $sug ? $catalogoSicar->reject(fn ($s) => $s->pro_id === $sug->pro_id)->prepend($sug) : $catalogoSicar];
        });

        return view('livewire.sicar.alta', [
            'bloqueos' => $bloqueos,
            'faltantes' => $faltantes,
            'puenteActivo' => Ajuste::puenteActivo(),
            'pedidos' => $pedidos,
            'filasPorPedido' => $filas->groupBy(fn ($f) => $f['pedido']->id),
            'seleccionadas' => $seleccionadas,
            'sync' => $sync,
            'syncViejo' => ! $sync || $sync->fin->lt(now()->subHours(24)),
            'columnas' => ColumnaPedido::cases(),
            'conteo' => $seleccionadas->countBy('estado'),
            'pendientesSat' => ClavesSatConIa::pendientes($seleccionadas)->count(),
            'unidadesSicar' => $unidadesSicar,
        ]);
    }
}
