<?php

namespace App\Livewire\Solicitudes;

use App\Models\Cliente;
use App\Models\SolicitudArchivo;
use App\Models\SolicitudCotizacion;
use App\Support\FotoEvidencia;
use App\Support\LecturaSolicitud;
use App\Support\ProductosCotizados;
use App\Support\Solicitudes;
use App\Support\TablaArchivo;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Alta y edición de una solicitud de cotización (formulario único; con IA que pre-llena las partidas). */
#[Layout('layouts.app')]
#[Title('Solicitud por cotizar')]
class Nueva extends Component
{
    use WithFileUploads;

    public const MAX_KB = 20480;

    public ?int $solicitudId = null;

    public ?int $clienteId = null;

    public string $buscarCliente = '';

    public string $clienteTexto = '';

    public string $contactoTexto = '';

    public string $canal = 'whatsapp';

    /** Formato del input datetime-local (hora de México). */
    public string $recibida = '';

    public string $detalle = '';

    /** @var list<array{cantidad: mixed, unidad: string, descripcion: string, modelo: string}> */
    public array $partidas = [];

    /** @var array<int, mixed> */
    public array $archivos = [];

    /** Archivos subidos antes de guardar: [ruta, nombre, mime, bytes]. */
    public array $pendientes = [];

    /** Productos ya cotizados que se parecen a cada renglón (por posición); no se guardan en la BD. */
    public array $sugerencias = [];

    /** Texto libre de "Buscar otro" por renglón. */
    public array $consultas = [];

    public ?string $iaEstado = null;

    public ?string $iaMensaje = null;

    /** Renglones guardados ya vistos por esta pantalla; el cron puede agregar más mientras está abierta. */
    public int $iaPartidasVistas = 0;

    public string $mensaje = '';

    public string $error = '';

    public function mount(?SolicitudCotizacion $solicitud = null): void
    {
        abort_unless(Solicitudes::puedeUsar(auth()->user()), 403);
        if (! $solicitud || ! $solicitud->exists) {
            $this->recibida = now()->format('Y-m-d\TH:i');
            $this->partidas = [self::renglonVacio()];

            return;
        }
        if (! $solicitud->estaAbierta()) {
            session()->flash('error', 'Esta solicitud ya está cerrada y no se edita.');
            $this->redirectRoute('solicitudes.ver', $solicitud);

            return;
        }
        $this->cargar($solicitud);
        $this->sugerir();
    }

    private static function renglonVacio(): array
    {
        return ['cantidad' => '', 'unidad' => '', 'descripcion' => '', 'modelo' => '', 'ligada' => null];
    }

    private function cargar(SolicitudCotizacion $s): void
    {
        $this->solicitudId = $s->id;
        $this->clienteId = $s->cliente_id;
        $this->clienteTexto = (string) $s->cliente_texto;
        $this->contactoTexto = (string) $s->contacto_texto;
        $this->canal = $s->canal;
        $this->recibida = $s->recibida_at->format('Y-m-d\TH:i');
        $this->detalle = (string) $s->detalle;
        $this->partidas = collect($s->partidas)->map(fn ($p) => [
            'cantidad' => $p['cantidad'] ?? '', 'unidad' => (string) ($p['unidad'] ?? ''),
            'descripcion' => (string) ($p['descripcion'] ?? ''), 'modelo' => (string) ($p['modelo'] ?? ''), 'ligada' => $p['ligada'] ?? null,
        ])->values()->all() ?: [self::renglonVacio()];
        $this->iaEstado = $s->ia_estado;
        $this->iaPartidasVistas = count((array) $s->partidas);
        $this->iaMensaje = $s->ia_mensaje;
    }

    private function solicitud(): ?SolicitudCotizacion
    {
        return $this->solicitudId ? SolicitudCotizacion::find($this->solicitudId) : null;
    }

    // ---------------------------------------------------------------- cliente

    public function seleccionarCliente(int $id): void
    {
        $this->clienteId = Cliente::findOrFail($id)->id;
        $this->clienteTexto = '';
        $this->buscarCliente = '';
    }

    public function quitarCliente(): void
    {
        $this->clienteId = null;
    }

