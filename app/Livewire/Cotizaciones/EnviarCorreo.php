<?php

namespace App\Livewire\Cotizaciones;

use App\Mail\CotizacionMail;
use App\Models\Cotizacion;
use App\Services\CotizacionPdf;
use App\Services\Ned\NedCotizacionPdf;
use App\Support\CompartirDocumento;
use App\Support\CuentaCorreo;
use App\Support\NedDesdeCotizacion;
use DomainException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class EnviarCorreo extends Component
{
    #[Locked]
    public int $cotizacionId;

    public bool $abierto = false;

    public string $para = '';

    public string $cc = '';

    public string $asunto = '';

    public string $mensaje = '';

    public string $aviso = '';

    public string $errorEnvio = '';

    /** Palomita: adjuntar también el PDF de Ned Tracking (misma lista, 60% de utilidad sobre el costo). */
    public bool $conNed = false;

    public function mount(Cotizacion $cotizacion): void
    {
        $this->cotizacionId = $cotizacion->id;
    }

    private function cotizacion(): Cotizacion
    {
        return Cotizacion::with(['cliente', 'contacto', 'vendedor'])->findOrFail($this->cotizacionId);
    }

    /** Dirección de la cuenta de salida del usuario actual, o null si no tiene una válida. */
    private function cuentaDelUsuario(): ?string
    {
        return CuentaCorreo::de(auth()->user());
    }

    public function abrir(): void
    {
        $cuenta = $this->cuentaDelUsuario();
        if (! $cuenta) {
            return;
        }

        $cot = $this->cotizacion();
        $yo = auth()->user();
        $this->resetValidation();
        $this->errorEnvio = '';
        $this->aviso = '';
        $this->conNed = false;
        $this->para = (string) ($cot->contacto?->correo ?? '');
        $this->cc = $cuenta;
        $folio = $cot->folioConRevision(' ');
        $this->asunto = "Cotización {$folio} - MASUME Industrial";
        $saludo = $cot->contacto?->nombre ? 'Hola '.$cot->contacto->nombre : 'Buen día';
        $this->mensaje = "{$saludo},\n\nAgradecemos tu interés en MASUME Industrial. Te comparto la cotización {$folio} que solicitaste; la encuentras adjunta en PDF.\n\nQuedo al pendiente de cualquier duda o ajuste que necesites.\n\nSaludos,\n{$yo->name}";
        $this->abierto = true;
    }

    public function cerrar(): void
    {
        $this->abierto = false;
    }

    /** @return array<int, string> */
    private function separar(string $texto): array
    {
        return CuentaCorreo::separar($texto);
    }

    public function enviar(): void
    {
        $this->errorEnvio = '';
        $cuenta = $this->cuentaDelUsuario();
        if (! $cuenta) {
            $this->errorEnvio = 'Tu usuario no tiene cuenta de correo asignada. Pídesela al administrador.';

            return;
        }

        $para = $this->separar($this->para);
        $cc = $this->separar($this->cc);

        $this->validate([
            'asunto' => ['required', 'string', 'max:255'],
            'mensaje' => ['required', 'string', 'max:5000'],
        ], [
            'asunto.required' => 'Escribe el asunto.',
            'mensaje.required' => 'Escribe el mensaje.',
        ]);

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

        $cot = $this->cotizacion();
        $yo = auth()->user();
        $registro = [
            'user_id' => $yo->id,
            'para' => implode(', ', $para),
            'cc' => $cc === [] ? null : implode(', ', $cc),
            'asunto' => $this->asunto,
            'mensaje' => $this->mensaje,
            'revision' => (int) $cot->revision,
        ];

        $extras = [];
        $ned = null;
        if ($this->conNed) {
            abort_unless($yo->nedDesdeMasume(), 403);
            try {
                $ned = NedDesdeCotizacion::sincronizar($cot);
            } catch (DomainException $e) {
                $this->errorEnvio = $e->getMessage();

                return;
            }
            $extras[$ned->nombreArchivoPdf()] = NedCotizacionPdf::generar($ned);
            $registro['mensaje'] .= "\n\n[Se adjuntó también el PDF de Ned Tracking {$ned->folioTexto()}, total $".number_format((float) $ned->total, 2).']';
        }

        try {
            $pdf = CotizacionPdf::generar($cot);
            $correo = new CotizacionMail($cot, $yo, $cuenta, $this->asunto, $this->mensaje, $pdf, $cot->nombreArchivoPdf(), $extras);
            $pendiente = Mail::mailer('smtp_'.$yo->cuenta_correo)->to($para);
            if ($cc !== []) {
                $pendiente->cc($cc);
            }
            $pendiente->send($correo);
        } catch (Throwable $e) {
            $cot->envios()->create($registro + ['exito' => false, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
            $this->errorEnvio = 'No se pudo enviar el correo. Revisa tu conexión e inténtalo de nuevo; si sigue fallando avisa al administrador.';

            return;
        }

        $cot->envios()->create($registro + ['exito' => true]);
        // Registro unificado de envíos (T11) y paso automático a "enviada".
        CompartirDocumento::registrar($cot, 'correo', implode(', ', $para));
        $cot->refresh();

        $this->abierto = false;
        $this->aviso = 'Correo enviado a '.implode(', ', $para).($ned ? ' con los dos PDF (MASUME y Ned Tracking '.$ned->folioTexto().').' : '.');
        $this->dispatch('envio-registrado', estado: $cot->estado->value);
    }

    #[On('revision-actualizada')]
    public function revisionActualizada(): void
    {
        // Solo vuelve a renderizar (para preguntar o no antes de enviar).
    }

    public function render()
    {
        // El historial de envíos lo pinta el componente Compartir (correo, WhatsApp y descarga juntos).
        return view('livewire.cotizaciones.enviar-correo', [
            'cuenta' => $this->cuentaDelUsuario(),
            'puedeNed' => (bool) auth()->user()?->nedDesdeMasume(),
            'avisoRevision' => Cotizacion::find($this->cotizacionId)?->avisoSinRevisar(),
        ]);
    }
}
