<?php

namespace App\Livewire\Cotizaciones;

use App\Enums\EstadoCotizacion;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\ListaPrecioRenglon;
use App\Models\Ned\NedCliente;
use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedPartida;
use App\Models\ProductoImagen;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use App\Models\User;
use App\Support\CatalogoImagenes;
use App\Support\EliminarCotizacion;
use App\Support\Folios;
use App\Support\ImagenPartida;
use App\Support\Limites;
use App\Support\LinkCompra;
use App\Support\ListaPartidas;
use App\Support\ListasPrecios;
use App\Support\Nombres;
use App\Support\Precios;
use App\Support\UnificarDuplicados;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Cotización')]
class Editor extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $cotizacionId = null;

    /** Encabezado editable (se guarda al salir de cada campo). */
    public array $enc = [
        'contacto_id' => '', 'fecha' => '', 'vigencia_dias' => '', 'forma_pago' => '',
        'condiciones_entrega' => '', 'notas' => '', 'estado' => 'borrador',
    ];

    /** Partidas como texto de formulario; se guardan por renglón. */
    public array $partidas = [];

    /** Archivos subidos, por índice de partida. */
    public array $imagenes = [];

    public string $buscarCliente = '';

    public string $nuevoCliente = '';

    public bool $cambiandoCliente = false;

    public string $mensaje = '';

    /** Cuántos renglones del historial de cambios se muestran. */
    public int $limiteHistorial = 50;

    public function verMasHistorial(): void
    {
        $this->limiteHistorial += 50;
    }

    public function mount(?Cotizacion $cotizacion = null): void
    {
        if ($cotizacion && $cotizacion->exists) {
            $this->cotizacionId = $cotizacion->id;
            $this->cargarEncabezado($cotizacion);
            $this->cargarPartidas();
        }
    }

    // ---------- Cliente ----------

    public function seleccionarCliente(int $id)
    {
        $cliente = Cliente::findOrFail($id);

        if (! $this->cotizacionId) {
            $cot = Cotizacion::create([
                'folio' => Folios::siguiente(),
                'cliente_id' => $cliente->id,
                'vendedor_id' => auth()->id(),
                'fecha' => now()->toDateString(),
                'vigencia_dias' => config('masume.cotizacion.vigencia_dias', 15),
                'condiciones_entrega' => config('masume.cotizacion.condiciones_entrega'),
                'estado' => EstadoCotizacion::Borrador,
            ]);

            return $this->redirectRoute('cotizaciones.editar', $cot);
        }

        $cot = $this->cot();
        if ($cot->cliente_id !== $cliente->id) {
            $cot->update(['cliente_id' => $cliente->id, 'contacto_id' => null]);
            $this->enc['contacto_id'] = '';
        }
        $this->cambiandoCliente = false;
        $this->buscarCliente = '';
        $this->mensaje = 'Cliente actualizado.';
    }

    public function altaCliente()
    {
        $nombre = Nombres::limpiar($this->nuevoCliente);
        if ($nombre === '' || Nombres::llave($nombre) === '') {
            $this->addError('nuevoCliente', 'Escribe el nombre del cliente.');

            return null;
        }

        $existente = Cliente::porNombre($nombre);
        if ($existente) {
            $this->addError('nuevoCliente', "Ya existe el cliente {$existente->nombre}. Búscalo arriba y selecciónalo.");
            $this->buscarCliente = $existente->nombre;

            return null;
        }

        if ($borrado = Cliente::borradoConNombre($nombre)) {
            $this->addError('nuevoCliente', "El cliente {$borrado->nombre} fue borrado. Busca el correcto arriba o pide al administrador que lo revise.");

            return null;
        }

        $cliente = Cliente::create(['nombre' => $nombre]);
        $this->nuevoCliente = '';

        return $this->seleccionarCliente($cliente->id);
    }

    public function cambiarCliente(): void
    {
        $this->cambiandoCliente = true;
    }

    public function cancelarCambioCliente(): void
    {
        $this->cambiandoCliente = false;
    }

    /** Tras enviar el correo, refleja el estado nuevo sin pisarlo en el siguiente autoguardado. */
    #[On('envio-registrado')]
    public function envioRegistrado(string $estado = ''): void
    {
        if ($estado !== '') {
            $this->enc['estado'] = $estado;
        }
    }

    /** Se pidió revisión, se comentó o se marcó revisada: vuelve a pintar el historial de cambios. */
    #[On('revision-actualizada')]
    public function revisionActualizada(): void
    {
        // Solo vuelve a renderizar.
    }

    // ---------- Encabezado ----------

    public function updatedEnc($valor, string $campo): void
    {
        $cot = $this->cot();
        if (! $cot) {
            return;
        }

        $reglas = [
            'contacto_id' => ['nullable', 'integer'],
            'fecha' => ['required', 'date'],
            'vigencia_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'forma_pago' => ['nullable', 'string', 'max:255'],
            'condiciones_entrega' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'estado' => ['required', 'in:'.implode(',', array_column(EstadoCotizacion::cases(), 'value'))],
            // Quién la elaboró (sale en el PDF con su correo): p. ej. la empezó Rosa y la termina Daniel.
            'vendedor_id' => ['required', Rule::exists('users', 'id')->where('activo', true)->whereIn('rol', ['admin', 'vendedor'])],
        ];
        if (! isset($reglas[$campo])) {
            return;
        }

        $this->validate(["enc.$campo" => $reglas[$campo]], [
            'enc.fecha.required' => 'Escribe la fecha.',
            'enc.vigencia_dias.required' => 'Escribe los días de vigencia.',
            'enc.vigencia_dias.integer' => 'La vigencia debe ser un número de días.',
            'enc.vendedor_id.*' => 'Elige quién elaboró la cotización.',
        ]);

        if ($campo === 'contacto_id') {
            $valor = $valor === '' || $valor === null ? null : (int) $valor;
            if ($valor && ! $cot->cliente->contactos()->whereKey($valor)->exists()) {
                $valor = null;
                $this->enc['contacto_id'] = '';
            }
        } elseif ($campo === 'vendedor_id') {
            $valor = (int) $valor;
        } elseif (in_array($campo, ['forma_pago', 'condiciones_entrega', 'notas'], true)) {
            $valor = trim((string) $valor) === '' ? null : trim((string) $valor);
        }

        $cot->update([$campo => $valor]);
        $this->mensaje = '';
    }

    // ---------- Partidas ----------

    public function agregarPartida(): void
    {
        $cot = $this->cot();
        if (! $cot) {
            return;
        }
        if ($cot->partidas()->count() >= (int) config('masume.cotizacion.max_partidas', 150)) {
            $this->mensaje = 'Llegaste al máximo de partidas por cotización.';

            return;
        }

        $cot->partidas()->create([
            'orden' => ((int) $cot->partidas()->max('orden')) + 1,
            'descripcion' => '', 'unidad' => 'PZA', 'cantidad' => 1, 'precio' => 0, 'importe' => 0,
            'incluir' => true,
        ]);
        $this->cargarPartidas();
    }

    /** Campos de la partida que se editan desde el navegador (resources/js/partidas.js). */
    private const CAMPOS_EDITABLES = [
        'cantidad', 'unidad', 'descripcion', 'proveedor', 'proveedor_vendedor', 'proveedor_telefono', 'link_compra', 'marca', 'modelo',
        'costo_sin', 'costo_con', 'utilidad_pct', 'precio', 'tiempo_entrega', 'articulo_id', 'lista_renglon',
    ];

    /**
     * Guarda uno o varios campos de una partida (por id, no por posición). Es una llamada con sus
     * valores: aunque el servidor tarde, una respuesta anterior no puede borrar lo que se escribió.
     *
     * @param  array<string, mixed>  $valores
     */
    public function guardarCampos(int $partidaId, array $valores): void
    {
        $i = collect($this->partidas)->search(fn ($p) => (int) $p['id'] === $partidaId);
        if ($i === false) {
            return;
        }
        foreach ($valores as $campo => $valor) {
            if (! in_array($campo, self::CAMPOS_EDITABLES, true) || ! (is_scalar($valor) || $valor === null)) {
                continue;
            }
            $this->anterior = (string) ($this->partidas[$i][$campo] ?? '');
            $this->partidas[$i][$campo] = (string) $valor;
            $this->updatedPartidas((string) $valor, $i.'.'.$campo);
        }
    }

    /** "Desligar": la partida deja de ser ese artículo del catálogo (los datos escritos se quedan). */
    public function desligarArticulo(int $partidaId): void
    {
        $this->guardarCampos($partidaId, ['articulo_id' => '']);
    }

    /** Campo que se está guardando (para saber si el precio o la utilidad manda). */
    private string $ultimoCampo = '';

    /** Valor que tenía el campo antes del cambio (para saber si se cambió de vendedor). */
    private string $anterior = '';

    public function updatingPartidas($valor, string $clave): void
    {
        [$i, $campo] = array_pad(explode('.', $clave, 2), 2, null);
        $this->anterior = (string) ($this->partidas[$i][$campo] ?? '');
    }

    public function updatedPartidas($valor, string $clave): void
    {
        [$i, $campo] = array_pad(explode('.', $clave, 2), 2, null);
        // Una pantalla vieja (sin recargar) puede mandar el campo de una partida que ya se borró o movió:
        // eso crearía una "partida fantasma" incompleta (E-QULCPN). Se descarta y se recargan de la BD.
        if ($campo === null || ! isset($this->partidas[$i]['id'])) {
            $this->cargarPartidas();

            return;
        }

        $p = $this->partidas[$i];
        $this->ultimoCampo = $campo;

        switch ($campo) {
            case 'costo_sin':
            case 'costo_con':
                $costo = self::num($valor);
                if ($campo === 'costo_con') {
                    $costo = round(Precios::costoSinIva($costo), 4);
                }
                if (trim((string) $valor) === '' || $costo <= 0) {
                    $p['costo_sin'] = $p['costo_con'] = '';
                    $costo = null;
                } else {
                    $p['costo_sin'] = self::fmtCosto($costo);
                    $p['costo_con'] = number_format($costo * (1 + Precios::iva()), 2, '.', '');
                }
                if ($costo && $p['utilidad_pct'] !== '') {
                    $p['precio'] = number_format(Precios::precioDesdeUtilidad($costo, self::num($p['utilidad_pct'])), 2, '.', '');
                } elseif ($costo && self::num($p['precio']) > 0) {
                    $p['utilidad_pct'] = self::fmtUtilidad(Precios::utilidadDesdePrecio($costo, self::num($p['precio'])));
                }
                break;

            case 'utilidad_pct':
                $costo = self::num($p['costo_sin']);
                if ($costo > 0 && trim((string) $valor) !== '') {
                    $p['precio'] = number_format(Precios::precioDesdeUtilidad($costo, self::num($valor)), 2, '.', '');
                }
                break;

            case 'precio':
                $costo = self::num($p['costo_sin']);
                $p['precio'] = number_format(self::num($valor), 2, '.', '');
                $p['utilidad_pct'] = $costo > 0 ? self::fmtUtilidad(Precios::utilidadDesdePrecio($costo, self::num($valor))) : '';
                break;

            case 'proveedor':
                $antes = $p['proveedor_id'] ?? null;
                $p['proveedor_id'] = $this->resolverProveedor((string) $valor);
                // Otro proveedor: el vendedor y el teléfono eran del anterior. Si se quedaban, se guardaban como contacto del
                // nuevo (Mauro de DISNOR terminó en PILAS Y BATERIAS UNIV). Si en la misma llamada viene el vendedor, se pone después.
                if ($antes && (int) $antes !== (int) $p['proveedor_id']) {
                    $p['proveedor_vendedor'] = $p['proveedor_telefono'] = '';
                }
                // Se muestra con el nombre ya guardado (corrige errores de dedo y quita la sucursal).
                $p['proveedor'] = $p['proveedor_id'] ? (string) Proveedor::whereKey($p['proveedor_id'])->value('nombre') : '';
                $p = $this->contactoProveedor($p, $campo);
                break;

            case 'proveedor_vendedor':
            case 'proveedor_telefono':
                $p = $this->contactoProveedor($p, $campo);
                break;

            case 'articulo_id':
                // Se eligió en "¿Es este?" (o se desligó con vacío): solo un artículo vigente del catálogo.
                $id = (int) $valor;
                $p['articulo_id'] = $id > 0 && Articulo::whereKey($id)->exists() ? $id : null;
                break;

            case 'lista_renglon':
                // Se eligió un producto de la lista de precios (Truper, Urrea…): nace su artículo y la partida queda ligada.
                $renglon = ListaPrecioRenglon::find((int) $valor);
                $p['articulo_id'] = $renglon ? ListasPrecios::articuloDe($renglon)->id : ($p['articulo_id'] ?? null);
                unset($p['lista_renglon']);
                break;
        }

        // Se subió la imagen antes de escribir la descripción: se liga al producto en cuanto tiene nombre.
        if ($campo === 'descripcion' && trim($this->anterior) === '' && ! empty($p['imagen_path'])
            && ! CatalogoImagenes::buscarFila($p['modelo'] ?? null, $p['descripcion'] ?? null, true)) {
            CatalogoImagenes::vincular($p['modelo'] ?? null, $p['descripcion'] ?? null, $p['imagen_path'], auth()->id());
        }

        // Producto que ya tiene imagen guardada: se le pone sola (o se cambia por la del modelo escrito,
        // si la que tenía también vino del catálogo).
        $cambiable = empty($p['imagen_path']) || ($campo === 'modelo' && CatalogoImagenes::esDelCatalogo($p['imagen_path']));
        if (in_array($campo, ['descripcion', 'modelo'], true) && $cambiable
            && ($ruta = CatalogoImagenes::imagenPara($p['modelo'] ?? null, $p['descripcion'] ?? null))
            && $ruta !== ($p['imagen_path'] ?? null)) {
            $p['imagen_path'] = $ruta;
            $this->mensaje = 'Se puso la imagen guardada de este producto. Si no es, ábrela y quítala o cámbiala.';
        }

        $this->partidas[$i] = $p;
        // Solo se guardan las columnas de este campo: con dos pantallas abiertas no se pisan los cambios del otro.
        $this->persistirPartida((int) $i, self::COLUMNAS_POR_CAMPO[$campo] ?? null);
    }

    /** Columnas que cambia cada campo editable (además del importe, que se recalcula siempre). */
    private const COLUMNAS_POR_CAMPO = [
        'costo_sin' => ['costo', 'precio', 'utilidad_pct'], 'costo_con' => ['costo', 'precio', 'utilidad_pct'],
        'utilidad_pct' => ['utilidad_pct', 'precio'], 'precio' => ['precio', 'utilidad_pct'],
        'cantidad' => ['cantidad'], 'unidad' => ['unidad'], 'marca' => ['marca'], 'tiempo_entrega' => ['tiempo_entrega'],
        'descripcion' => ['descripcion', 'imagen_path'], 'modelo' => ['modelo', 'link_compra', 'imagen_path'], 'link_compra' => ['modelo', 'link_compra'],
        'proveedor' => ['proveedor_id', 'proveedor_vendedor', 'proveedor_telefono'],
        'proveedor_vendedor' => ['proveedor_vendedor', 'proveedor_telefono'], 'proveedor_telefono' => ['proveedor_vendedor', 'proveedor_telefono'],
        'articulo_id' => ['articulo_id'], 'lista_renglon' => ['articulo_id'],
    ];

    public function updatedImagenes($valor, string $indice): void
    {
        $i = (int) $indice;

        try {
            $this->validate(
                ["imagenes.$i" => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']],
                [
                    "imagenes.$i.image" => 'El archivo debe ser una imagen.',
                    "imagenes.$i.mimes" => 'Usa una imagen JPG, PNG o WEBP.',
                    "imagenes.$i.max" => 'La imagen pesa más de 5 MB.',
                ],
            );
        } catch (ValidationException $e) {
            unset($this->imagenes[$i]);
            throw $e;
        }

        $archivo = $this->imagenes[$i] ?? null;
        unset($this->imagenes[$i]);
        if (! $archivo instanceof TemporaryUploadedFile || ! isset($this->partidas[$i])) {
            return;
        }

        $ruta = $this->guardarImagen($archivo);
        if (! $ruta) {
            $this->addError("imagenes.$i", 'No se pudo procesar la imagen.');

            return;
        }

        $anterior = $this->partidas[$i]['imagen_path'] ?? null;
        $this->partidas[$i]['imagen_path'] = $ruta;
        $this->persistirPartida($i, ['imagen_path']);
        // Queda ligada al producto: la próxima vez que se cotice, se pone sola.
        $p = $this->partidas[$i];
        $yaTenia = (bool) CatalogoImagenes::buscarFila($p['modelo'] ?? null, $p['descripcion'] ?? null, true);
        if ($ficha = CatalogoImagenes::vincular($p['modelo'] ?? null, $p['descripcion'] ?? null, $ruta, auth()->id())) {
            $this->mensaje = $yaTenia
                ? 'Este producto ya tenía imagen guardada: se cambió por la nueva.'
                : 'Imagen guardada: se pondrá sola la próxima vez que se cotice este producto.';
            $this->avisarParecidos($p['descripcion'] ?? null, $ficha->id);
        }
        $this->borrarArchivoSiSobra($anterior, (int) $p['id']);
    }

    /** "Ya hay otro parecido": para que usen el del catálogo en vez de duplicarlo. */
    private function avisarParecidos(?string $descripcion, ?int $excepto): void
    {
        $parecidos = CatalogoImagenes::parecidos($descripcion, $excepto);
        if ($parecidos->isNotEmpty()) {
            $this->mensaje .= ' Ojo: en el catálogo ya hay '.($parecidos->count() === 1 ? 'uno parecido' : 'otros parecidos').': '
                .$parecidos->map(fn ($f) => '«'.Str::limit($f->descripcion, 60).'»'.($f->modelo ? " ({$f->modelo})" : ''))->implode(', ')
                .'. Si es el mismo producto, usa "Buscar en catálogo".';
        }
    }

    // ---------- Buscar en catálogo ----------

    /** Partida (índice) para la que está abierta la ventana "Buscar en catálogo". */
    public ?int $catalogoPara = null;

    public string $buscarCatalogo = '';

    public function abrirCatalogo(int $i): void
    {
        if (! isset($this->partidas[$i])) {
            return;
        }
        $this->catalogoPara = $i;
        // Arranca buscando con la descripción de la partida (sus palabras principales).
        $this->buscarCatalogo = collect(preg_split('/\s+/u', trim((string) $this->partidas[$i]['descripcion'])) ?: [])
            ->filter(fn ($w) => mb_strlen($w) >= 4)->take(2)->implode(' ');
    }

    public function cerrarCatalogo(): void
    {
        $this->catalogoPara = null;
        $this->buscarCatalogo = '';
    }

    /** Usa la imagen de un producto del catálogo; si la partida es otro nombre del mismo producto, también se liga. */
    public function usarDelCatalogo(int $fichaId): void
    {
        $i = $this->catalogoPara;
        $ficha = ProductoImagen::find($fichaId);
        if ($i === null || ! isset($this->partidas[$i]) || ! $ficha) {
            return;
        }
        $p = $this->partidas[$i];
        $anterior = $p['imagen_path'] ?? null;
        $this->partidas[$i]['imagen_path'] = $ficha->imagen_path;
        $this->persistirPartida($i, ['imagen_path']);
        $ficha->increment('veces_usada');
        if (! CatalogoImagenes::buscarFila($p['modelo'] ?? null, $p['descripcion'] ?? null, true)) {
            CatalogoImagenes::vincular($p['modelo'] ?? null, $p['descripcion'] ?? null, $ficha->imagen_path, auth()->id());
        }
        if ($anterior !== $ficha->imagen_path) {
            $this->borrarArchivoSiSobra($anterior, (int) $p['id']);
        }
        $this->cerrarCatalogo();
        $this->mensaje = 'Se usó la imagen del catálogo.';
    }

    public function quitarImagen(int $i): void
    {
        if (! isset($this->partidas[$i])) {
            return;
        }
        $p = $this->partidas[$i];
        $anterior = $p['imagen_path'] ?? null;
        $this->partidas[$i]['imagen_path'] = null;
        $this->persistirPartida($i, ['imagen_path']);
        // Se desliga del producto: ya no se pondrá sola (las cotizaciones hechas conservan la suya).
        if (CatalogoImagenes::desvincular($p['modelo'] ?? null, $p['descripcion'] ?? null)) {
            $this->mensaje = 'Imagen quitada: ya no se pondrá sola en este producto.';
        }
        $this->borrarArchivoSiSobra($anterior, (int) $p['id']);
    }

    public function borrarPartida(int $i): void
    {
        $partida = $this->partidaModelo($i);
        if (! $partida) {
            return;
        }
        $ruta = $partida->imagen_path;
        $partida->delete();
        $this->borrarArchivoSiSobra($ruta, 0);
        $this->reordenar();
        $this->cot()->recalcularTotales();
        $this->cargarPartidas();
    }

    public function duplicarPartida(int $i): void
    {
        $partida = $this->partidaModelo($i);
        if (! $partida) {
            return;
        }

        $cot = $this->cot();
        $cot->partidas()->where('orden', '>', $partida->orden)->increment('orden');

        $copia = $partida->replicate();
        $copia->orden = $partida->orden + 1;
        if ($partida->imagen_path && Storage::disk('public')->exists($partida->imagen_path)) {
            $nueva = 'partidas/'.Str::random(40).'.'.pathinfo($partida->imagen_path, PATHINFO_EXTENSION);
            Storage::disk('public')->copy($partida->imagen_path, $nueva);
            $copia->imagen_path = $nueva;
        }
        $copia->save();

        $cot->recalcularTotales();
        $this->cargarPartidas();
    }

    public function subir(int $i): void
    {
        $this->mover($i, -1);
    }

    public function bajar(int $i): void
    {
        $this->mover($i, 1);
    }

    private function mover(int $i, int $delta): void
    {
        $a = $this->partidaModelo($i);
        $b = isset($this->partidas[$i + $delta]) ? $this->partidaModelo($i + $delta) : null;
        if (! $a || ! $b) {
            return;
        }

        [$oa, $ob] = [$a->orden, $b->orden];
        $a->update(['orden' => $ob]);
        $b->update(['orden' => $oa]);
        $this->reordenar();
        $this->cargarPartidas();
    }

    // ---------- Acciones de la cotización ----------

    /** Solo el admin: elimina la cotización (borrado suave) y regresa a la lista. */
    public function eliminarCotizacion()
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $cot = $this->cot();
        if (! $cot) {
            return null;
        }
        try {
            EliminarCotizacion::eliminar($cot, auth()->user());
        } catch (DomainException $e) {
            $this->mensaje = $e->getMessage();

            return null;
        }
        session()->flash('exito', "Se eliminó la cotización {$cot->folio}.");

        return $this->redirectRoute('cotizaciones.index');
    }

    public function duplicarCotizacion()
    {
        $cot = $this->cot();
        if (! $cot) {
            return null;
        }

        $nueva = $cot->replicate(['folio', 'estado', 'enviada_at', 'subtotal', 'iva', 'total', 'revision',
            'revision_pedida_at', 'revision_pedida_por', 'revisada_at', 'revisada_por']);
        $nueva->folio = Folios::siguiente();
        $nueva->estado = EstadoCotizacion::Borrador;
        $nueva->fecha = now()->toDateString();
        $nueva->vendedor_id = auth()->id();
        $nueva->save();

        foreach ($cot->partidas as $partida) {
            $copia = $partida->replicate();
            $copia->cotizacion_id = $nueva->id;
            if ($partida->imagen_path && Storage::disk('public')->exists($partida->imagen_path)) {
                $ruta = 'partidas/'.Str::random(40).'.'.pathinfo($partida->imagen_path, PATHINFO_EXTENSION);
                Storage::disk('public')->copy($partida->imagen_path, $ruta);
                $copia->imagen_path = $ruta;
            }
            $copia->save();
        }
        $nueva->recalcularTotales();

        session()->flash('exito', "Cotización duplicada. Nuevo folio: {$nueva->folio}.");

        return $this->redirectRoute('cotizaciones.editar', $nueva);
    }

    /**
     * Crea una cotización de Ned Tracking con las mismas partidas (descripción, cantidad, unidad y costo)
     * para clientes que tienen dado de alta a Ned Tracking. No pasa el cliente ni el precio de MASUME: en Ned
     * el admin aplica su utilidad sobre el costo. Las dos cotizaciones quedan independientes.
     */
    public function pasarANed()
    {
        abort_unless(auth()->user()?->nedDesdeMasume(), 403);
        $cot = $this->cot();
        if (! $cot) {
            return null;
        }

        $ned = DB::transaction(function () use ($cot) {
            // Cliente predeterminado de Ned (HOTEL CENTRAL), no el de MASUME.
            $ned = NedCotizacion::create([
                'folio' => NedCotizacion::siguienteFolio(),
                'user_id' => auth()->id(),
                'fecha' => now()->toDateString(),
            ] + NedCliente::datosNuevaCotizacion());
            $orden = 0;
            foreach ($cot->partidasIncluidas()->orderBy('orden')->get(['descripcion', 'cantidad', 'unidad', 'costo']) as $p) {
                // El costo (sin IVA) va para calcular el precio de Ned con su utilidad; solo lo ve el admin.
                NedPartida::create([
                    'ned_cotizacion_id' => $ned->id, 'orden' => ++$orden, 'descripcion' => $p->descripcion,
                    'cantidad' => $p->cantidad, 'unidad' => $p->unidad ?: 'PZA', 'costo' => $p->costo, 'precio' => 0, 'importe' => 0,
                ]);
            }
            $ned->recalcularTotales();

            return $ned;
        });

        return $this->redirectRoute('ned.editar', $ned);
    }

    // ---------- Internos ----------

    private function cot(): ?Cotizacion
    {
        return $this->cotizacionId ? Cotizacion::with('cliente')->find($this->cotizacionId) : null;
    }

    private function cargarEncabezado(Cotizacion $c): void
    {
        $this->enc = [
            'contacto_id' => $c->contacto_id ? (string) $c->contacto_id : '',
            'fecha' => $c->fecha->format('Y-m-d'),
            'vigencia_dias' => (string) $c->vigencia_dias,
            'forma_pago' => (string) $c->forma_pago,
            'condiciones_entrega' => (string) $c->condiciones_entrega,
            'notas' => (string) $c->notas,
            'estado' => $c->estado->value,
            'vendedor_id' => $c->vendedor_id ? (string) $c->vendedor_id : '',
        ];
    }

    private function cargarPartidas(): void
    {
        $this->partidas = $this->cot()->partidas()->with('proveedor:id,nombre')->get()
            ->map(function (CotizacionPartida $p) {
                $costo = $p->costo !== null ? (float) $p->costo : null;

                return [
                    'id' => $p->id,
                    'incluir' => (bool) $p->incluir,
                    'cantidad' => self::fmtCantidad((float) $p->cantidad),
                    'descripcion' => $p->descripcion,
                    'unidad' => $p->unidad,
                    'proveedor' => $p->proveedor?->nombre ?? '',
                    'proveedor_id' => $p->proveedor_id,
                    'proveedor_vendedor' => (string) $p->proveedor_vendedor,
                    'proveedor_telefono' => (string) $p->proveedor_telefono,
                    'link_compra' => (string) $p->link_compra,
                    'marca' => (string) $p->marca,
                    'modelo' => (string) $p->modelo,
                    'costo_sin' => $costo ? self::fmtCosto($costo) : '',
                    'costo_con' => $costo ? number_format($costo * (1 + Precios::iva()), 2, '.', '') : '',
                    'tiempo_entrega' => (string) $p->tiempo_entrega,
                    'entrega_lenta' => (bool) $p->entrega_lenta,
                    'utilidad_pct' => $p->utilidad_pct !== null ? self::fmtUtilidad((float) $p->utilidad_pct) : '',
                    'precio' => number_format((float) $p->precio, 2, '.', ''),
                    'imagen_path' => $p->imagen_path,
                    'articulo_id' => $p->articulo_id,
                ];
            })->all();
    }

    private function partidaModelo(int $i): ?CotizacionPartida
    {
        $id = $this->partidas[$i]['id'] ?? null;

        return $id && $this->cotizacionId
            ? CotizacionPartida::where('cotizacion_id', $this->cotizacionId)->find($id)
            : null;
    }

    /** @param  list<string>|null  $soloColumnas  null = todas (acciones de la partida completa) */
    private function persistirPartida(int $i, ?array $soloColumnas = null): void
    {
        DB::transaction(function () use ($i, $soloColumnas) {
            Cotizacion::whereKey($this->cotizacionId)->lockForUpdate()->firstOrFail();
            $partida = $this->partidaModelo($i);
            if (! $partida) {
                return;
            }
            $p = $this->partidas[$i];

            $cantidad = max(0, self::num($p['cantidad']));
            $precio = max(0, self::num($p['precio']));
            $costo = self::num($p['costo_sin']);
            // Lo que no se editó se toma de la base (puede haberlo cambiado otra persona en otra pantalla).
            if ($soloColumnas !== null) {
                if (! in_array('cantidad', $soloColumnas, true)) {
                    $cantidad = (float) $partida->cantidad;
                }
                if (! in_array('costo', $soloColumnas, true)) {
                    $costo = (float) $partida->costo;
                }
                if (in_array('costo', $soloColumnas, true)) {
                    $p['utilidad_pct'] = $partida->utilidad_pct !== null ? self::fmtUtilidad((float) $partida->utilidad_pct) : '';
                    $p['precio'] = (string) $partida->precio;
                    $precio = (float) $partida->precio;
                    if ($costo > 0 && $p['utilidad_pct'] !== '') {
                        $precio = Precios::precioDesdeUtilidad($costo, self::num($p['utilidad_pct']));
                    } elseif ($costo > 0) {
                        $p['utilidad_pct'] = self::fmtUtilidad(Precios::utilidadDesdePrecio($costo, $precio));
                    }
                }
                if (in_array('utilidad_pct', $soloColumnas, true) && ! in_array('costo', $soloColumnas, true) && $costo > 0) {
                    if ($this->ultimoCampo === 'precio') {
                        $this->partidas[$i]['utilidad_pct'] = $p['utilidad_pct'] = self::fmtUtilidad(Precios::utilidadDesdePrecio($costo, $precio));
                    } elseif (trim((string) $p['utilidad_pct']) !== '') {
                        $precio = Precios::precioDesdeUtilidad($costo, self::num($p['utilidad_pct']));
                        $this->partidas[$i]['precio'] = $p['precio'] = number_format($precio, 2, '.', '');
                    }
                }
                if (! in_array('precio', $soloColumnas, true)) {
                    $precio = (float) $partida->precio;
                }
            }
            if ($error = Limites::partida($cantidad, $precio, $costo > 0 ? $costo : null)) {
                $this->mensaje = $error.' No se guardó.';
                $this->cargarPartidas();

                return;
            }

            // Si pegaron el link de compra en "Modelo", se pasa a su campo (en la OC salía la dirección larga).
            [$modelo, $link] = LinkCompra::separar($this->nulo($p['modelo']), LinkCompra::normalizar($p['link_compra'] ?? ''));
            $this->partidas[$i]['modelo'] = (string) $modelo;
            $this->partidas[$i]['link_compra'] = (string) $link;

            $datos = [
                'incluir' => (bool) $p['incluir'],
                'descripcion' => mb_substr(trim((string) $p['descripcion']), 0, 2000),
                'marca' => $this->nulo($p['marca']),
                'modelo' => $modelo,
                'unidad' => mb_substr(trim((string) $p['unidad']) ?: 'PZA', 0, 10),
                'cantidad' => $cantidad,
                'proveedor_id' => $p['proveedor_id'] ?: null,
                'proveedor_vendedor' => $this->nulo($p['proveedor_vendedor']),
                'proveedor_telefono' => mb_substr(trim((string) ($p['proveedor_telefono'] ?? '')), 0, 30) ?: null,
                'link_compra' => $link,
                'costo' => $costo > 0 ? $costo : null,
                'utilidad_pct' => trim((string) $p['utilidad_pct']) === '' ? null : self::num($p['utilidad_pct']),
                'precio' => $precio,
                'importe' => Precios::importe($precio, $cantidad),
                'tiempo_entrega' => $this->nulo($p['tiempo_entrega']),
                'entrega_lenta' => (bool) $p['entrega_lenta'],
                'imagen_path' => $p['imagen_path'] ?: null,
                'articulo_id' => $p['articulo_id'] ?? null,
            ];
            if ($soloColumnas !== null) {
                $datos = array_intersect_key($datos, array_flip($soloColumnas)) + ['importe' => $datos['importe']];
            }
            $partida->update($datos);

            $this->cot()->recalcularTotales();
        });
    }

    private function nulo(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    private function reordenar(): void
    {
        $n = 1;
        foreach ($this->cot()->partidas()->get() as $p) {
            if ($p->orden !== $n) {
                $p->update(['orden' => $n]);
            }
            $n++;
        }
    }

    /**
     * El vendedor del proveedor y su teléfono (desde donde mandó la cotización) quedan en la ficha del
     * proveedor como contacto, para futuras cotizaciones:
     * - Se escribe el teléfono: se guarda en el contacto de ese vendedor (o se actualiza si cambió de
     *   número). Sin nombre de vendedor también se guarda.
     * - Se elige o cambia el vendedor: si ya tiene teléfono guardado, se llena solo en la partida.
     */
    private function contactoProveedor(array $p, string $campo): array
    {
        $proveedorId = $p['proveedor_id'] ?? null;
        if (! $proveedorId) {
            return $p;
        }
        $nombre = trim((string) ($p['proveedor_vendedor'] ?? ''));
        $tel = mb_substr(trim((string) ($p['proveedor_telefono'] ?? '')), 0, 30);
        $contactos = ProveedorContacto::where('proveedor_id', $proveedorId)->get();
        $porNombre = $nombre !== '' ? $contactos->first(fn ($c) => Nombres::llave($c->nombre) === Nombres::llave($nombre)) : null;
        $digitos = fn ($t) => preg_replace('/\D+/', '', (string) $t);
        $nuevo = fn (string $n, ?string $t) => ProveedorContacto::create([
            'proveedor_id' => $proveedorId, 'nombre' => mb_substr($n, 0, 255), 'telefono' => $t ?: null,
            'puesto' => 'Vendedor', 'principal' => $contactos->isEmpty(),
        ]);

        if ($campo === 'proveedor_telefono') {
            if ($tel === '') {
                return $p;
            }
            if ($porNombre) {
                if ($digitos($porNombre->telefono) !== $digitos($tel)) {
                    $porNombre->update(['telefono' => $tel]);
                }
            } elseif ($nombre !== '') {
                $nuevo($nombre, $tel);
            } elseif (! $contactos->contains(fn ($c) => $digitos($c->telefono) === $digitos($tel))) {
                $nuevo('Vendedor '.$tel, $tel);
            }

            return $p;
        }

        // Cambió el vendedor (o el proveedor).
        if ($nombre === '') {
            return $p;
        }
        if ($porNombre) {
            if ($porNombre->telefono) {
                $p['proveedor_telefono'] = $porNombre->telefono;
            } elseif ($tel !== '' && ($campo !== 'proveedor_vendedor' || $this->anterior === '')) {
                $porNombre->update(['telefono' => $tel]);
            }

            return $p;
        }
        // Vendedor nuevo: el teléfono de la partida es suyo solo si antes no había otro vendedor.
        $esSuyo = $campo === 'proveedor_vendedor' ? $this->anterior === '' : true;
        if (! $esSuyo) {
            $p['proveedor_telefono'] = '';
        }
        $nuevo($nombre, $esSuyo ? $tel : null);

        return $p;
    }

    private function resolverProveedor(string $nombre): ?int
    {
        $nombre = Nombres::limpiar($nombre);
        $llave = Nombres::llave($nombre);
        if ($llave === '') {
            return null;
        }

        $proveedor = Proveedor::porNombre($nombre) ?? $this->proveedorParecido($nombre) ?? $this->proveedorSucursal($nombre);
        if ($proveedor) {
            return $proveedor->id;
        }
        if ($borrado = Proveedor::borradoConNombre($nombre)) {
            $this->mensaje = "El proveedor {$borrado->nombre} fue borrado; no se puede usar. Elige otro de la lista.";

            return null;
        }

        return Proveedor::create(['nombre' => $nombre])->id;
    }

    /**
     * "STEREN COLON" cuando ya existe STEREN: es una sucursal, no otro proveedor (hay muchas sucursales y no se da
     * de alta una por cada una). Se usa el que existe (el de nombre más largo que coincida palabra por palabra al
     * inicio) y la sucursal se anota en los comentarios de la OC al proveedor.
     */
    private function proveedorSucursal(string $nombre): ?Proveedor
    {
        $palabras = preg_split('/\s+/u', $nombre) ?: [];
        for ($n = count($palabras) - 1; $n >= 1; $n--) {
            $base = implode(' ', array_slice($palabras, 0, $n));
            if (mb_strlen(Nombres::llave($base)) < 4) {
                break;
            }
            if ($p = Proveedor::porNombre($base)) {
                $sucursal = implode(' ', array_slice($palabras, $n));
                $this->mensaje = "Ya existe el proveedor {$p->nombre}; se usó ese. La sucursal ({$sucursal}) anótala en los comentarios de la OC al proveedor.";

                return $p;
            }
        }

        return null;
    }

    /**
     * Si el nombre escrito es un error de dedo de un proveedor que ya existe ("MAXIFERO" → MAXIFERRO),
     * se usa ese en vez de crear otro: con dos registros las OC a proveedor salían separadas.
     */
    private function proveedorParecido(string $nombre): ?Proveedor
    {
        $base = UnificarDuplicados::base($nombre);
        $encontrado = Proveedor::query()->get(['id', 'nombre'])
            ->first(fn (Proveedor $p) => UnificarDuplicados::errorDeDedo($base, UnificarDuplicados::base($p->nombre)));
        if ($encontrado) {
            $this->mensaje = "Se usó el proveedor {$encontrado->nombre}, que ya existía (escribiste \"{$nombre}\").";
        }

        return $encontrado;
    }

    /** Borra el archivo si ya nadie lo usa (otras partidas, pedidos o el catálogo de imágenes). */
    private function borrarArchivoSiSobra(?string $ruta, int $exceptoId): void
    {
        CatalogoImagenes::borrarSiSobra($ruta, $exceptoId);
    }

    /** Redimensiona a máx. 800 px y guarda en public/partidas. */
    private function guardarImagen(TemporaryUploadedFile $archivo): ?string
    {
        return ImagenPartida::guardar($archivo->getRealPath());
    }

    private static function num(mixed $v): float
    {
        $v = str_replace([',', '$', ' '], '', (string) $v);

        return is_numeric($v) ? (float) $v : 0.0;
    }

    private static function fmtCosto(float $n): string
    {
        $s = number_format($n, 4, '.', '');

        return str_ends_with($s, '00') ? number_format($n, 2, '.', '') : rtrim($s, '0');
    }

    private static function fmtUtilidad(?float $n): string
    {
        return $n === null ? '' : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private static function fmtCantidad(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.') ?: '0';
    }

    /** Totales en vivo: solo partidas incluidas. */
    private function totales(): array
    {
        $venta = $costo = 0.0;
        foreach ($this->partidas as $p) {
            if (empty($p['incluir'])) {
                continue;
            }
            $cant = self::num($p['cantidad']);
            $venta += Precios::importe(self::num($p['precio']), $cant);
            $costo += self::num($p['costo_sin']) * $cant;
        }
        $venta = round($venta, 2);
        $costo = round($costo, 2);
        $utilidad = round($venta - $costo, 2);
        $t = Precios::totales([$venta]);

        return [
            'venta' => $venta, 'costo' => $costo, 'utilidad' => $utilidad,
            'utilidad_pct' => $costo > 0 ? round($utilidad / $costo * 100, 2) : null,
            'iva' => $t['iva'], 'total' => $t['total'],
            'incluidas' => count(array_filter($this->partidas, fn ($p) => ! empty($p['incluir']))),
        ];
    }

    public function render()
    {
        $cot = $this->cot();
        $texto = trim($this->buscarCliente);
        $mostrarBuscador = ! $cot || $this->cambiandoCliente;

        return view('livewire.cotizaciones.editor', [
            'cot' => $cot,
            'resultados' => $mostrarBuscador && $texto !== ''
                ? Cliente::where('nombre', 'like', '%'.mb_strtoupper($texto).'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'contactos' => $cot ? $cot->cliente->contactos()->orderBy('nombre')->get(['id', 'nombre', 'puesto']) : collect(),
            'vendedores' => User::where('activo', true)->whereIn('rol', ['admin', 'vendedor'])->orderBy('name')->get(['id', 'name']),
            'mostrarBuscador' => $mostrarBuscador,
            'proveedores' => $cot ? Proveedor::orderBy('nombre')->pluck('nombre') : collect(),
            // Vendedores ya guardados de los proveedores de esta cotización (para elegirlos en la partida).
            'vendedoresPorProveedor' => ProveedorContacto::whereIn('proveedor_id', collect($this->partidas)->pluck('proveedor_id')->filter()->unique())
                ->orderBy('nombre')->get(['proveedor_id', 'nombre', 'telefono'])->groupBy('proveedor_id'),
            'estados' => EstadoCotizacion::cases(),
            'totales' => $this->totales(),
            'iva' => Precios::iva(),
            'cambios' => $cot ? $cot->cambios()->with('user:id,name')->limit($this->limiteHistorial)->get() : collect(),
            'totalCambios' => $cot ? $cot->cambios()->count() : 0,
            // Pedir cotización a otro proveedor: solo descripción, cantidad, unidad y marca. Nunca precios ni el
            // modelo (puede ser el SKU de nuestro proveedor).
            'catalogo' => $this->catalogoPara !== null
                ? CatalogoImagenes::buscar($this->buscarCatalogo)->latest('updated_at')->limit(24)->get()
                : collect(),
            'listaSinPrecios' => $cot ? ListaPartidas::texto($cot->partidasIncluidas()->orderBy('orden')->get(['descripcion', 'cantidad', 'unidad', 'marca'])->toArray()) : '',
        ]);
    }
}