    /** "Cliente nuevo": lo da de alta con el nombre escrito (si ya existe con ese nombre, usa el existente). */
    public function darDeAltaCliente(): void
    {
        $this->resetErrorBag('clienteTexto');
        try {
            $cliente = Solicitudes::asegurarCliente($this->clienteTexto);
        } catch (DomainException $e) {
            $this->addError('clienteTexto', $e->getMessage());

            return;
        }
        $this->seleccionarCliente($cliente->id);
        $this->mensaje = 'Cliente listo: '.$cliente->nombre.'.';
    }

    // ---------------------------------------------------------------- partidas

    public function agregarRenglon(): void
    {
        $this->partidas[] = self::renglonVacio();
    }

    public function quitarRenglon(int $i): void
    {
        // Las sugerencias y consultas van por posición: se recorren junto con los renglones.
        $recorrer = function (array $por) use ($i) {
            $nuevo = [];
            foreach ($por as $k => $v) {
                if ($k !== $i) {
                    $nuevo[$k > $i ? $k - 1 : $k] = $v;
                }
            }

            return $nuevo;
        };
        $this->sugerencias = $recorrer($this->sugerencias);
        $this->consultas = $recorrer($this->consultas);
        unset($this->partidas[$i]);
        $this->partidas = array_values($this->partidas) ?: [self::renglonVacio()];
    }

    // ---------------------------------------------------------------- productos ya cotizados

    /** Busca, para los renglones sin liga, productos que ya cotizamos (por modelo y luego por descripción). */
    public function sugerir(): void
    {
        foreach (array_slice($this->partidas, 0, 40, true) as $i => $p) {
            if (! empty($p['ligada']) || trim((string) ($p['descripcion'] ?? '')) === '') {
                unset($this->sugerencias[$i]);

                continue;
            }
            $r = ProductosCotizados::buscar($p['modelo'] ?? null, (string) $p['descripcion'], 5, $this->clienteId);
            if ($r) {
                $this->sugerencias[$i] = $r;
            } else {
                unset($this->sugerencias[$i]);
            }
        }
    }

    /** "Buscar otro": el vendedor escribe qué buscar y se usa la misma búsqueda. */
    public function buscarOtro(int $i): void
    {
        $texto = trim((string) ($this->consultas[$i] ?? ''));
        if (! isset($this->partidas[$i]) || $texto === '') {
            return;
        }
        $r = ProductosCotizados::buscar($texto, $texto, 5, $this->clienteId);
        $this->sugerencias[$i] = $r;
        if (! $r) {
            $this->mensaje = 'No encontré productos cotizados con «'.$texto.'». Se queda como producto nuevo.';
        }
    }

    /** "Sí, es este": copia descripción, modelo y unidad oficiales y guarda la liga; la cantidad del cliente se conserva. */
    public function elegirSugerencia(int $i, int $j): void
    {
        $item = $this->sugerencias[$i][$j] ?? null;
        if (! $item || ! isset($this->partidas[$i])) {
            return;
        }
        $this->partidas[$i]['descripcion'] = $item['descripcion'];
        $this->partidas[$i]['modelo'] = $item['modelo'];
        if ($item['unidad'] !== '') {
            $this->partidas[$i]['unidad'] = $item['unidad'];
        }
        unset($item['imagen']);
        $this->partidas[$i]['ligada'] = $item;
        unset($this->sugerencias[$i]);
    }

    /** "Es producto nuevo": se esconden las sugerencias de ese renglón. */
    public function quitarSugerencias(int $i): void
    {
        unset($this->sugerencias[$i]);
    }

    /** Quita la liga: el renglón queda como producto nuevo (conserva el texto). */
    public function quitarLiga(int $i): void
    {
        if (isset($this->partidas[$i])) {
            $this->partidas[$i]['ligada'] = null;
        }
    }

    // ---------------------------------------------------------------- archivos

