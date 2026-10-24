<?php

namespace App\Livewire;

use App\Mail\OrdenProveedorMail;
use App\Models\Cotizacion;
use App\Models\CotizacionEnvio;
use App\Models\OrdenProveedor;
use App\Support\CompartirDocumento;
use App\Support\CuentaCorreo;
use App\Support\Telefono;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Compartir un documento (cotización u OC a proveedor) por WhatsApp, correo o descarga, con registro.
 * Uso: <livewire:compartir tipo="orden_proveedor" :documento-id="$orden->id" />
 */
class Compartir extends Component
{
    #[Locked]
    public string $tipo;

    #[Locked]
    public int $documentoId;

    public string $aviso = '';

    // Correo de la OC a proveedor (el de cotizaciones es Cotizaciones\EnviarCorreo)
    public bool $correoAbierto = false;

    public string $para = '';

    public string $cc = '';

    public string $asunto = '';

    public string $mensaje = '';

    public string $errorEnvio = '';

    public function mount(string $tipo, int $documentoId): void
    {
        abort_unless(isset(CompartirDocumento::TIPOS[$tipo]), 404);
        $this->tipo = $tipo;
        $this->documentoId = $documentoId;
        $this->autorizar();
    }

    /** El rol campo comparte OC a proveedores, pero nunca cotizaciones (tienen precio de venta). */
    private function autorizar(): void
    {
        abort_if($this->tipo === 'cotizacion' && auth()->user()->esCampo(), 403);
    }

    private function documento(): Cotizacion|OrdenProveedor
    {
        return CompartirDocumento::buscar($this->tipo, $this->documentoId);
    }

    private function avisarCambio(Cotizacion|OrdenProveedor $doc): void
    {
        $this->dispatch('envio-registrado', estado: $doc->estado->value);
    }

    /** Número del contacto del cliente o del proveedor, listo para wa.me. */
    private function telefonoSugerido(Cotizacion|OrdenProveedor $doc): string
    {
        $tel = $doc instanceof Cotizacion
            ? ($doc->contacto?->telefono ?: $doc->cliente?->telefono)
            : $doc->proveedor?->datosContacto()['telefono'];

        return (string) Telefono::whatsapp($tel);
    }

    /**
     * Mensaje que acompaña al PDF. Sin enlace: mucha gente no tiene permitido abrir links, así que se manda el
     * archivo. El enlace solo se agrega si eligen "Mandar enlace en lugar del PDF".
     */
    private function mensajeWhatsapp(Cotizacion|OrdenProveedor $doc): string
    {
        if ($doc instanceof Cotizacion) {
            $saludo = $doc->contacto?->nombre ? 'Hola '.$doc->contacto->nombre : 'Buen día';

            return "{$saludo}, te comparto en PDF la cotización {$doc->folioConRevision(' ')} de MASUME Industrial.\n\nQuedo al pendiente.\n".auth()->user()->name;
        }
        $nombre = $doc->proveedor?->datosContacto()['nombre'];
        $saludo = $nombre ? 'Hola '.$nombre : 'Buen día';

        return "{$saludo}, te envío la orden de compra {$doc->folioTexto()} de MASUME Industrial.\n"
            .'Referencia: '.$doc->referenciaCliente()."\n\nGracias.\n".auth()->user()->name;
    }

    /**
     * Se abrió WhatsApp con el mensaje (o se compartió el PDF desde el celular). Se registra que se compartió,
     * no que llegó: WhatsApp no avisa si de verdad se mandó.
     */
    public function registrarWhatsapp(string $telefono = '', string $forma = 'enlace'): void
    {
        $this->autorizar();
        $doc = $this->documento();
        $numero = Telefono::whatsapp($telefono);
        $destino = $numero ? '+'.$numero : ($forma === 'enlace' ? 'Sin número (se eligió en WhatsApp)' : 'Contacto elegido en WhatsApp');
        if (! in_array($forma, ['archivo', 'descargado', 'copiada', 'enlace'], true)) {
            $forma = 'enlace';
        }
        $formas = ['archivo' => ' · archivo adjunto', 'descargado' => ' · archivo descargado para adjuntar',
            'copiada' => ' · imagen copiada para pegar', 'enlace' => ' · enlace'];
        CompartirDocumento::registrar($doc, 'whatsapp', $destino.($formas[$forma] ?? ' · enlace'));
        $this->aviso = 'Se registró que compartiste por WhatsApp.';
        $this->avisarCambio($doc->refresh());
    }

