<?php

namespace App\Livewire\Pedidos;

use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoArchivo;
use App\Models\Venta;
use App\Support\CostoSicarVsPedido;
use App\Support\DocumentoPropio;
use App\Support\FacturaArchivo;
use App\Support\FotoEvidencia;
use App\Support\LigasPorConfirmar;
use App\Support\RegistroErrores;
use App\Support\Trazabilidad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Expediente del pedido: OC del cliente (vive en pedidos.oc_cliente_path), facturas (PDF/XML) y
 * evidencia de entrega (fotos). El rol campo sube evidencia y ve todo; no sube ni borra facturas.
 */
class Expediente extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $pedidoId;

    /** @var array<int, mixed> */
    public array $facturas = [];

    /** @var array<int, mixed> */
    public array $fotos = [];

    public string $notaFoto = '';

    /** Folio de factura o nota de Ventas para ligar a mano. */
    public string $folioVenta = '';

    /** Archivo nuevo de la OC del cliente (reemplaza al que hay). */
    public $nuevaOc = null;

    /** Partidas a las que corresponde la evidencia que se sube (informativo, no cambia su estado). */
    public array $partidasFoto = [];

    public string $mensaje = '';

    public string $error = '';

    public function mount(int $pedidoId): void
    {
        $this->pedidoId = $pedidoId;
    }

    private function pedido(): Pedido
    {
        return Pedido::findOrFail($this->pedidoId);
    }

    private function esCampo(): bool
    {
        return (bool) auth()->user()?->esCampo();
    }

    #[On('oc-cliente-actualizada')]
    public function refrescar(): void
    {
        // Solo vuelve a renderizar.
    }

    /** Reemplazar la OC del cliente desde el expediente (p. ej. si subieron por error la cotización). */
    public function updatedNuevaOc(): void
    {
        abort_if($this->esCampo(), 403);
        $this->mensaje = $this->error = '';
        $v = validator(['oc' => $this->nuevaOc], ['oc' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480']], [
            'oc.mimes' => 'La OC debe ser PDF, JPG, PNG o WEBP.', 'oc.max' => 'El archivo pesa más de 20 MB.', 'oc.file' => 'Sube un PDF o una imagen.',
        ]);
        if ($v->fails()) {
            $this->error = $v->errors()->first();
            $this->nuevaOc = null;

            return;
        }
        if ($motivo = DocumentoPropio::motivoNoEsOc((string) file_get_contents($this->nuevaOc->getRealPath()), $this->nuevaOc->getClientOriginalName())) {
            $this->error = $motivo;
            $this->nuevaOc = null;

            return;
        }
        $pedido = $this->pedido();
        $anterior = $pedido->oc_cliente_path;
        $pedido->update(['oc_cliente_path' => $this->nuevaOc->store('pedidos/oc-cliente', 'local')]);
        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }
        $this->nuevaOc = null;
        $this->mensaje = $anterior ? 'Se reemplazó el archivo de la OC del cliente.' : 'Se cargó la OC del cliente.';
        PedidoActividad::registrar($pedido, 'archivo', $anterior ? 'reemplazó el archivo de la OC del cliente' : 'subió el archivo de la OC del cliente');
        $this->dispatch('oc-cliente-actualizada');
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    /** Quitar el archivo de la OC del cliente (se subió uno equivocado). El número de OC se queda. */
    public function quitarOc(): void
    {
        abort_if($this->esCampo(), 403);
        $this->mensaje = $this->error = '';
        $pedido = $this->pedido();
        if (! $pedido->oc_cliente_path) {
            return;
        }
        Storage::disk('local')->delete($pedido->oc_cliente_path);
        $pedido->update(['oc_cliente_path' => null]);
        $this->mensaje = 'Se quitó el archivo de la OC del cliente. Sube el correcto cuando lo tengas.';
        PedidoActividad::registrar($pedido, 'archivo', 'quitó el archivo de la OC del cliente');
        $this->dispatch('oc-cliente-actualizada');
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    // ---------- Facturas y notas de SICAR ligadas al pedido (antes sección aparte en la tarjeta) ----------

    /** Solo quien tiene el permiso 'ventas' y puede ver importes de venta (el rol campo no) maneja las facturas del pedido. */
    private function puedeFacturas(): bool
    {
        $u = auth()->user();

        return $u->puede('ventas') && $u->puedeVerVenta();
    }

    private function anotar(string $tipo, string $descripcion): void
    {
        PedidoActividad::registrar($this->pedidoId, $tipo, $descripcion);
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    /** Liga una factura o nota de Ventas (por folio) a este pedido: un pedido puede tener varias. */
    public function ligarVenta(): void
    {
        abort_unless($this->puedeFacturas(), 403);
        $this->mensaje = $this->error = '';
        $folio = trim($this->folioVenta);
        $this->validate(['folioVenta' => ['required', 'string', 'max:40']], [
            'folioVenta.required' => 'Escribe el folio de la factura o la nota.',
            'folioVenta.max' => 'El folio es muy largo.',
        ]);

        $venta = Venta::where('folio_llave', Venta::llave('factura', $folio))->first()
            ?? Venta::where('folio_llave', Venta::llave('nota', $folio))->first();
        if (! $venta) {
            $this->addError('folioVenta', 'No encontré ese folio en Ventas.');

            return;
        }
        $pedido = $this->pedido();
        if ($pedido->ventas()->whereKey($venta->id)->exists()) {
            $this->addError('folioVenta', 'Ese documento ya está ligado a este pedido.');

            return;
        }

        Trazabilidad::ligarPedido($pedido, $venta);
        $this->folioVenta = '';
        $queEs = $venta->tipo === 'nota' ? 'la nota' : 'la factura';
        $this->mensaje = 'Se ligó '.$queEs.' '.$venta->folio.' a este pedido.';
        $this->anotar('factura_ligada', 'ligó '.$queEs.' '.$venta->folio);
    }

    /** "Sí": la razón social de esa factura sí factura por el cliente de este pedido (confirma también las demás del mismo par). */
    public function confirmarLiga(int $ventaId): void
    {
        abort_unless($this->puedeFacturas(), 403);
        $n = LigasPorConfirmar::confirmar($this->pedidoId, $ventaId, auth()->id());
        $this->mensaje = $n > 0 ? 'Listo: quedó ligada'.($n > 1 ? " (y otras $n del mismo nombre)" : '').'. De ahora en adelante esa razón social se liga sola.' : '';
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    /** "No": se quita la propuesta y no se vuelve a proponer ese par. */
    public function rechazarLiga(int $ventaId): void
    {
        abort_unless($this->puedeFacturas(), 403);
        $n = LigasPorConfirmar::rechazar($this->pedidoId, $ventaId, auth()->id());
        $this->mensaje = $n > 0 ? 'Listo: no se liga y no se vuelve a preguntar por ese nombre.' : '';
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    public function quitarVenta(int $ventaId): void
    {
        abort_unless($this->puedeFacturas(), 403);
        $pedido = $this->pedido();
        $venta = $pedido->ventas()->where('ventas.id', $ventaId)->first();
        if (! $venta) {
            return;
        }
        Trazabilidad::desligarPedido($pedido, $venta);
        $queEs = $venta->tipo === 'nota' ? 'la nota' : 'la factura';
        $this->mensaje = 'Se quitó '.$queEs.' '.$venta->folio.' de este pedido.';
        $this->anotar('factura_ligada', 'quitó '.$queEs.' '.$venta->folio);
    }

    /**
     * Cada factura/nota ligada con sus archivos (por UUID o folio leídos del CFDI; el PDF que se subió junto con el
     * XML —mismo nombre— va con él) y lo que le falta. Los archivos que no son de ninguna quedan en grupos aparte.
     *
     * @return list<array{venta: ?Venta, archivos: Collection, folio: ?string, falta: list<string>}>
     */
    private function gruposFacturas($archivos, $ventas): array
    {
        $raiz = fn (PedidoArchivo $a) => mb_strtolower(pathinfo((string) $a->nombre_original, PATHINFO_FILENAME));
        $usados = [];
        $grupos = [];
        foreach ($ventas as $v) {
            $folio = mb_strtoupper(preg_replace('/\s+/u', '', (string) $v->folio));
            $uuid = $v->uuid ? mb_strtoupper($v->uuid) : null;
            $directos = $archivos->filter(fn ($a) => ($uuid && $a->cfdi_uuid === $uuid) || ($a->cfdi_folio && $a->cfdi_folio === $folio));
            $raices = $directos->map($raiz)->unique();
            $suyos = $archivos->filter(fn ($a) => ! isset($usados[$a->id]) && ($directos->contains('id', $a->id) || $raices->contains($raiz($a))))->values();
            foreach ($suyos as $a) {
                $usados[$a->id] = true;
            }
            $falta = $v->tipo === 'factura' && $v->estatus !== 'cancelada' ? array_values(array_filter([
                $suyos->contains('tipo', 'factura_pdf') ? null : 'el PDF',
                $suyos->contains('tipo', 'factura_xml') ? null : 'el XML',
            ])) : [];
            $grupos[] = ['venta' => $v, 'archivos' => $suyos, 'folio' => $v->folio, 'falta' => $falta];
        }
        foreach ($archivos->reject(fn ($a) => isset($usados[$a->id]))->groupBy($raiz) as $lista) {
            $grupos[] = ['venta' => null, 'archivos' => $lista->values(), 'folio' => $lista->pluck('cfdi_folio')->filter()->first(), 'falta' => []];
        }

        return $grupos;
    }

    public function updatedFacturas(): void
    {
        abort_if($this->esCampo(), 403);
        $this->mensaje = $this->error = '';

        $archivos = array_values(array_filter($this->facturas));
        $this->facturas = [];
        if (! $archivos) {
            return;
        }

        $v = validator(
            ['facturas' => $archivos],
            ['facturas.*' => ['file', 'mimes:pdf,xml', 'max:10240']],
            ['facturas.*.mimes' => 'La factura debe ser un PDF o un XML.', 'facturas.*.max' => 'Un archivo pesa más de 10 MB.', 'facturas.*.file' => 'Sube un PDF o un XML.'],
        );
        if ($v->fails()) {
            $this->error = $v->errors()->first();

            return;
        }

        $pedido = $this->pedido();
        $ligadas = [];
        foreach ($archivos as $a) {
            $ext = strtolower($a->getClientOriginalExtension()) === 'xml' ? 'xml' : 'pdf';
            $archivo = PedidoArchivo::create([
                'pedido_id' => $pedido->id,
                'tipo' => $ext === 'xml' ? 'factura_xml' : 'factura_pdf',
                'disco' => 'local',
                'ruta' => $a->storeAs('pedidos/'.$pedido->id.'/facturas', bin2hex(random_bytes(8)).'.'.$ext, 'local'),
                'nombre_original' => $a->getClientOriginalName(),
                'mime' => $ext === 'xml' ? 'application/xml' : 'application/pdf',
                'tamano' => $a->getSize(),
                'user_id' => auth()->id(),
            ]);
            // Si es CFDI, se liga con su factura de SICAR (o queda listo para cuando llegue). Nunca frena la subida.
            try {
                if ($folio = FacturaArchivo::procesar($archivo)) {
                    $ligadas[$folio] = true;
                }
            } catch (\Throwable $e) {
                RegistroErrores::registrar($e);
            }
        }
        $this->mensaje = (count($archivos) === 1 ? 'Factura guardada en el expediente.' : count($archivos).' archivos de factura guardados.')
            .($ligadas ? ' Quedó ligada con la factura '.implode(', ', array_keys($ligadas)).' de SICAR.' : '');
        PedidoActividad::registrar($pedido, 'archivo', 'subió '.(count($archivos) === 1 ? 'la factura '.$archivos[0]->getClientOriginalName() : count($archivos).' archivos de factura'));
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    public function updatedFotos(): void
    {
        $this->mensaje = $this->error = '';

        $archivos = array_values(array_filter($this->fotos));
        $this->fotos = [];
        if (! $archivos) {
            return;
        }

        $v = validator(
            ['fotos' => $archivos],
            ['fotos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360']],
            ['fotos.*.mimes' => 'Las fotos deben ser JPG, PNG o WEBP.', 'fotos.*.max' => 'Una foto pesa más de 15 MB.', 'fotos.*.file' => 'Sube una foto.'],
        );
        if ($v->fails()) {
            $this->error = $v->errors()->first();

            return;
        }

        $pedido = $this->pedido();
        $nota = trim($this->notaFoto);
        $ids = $pedido->partidas()->whereIn('id', array_map('intval', $this->partidasFoto))->pluck('id')->map(fn ($i) => (int) $i)->all();

        $guardadas = 0;
        foreach ($archivos as $a) {
            $jpeg = FotoEvidencia::procesar($a->getRealPath());
            if ($jpeg === null) {
                $this->error = 'No se pudo leer una de las fotos ('.$a->getClientOriginalName().').';

                continue;
            }
            $ruta = 'pedidos/'.$pedido->id.'/evidencia/'.bin2hex(random_bytes(8)).'.jpg';
            Storage::disk('local')->put($ruta, $jpeg);
            PedidoArchivo::create([
                'pedido_id' => $pedido->id,
                'tipo' => 'evidencia',
                'disco' => 'local',
                'ruta' => $ruta,
                'nombre_original' => pathinfo($a->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
                'mime' => 'image/jpeg',
                'tamano' => strlen($jpeg),
                'user_id' => auth()->id(),
                'notas' => $nota !== '' ? mb_substr($nota, 0, 500) : null,
                'partidas_ids' => $ids ?: null,
            ]);
            $guardadas++;
        }

        if ($guardadas) {
            $this->mensaje = $guardadas === 1 ? 'Foto de evidencia guardada.' : $guardadas.' fotos de evidencia guardadas.';
            PedidoActividad::registrar($pedido, 'archivo', 'subió '.($guardadas === 1 ? 'una foto' : $guardadas.' fotos').' de evidencia de entrega');
            $this->dispatch('pedido-actualizado');
            $this->dispatch('facturas-actualizadas');
            $this->notaFoto = '';
            $this->partidasFoto = [];
        }
    }

    public function borrar(int $archivoId): void
    {
        $archivo = PedidoArchivo::where('pedido_id', $this->pedidoId)->findOrFail($archivoId);
        $user = auth()->user();

        // Solo quien lo subió o un administrador; el rol campo únicamente puede borrar evidencia.
        abort_unless($user->esAdmin() || $archivo->user_id === $user->id, 403);
        abort_if($user->esCampo() && $archivo->tipo !== 'evidencia', 403);

        $archivo->delete();
        $this->mensaje = 'Archivo eliminado.';
        PedidoActividad::registrar($this->pedidoId, 'archivo', 'borró el archivo '.$archivo->nombre_original);
        $this->dispatch('pedido-actualizado');
        $this->dispatch('facturas-actualizadas');
    }

    public function render()
    {
        $pedido = $this->pedido();
        $archivos = $pedido->archivos()->with('user:id,name')->get();
        $user = auth()->user();

        $verFacturas = $this->puedeFacturas();
        $ventas = $verFacturas ? $pedido->ventas()->orderBy('ventas.fecha')->orderBy('ventas.id')->get() : collect();

        return view('livewire.pedidos.expediente', [
            'pedido' => $pedido,
            'verFacturas' => $verFacturas,
            // Campo también ve y abre los PDF/XML de las facturas (decisión de Daniel, 18-oct-2026); sin datos de SICAR ni cobro.
            'gruposFacturas' => $this->gruposFacturas($archivos->whereIn('tipo', ['factura_pdf', 'factura_xml'])->values(), $ventas),
            'costoSicar' => $verFacturas && $user->esAdmin() ? CostoSicarVsPedido::dePedido($pedido) : null,
            'ventasPorConfirmar' => $verFacturas ? LigasPorConfirmar::delPedido($pedido) : collect(),
            'listaEvidencias' => $archivos->where('tipo', 'evidencia')->values(),
            'partidas' => $pedido->partidas()->get(['id', 'orden', 'descripcion']),
            'esCampo' => $this->esCampo(),
            'tieneOc' => (bool) $pedido->oc_cliente_path && Storage::disk('local')->exists($pedido->oc_cliente_path),
            'ocEsImagen' => (bool) preg_match('/\.(jpe?g|png|webp)$/i', (string) $pedido->oc_cliente_path),
            'puedeBorrar' => fn (PedidoArchivo $a) => $user->esAdmin() || ($a->user_id === $user->id && (! $user->esCampo() || $a->tipo === 'evidencia')),
        ]);
    }
}