    public function updatedArchivos(): void
    {
        $this->error = '';
        try {
            $this->validate(['archivos.*' => ['file', 'extensions:pdf,jpg,jpeg,png,webp,xlsx,csv', 'max:'.self::MAX_KB]], [
                'archivos.*.extensions' => 'Solo se aceptan PDF, fotos (JPG, PNG, WEBP) o Excel (.xlsx o .csv). Si es Excel viejo (.xls), guárdalo como .xlsx.',
                'archivos.*.max' => 'Cada archivo puede pesar máximo 20 MB.',
                'archivos.*.file' => 'No se pudo subir el archivo.',
            ]);
        } catch (ValidationException $e) {
            $this->archivos = [];
            throw $e;
        }

        $carpeta = 'solicitudes/'.now()->format('Y-m');
        foreach ($this->archivos as $archivo) {
            $nombre = mb_substr($archivo->getClientOriginalName(), 0, 250);
            $mime = (string) $archivo->getMimeType();
            $ext = strtolower($archivo->getClientOriginalExtension());
            // Lo que trae ADENTRO debe coincidir con la extensión (auditoría oct-2026: una página web llamada .pdf se abría
            // como página y ejecutaba código en la sesión de quien la veía).
            $real = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($archivo->getRealPath());
            if (! self::contenidoValido($ext, $mime, $real)) {
                $this->error = "«{$nombre}» no es un ".($ext === 'csv' || $ext === 'xlsx' ? 'Excel' : ($ext === 'pdf' ? 'PDF' : 'archivo'))
                    .' de verdad (o está dañado). Vuelve a guardarlo y súbelo otra vez.';

                continue;
            }
            if (isset(TablaArchivo::MIMES[$ext])) {
                // Excel/CSV: se guarda tal cual; al leerlo con IA se convierte a texto.
                $ruta = $archivo->storeAs($carpeta, Str::uuid().'.'.$ext, 'local');
                $mime = TablaArchivo::MIMES[$ext];
                $tam = (int) $archivo->getSize();
            } elseif (str_starts_with($mime, 'image/')) {
                // Fotos del celular: se reducen a 1600 px (la IA y la pantalla las leen igual y pesan menos).
                $bytes = FotoEvidencia::procesar($archivo->getRealPath());
                if ($bytes === null) {
                    $this->error = "No se pudo abrir la imagen «{$nombre}».";

                    continue;
                }
                $ruta = $carpeta.'/'.Str::uuid().'.jpg';
                Storage::disk('local')->put($ruta, $bytes);
                $mime = 'image/jpeg';
                $tam = strlen($bytes);
            } else {
                $ruta = $archivo->storeAs($carpeta, Str::uuid().'.pdf', 'local');
                $mime = 'application/pdf';
                $tam = (int) $archivo->getSize();
            }
            $dato = ['ruta' => $ruta, 'nombre' => $nombre, 'mime' => $mime, 'bytes' => $tam];
            if ($s = $this->solicitud()) {
                $s->archivos()->create($dato);
            } else {
                $this->pendientes[] = $dato;
            }
        }
        $this->archivos = [];
    }

    /** ¿El contenido real (leído del archivo) corresponde a lo que dice su extensión? */
    private static function contenidoValido(string $ext, string $mime, string $real): bool
    {
        return match (true) {
            $ext === 'xlsx' => in_array($real, [TablaArchivo::MIMES['xlsx'], 'application/zip'], true),
            $ext === 'csv' => in_array($real, ['text/plain', 'text/csv', 'application/csv'], true),
            str_starts_with($mime, 'image/') => str_starts_with($real, 'image/') && $real !== 'image/svg+xml',
            default => $real === 'application/pdf',
        };
    }

    public function quitarPendiente(int $i): void
    {
        if (isset($this->pendientes[$i])) {
            Storage::disk('local')->delete($this->pendientes[$i]['ruta']);
            unset($this->pendientes[$i]);
            $this->pendientes = array_values($this->pendientes);
        }
    }

    public function quitarArchivo(int $id): void
    {
        $a = SolicitudArchivo::where('solicitud_id', $this->solicitudId)->find($id);
        if ($a) {
            Storage::disk('local')->delete($a->ruta);
            $a->delete();
        }
    }

    // ---------------------------------------------------------------- guardar