    /** La descarga ya quedó registrada en el servidor: solo se vuelve a leer el documento para el aviso y el historial. */
    public function descargado(): void
    {
        $this->aviso = 'Descarga registrada.';
        $this->avisarCambio($this->documento()->refresh());
    }

    public function registrarDescarga(): void
    {
        $this->autorizar();
        $doc = $this->documento();
        CompartirDocumento::registrar($doc, 'descarga', 'Descargado por '.auth()->user()->name);
        $this->aviso = 'Descarga registrada.';
        $this->avisarCambio($doc->refresh());
    }

    // ------------------------------------------------------------------ correo de OC a proveedor

    public function abrirCorreo(): void
    {
        abort_unless($this->tipo === 'orden_proveedor', 404);
        $cuenta = CuentaCorreo::de(auth()->user());
        if (! $cuenta) {
            return;
        }
        /** @var OrdenProveedor $oc */
        $oc = $this->documento()->load('proveedor', 'pedido.cliente');
        $this->resetValidation();
        $this->errorEnvio = '';
        $datos = $oc->proveedor?->datosContacto() ?? ['nombre' => null, 'correo' => null];
        $this->para = (string) ($datos['correo'] ?? '');
        $this->cc = $cuenta;
        $this->asunto = "Orden de compra {$oc->folioTexto()} - MASUME Industrial";
        $saludo = $datos['nombre'] ? 'Hola '.$datos['nombre'] : 'Buen día';
        $this->mensaje = "{$saludo},\n\nTe envío la orden de compra {$oc->folioTexto()}; la encuentras adjunta en PDF. Método de pago: {$oc->metodo_pago}.\n\nPor favor confírmame la recepción y el tiempo de entrega.\n\nSaludos,\n".auth()->user()->name;
        $this->correoAbierto = true;
    }

    public function cerrarCorreo(): void
    {
        $this->correoAbierto = false;
    }

    public function enviarCorreo(): void
    {
        abort_unless($this->tipo === 'orden_proveedor', 404);
        $this->errorEnvio = '';
        $yo = auth()->user();
        $cuenta = CuentaCorreo::de($yo);
        if (! $cuenta) {
            $this->errorEnvio = 'Tu usuario no tiene cuenta de correo asignada. Pídesela al administrador.';

            return;
        }

        $para = CuentaCorreo::separar($this->para);
        $cc = CuentaCorreo::separar($this->cc);
        $this->validate([
            'asunto' => ['required', 'string', 'max:255'],
            'mensaje' => ['required', 'string', 'max:5000'],
        ], ['asunto.required' => 'Escribe el asunto.', 'mensaje.required' => 'Escribe el mensaje.']);
        if ($para === []) {
            $this->addError('para', 'Escribe al menos un correo en "Para".');

            return;
        }
        foreach (['para' => $para, 'cc' => $cc] as $campo => $lista) {
            foreach ($lista as $correo) {
                if (Validator::make(['c' => $correo], ['c' => 'email'])->fails()) {
                    $this->addError($campo, "El correo \"$correo\" no es válido.");

                    return;
                }
            }
        }

        /** @var OrdenProveedor $oc */
        $oc = $this->documento()->load('proveedor');
        $destino = implode(', ', $para).($cc ? ' · CC: '.implode(', ', $cc) : '');
        try {
            $correo = new OrdenProveedorMail($oc, $yo, $cuenta, $this->asunto, $this->mensaje, CompartirDocumento::pdf($oc), $oc->nombreArchivoPdf());
            $pendiente = Mail::mailer('smtp_'.$yo->cuenta_correo)->to($para);
            if ($cc !== []) {
                $pendiente->cc($cc);
            }
            $pendiente->send($correo);
        } catch (Throwable $e) {
            CompartirDocumento::registrar($oc, 'correo', $destino, exito: false, error: $e->getMessage());
            report($e);
            $this->errorEnvio = 'No se pudo enviar el correo. Revisa tu conexión e inténtalo de nuevo; si sigue fallando avisa al administrador.';

            return;
        }

        CompartirDocumento::registrar($oc, 'correo', $destino);
        $this->correoAbierto = false;
        $this->aviso = 'Correo enviado a '.implode(', ', $para).'.';
        $this->avisarCambio($oc->refresh());
    }