    /** @param bool $exigirCliente La lectura con IA puede arrancar sin cliente (ella lo intenta encontrar). */
    private function guardarDatos(bool $exigirCliente = true): ?SolicitudCotizacion
    {
        $this->error = '';
        $this->validate([
            'canal' => ['required', 'in:'.implode(',', array_keys(SolicitudCotizacion::CANALES))],
            'recibida' => ['required', 'date'],
            'clienteTexto' => ['nullable', 'string', 'max:250'],
            'contactoTexto' => ['nullable', 'string', 'max:250'],
            'detalle' => ['nullable', 'string', 'max:5000'],
        ], ['recibida.required' => 'Pon la fecha y hora en que llegó.', 'recibida.date' => 'La fecha y hora de llegada no es válida.']);

        $partidas = Solicitudes::partidasValidas($this->partidas);
        $tieneArchivos = $this->pendientes !== [] || ($this->solicitudId && SolicitudArchivo::where('solicitud_id', $this->solicitudId)->exists());
        if ($exigirCliente && ! $this->clienteId && trim($this->clienteTexto) === '') {
            $this->addError('clienteTexto', 'Escoge el cliente o escribe su nombre.');

            return null;
        }
        if ($partidas === [] && trim($this->detalle) === '' && ! $tieneArchivos) {
            $this->error = 'Anota qué pidió el cliente: el detalle, las partidas o sube un archivo.';

            return null;
        }
        $datos = [
            'cliente_id' => $this->clienteId,
            'cliente_texto' => $this->clienteId ? null : mb_substr(trim($this->clienteTexto), 0, 250),
            'contacto_texto' => trim($this->contactoTexto) ?: null,
            'canal' => $this->canal,
            'recibida_at' => Carbon::parse($this->recibida, config('app.timezone')),
            'detalle' => trim($this->detalle) ?: null,
            'partidas' => $partidas,
        ];

        $s = $this->solicitud();
        if ($s) {
            $s = DB::transaction(function () use ($s, $datos) {
                $actual = SolicitudCotizacion::lockForUpdate()->findOrFail($s->id);
                // El cron pudo agregar renglones antes del siguiente polling de esta pantalla.
                $nuevas = array_slice((array) $actual->partidas, $this->iaPartidasVistas);
                $datos['partidas'] = array_merge($datos['partidas'], $nuevas);
                $actual->update($datos);
                foreach ($nuevas as $p) {
                    $this->partidas[] = $p;
                }
                $this->iaPartidasVistas = count($datos['partidas']);

                return $actual;
            });
        } else {
            $s = Solicitudes::crear($datos, auth()->user());
            $this->solicitudId = $s->id;
        }
        foreach ($this->pendientes as $a) {
            $s->archivos()->create($a);
        }
        $this->pendientes = [];

        return $s;
    }

    public function guardar()
    {
        try {
            $s = $this->guardarDatos();
        } catch (ValidationException $e) {
            $this->dispatch('mostrar-error');
            throw $e;
        }
        if (! $s) {
            $this->dispatch('mostrar-error');   // el aviso puede quedar arriba, fuera de la vista

            return null;
        }

        return $this->redirectRoute('solicitudes.ver', $s);
    }

    // ---------------------------------------------------------------- IA

    public function leerConIa(): void
    {
        $s = $this->guardarDatos(false);
        if (! $s) {
            return;
        }
        if ($s->ia_estado === 'leyendo') {
            return;
        }
        LecturaSolicitud::iniciar($s);
        $this->iaPartidasVistas = count((array) $s->fresh()->partidas);
        $this->iaEstado = $s->fresh()->ia_estado;
        $this->iaMensaje = $s->fresh()->ia_mensaje;
    }

    /**
     * "Acomodar este texto con IA": lo pegado en "Qué pidió" se guarda como un .txt de la solicitud y la IA lo
     * convierte en partidas (igual que un archivo; después se buscan los productos ya cotizados).
     */
    public function acomodarTexto(): void
    {
        $this->error = $this->mensaje = '';
        $texto = trim($this->detalle);
        if (mb_strlen($texto) < 3) {
            $this->error = 'Primero pega en "Qué pidió" el texto que mandó el cliente.';

            return;
        }
        $s = $this->guardarDatos(false);
        if (! $s) {
            return;
        }
        if ($s->ia_estado === 'leyendo') {
            $this->mensaje = 'La IA todavía está leyendo; espera a que termine.';

            return;
        }
        $disco = Storage::disk('local');
        $yaEsta = $s->archivos()->where('mime', LecturaSolicitud::MIME_TEXTO)->get()
            ->contains(fn ($a) => $disco->exists($a->ruta) && trim((string) $disco->get($a->ruta)) === $texto);
        if ($yaEsta) {
            $this->error = 'Ese texto ya se acomodó en partidas. Si cambiaste algo, edita las partidas abajo.';

            return;
        }
        $ruta = 'solicitudes/'.now()->format('Y-m').'/'.Str::uuid().'.txt';
        $disco->put($ruta, $texto);
        $s->archivos()->create(['ruta' => $ruta, 'nombre' => 'Texto pegado.txt', 'mime' => LecturaSolicitud::MIME_TEXTO, 'bytes' => strlen($texto)]);
        LecturaSolicitud::iniciar($s);
        $s->refresh();
        $this->iaPartidasVistas = count((array) $s->partidas);
        $this->iaEstado = $s->ia_estado;
        $this->iaMensaje = $s->ia_mensaje;
    }

    /** Sondeo (wire:poll) mientras la IA lee; lo que lee se agrega al formulario sin borrar lo escrito. */
    public function consultarIa(): void
    {
        $s = $this->solicitud();
        if (! $s) {
            return;
        }
        $agregado = LecturaSolicitud::consultar($s);
        $s->refresh();
        $nuevas = array_slice((array) $s->partidas, $this->iaPartidasVistas);
        if ($nuevas !== []) {
            // Si el cron ganó la consulta, se incorporan sus renglones sin borrar lo escrito en la pantalla.
            $agregado ??= ['cliente_id' => $s->cliente_id, 'cliente_texto' => $s->cliente_texto,
                'contacto_texto' => $s->contacto_texto, 'notas' => null];
            $agregado['partidas'] = $nuevas;
        }
        $this->iaPartidasVistas = count((array) $s->partidas);
        if ($agregado) {
            if (count(Solicitudes::partidasValidas($this->partidas)) === 0) {
                $this->partidas = [];
            }
            foreach ($agregado['partidas'] as $p) {
                $this->partidas[] = ['cantidad' => $p['cantidad'] ?? '', 'unidad' => $p['unidad'], 'descripcion' => $p['descripcion'], 'modelo' => $p['modelo'], 'ligada' => null];
            }
            if ($this->partidas === []) {
                $this->partidas = [self::renglonVacio()];
            }
            if ($agregado['cliente_id']) {
                $this->clienteId = $agregado['cliente_id'];
                $this->clienteTexto = '';
            } elseif ($agregado['cliente_texto'] && ! $this->clienteId && trim($this->clienteTexto) === '') {
                $this->clienteTexto = $agregado['cliente_texto'];
            }
            if ($agregado['contacto_texto'] && trim($this->contactoTexto) === '') {
                $this->contactoTexto = $agregado['contacto_texto'];
            }
            if ($agregado['notas']) {
                $this->detalle = trim($this->detalle."\n".$agregado['notas']);
            }
            $this->sugerir();
        }
        $s->refresh();
        $this->iaEstado = $s->ia_estado;
        $this->iaMensaje = $s->ia_mensaje;
    }

    public function render()
    {
        $texto = trim($this->buscarCliente);

        return view('livewire.solicitudes.nueva', [
            'cliente' => $this->clienteId ? Cliente::withTrashed()->find($this->clienteId) : null,
            'resultados' => $texto !== '' && ! $this->clienteId
                ? Cliente::where('nombre', 'like', '%'.mb_strtoupper(addcslashes($texto, '%_\\')).'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'guardados' => $this->solicitudId ? SolicitudArchivo::where('solicitud_id', $this->solicitudId)->orderBy('id')->get() : collect(),
            'tieneArchivos' => $this->pendientes !== [] || ($this->solicitudId && SolicitudArchivo::where('solicitud_id', $this->solicitudId)->exists()),
            'folio' => $this->solicitud()?->folioTexto(),
        ]);
    }
}