    /** El correo de la cotización (otro componente) terminó: vuelve a pintar el historial. */
    #[On('envio-registrado')]
    #[On('revision-actualizada')]
    public function refrescar(): void
    {
        // Solo vuelve a renderizar.
    }

    public function render()
    {
        $doc = $this->documento();
        if ($doc instanceof Cotizacion) {
            $doc->load('cliente', 'contacto');
        } else {
            $doc->load('proveedor.contactos');
        }
        $enlace = CompartirDocumento::enlace($doc);

        // Historial unificado. Los correos de cotizaciones tienen su propio detalle (para, CC, error) en
        // cotizacion_envios; de documento_envios se toman los demás canales para no duplicarlos.
        $historial = CompartirDocumento::envios($doc)->with('user:id,name')
            ->when($doc instanceof Cotizacion, fn ($q) => $q->where('canal', '!=', 'correo'))
            ->limit(50)->get()
            ->map(fn ($e) => ['canal' => $e->canal, 'fecha' => $e->created_at, 'usuario' => $e->user?->name, 'destinatario' => $e->destinatario,
                'revision' => (int) $e->revision, 'exito' => (bool) $e->exito, 'error' => $e->error, 'clave' => 'd'.$e->id]);
        if ($doc instanceof Cotizacion) {
            $historial = $historial->concat(CotizacionEnvio::where('cotizacion_id', $doc->id)->with('user:id,name')->latest('id')->limit(50)->get()
                ->map(fn ($e) => ['canal' => 'correo', 'fecha' => $e->created_at, 'usuario' => $e->user?->name,
                    'destinatario' => $e->para.($e->cc ? ' · CC: '.$e->cc : ''), 'revision' => (int) $e->revision, 'exito' => (bool) $e->exito, 'error' => $e->error, 'clave' => 'c'.$e->id]));
        }
        $historial = $historial->sortByDesc(fn ($h) => $h['fecha']?->getTimestamp())->values();

        // Si el servidor no pudiera dibujar texto en imágenes, la OC se manda como PDF (no se rompe nada).
        $imagen = $doc instanceof OrdenProveedor && function_exists('imagettftext');

        return view('livewire.compartir', [
            'doc' => $doc,
            'enlace' => $enlace,
            'telefono' => $this->telefonoSugerido($doc),
            // Otros números a elegir en WhatsApp (contactos del cliente o vendedores del proveedor).
            'otrosContactos' => ($doc instanceof Cotizacion ? $doc->cliente?->contactos()->get() : $doc->proveedor?->contactos)
                ?->filter(fn ($c) => Telefono::whatsapp($c->telefono))
                ->map(fn ($c) => ['nombre' => $c->nombre, 'numero' => Telefono::whatsapp($c->telefono)])->values()->all() ?? [],
            'textoWhatsapp' => $this->mensajeWhatsapp($doc),
            // Botón "Descargar PDF": la descarga se registra en el servidor (y pasa a enviada).
            'urlDescarga' => $doc instanceof Cotizacion
                ? route('cotizaciones.pdf', ['cotizacion' => $doc, 'descargar' => 1])
                : route('ordenes-proveedor.pdf', ['orden' => $doc, 'descargar' => 1]),
            // El de WhatsApp baja el archivo para adjuntarlo; ese envío lo registra registrarWhatsapp.
            // La OC al proveedor va por WhatsApp como IMAGEN (se ve sin abrir nada, con el modelo/SKU resaltado);
            // la cotización al cliente, como PDF.
            'urlPdf' => ! $imagen
                ? ($doc instanceof Cotizacion
                    ? route('cotizaciones.pdf', ['cotizacion' => $doc, 'descargar' => 1, 'registrar' => 0])
                    : route('ordenes-proveedor.pdf', ['orden' => $doc, 'descargar' => 1, 'registrar' => 0]))
                : route('ordenes-proveedor.imagen', ['orden' => $doc, 'descargar' => 1, 'registrar' => 0]),
            'nombrePdf' => $imagen ? preg_replace('/\.pdf$/i', '.png', $doc->nombreArchivoPdf()) : $doc->nombreArchivoPdf(),
            'esImagen' => $imagen,
            'cuenta' => CuentaCorreo::de(auth()->user()),
            'historial' => $historial,
            'canales' => CompartirDocumento::CANALES,
            // Revisión interna opcional: solo se pregunta antes de mandarla al cliente.
            'avisoRevision' => $doc instanceof Cotizacion ? $doc->avisoSinRevisar() : null,
        ]);
    }
}
