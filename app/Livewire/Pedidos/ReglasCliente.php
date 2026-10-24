<?php

namespace App\Livewire\Pedidos;

use App\Mail\PedidoDocumentosMail;
use App\Models\ClienteSucursal;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Support\CuentaCorreo;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Lo que cada cliente pide después de entregar (T16): ACME quiere la evidencia de entrega respondiendo
 * el correo de su OC; PARQUES / LUMEX quieren la factura en los correos de cada plaza. No es para el rol campo.
 */
class ReglasCliente extends Component
{
    #[Locked]
    public int $pedidoId;

    public string $sucursalId = '';

    public string $remitente = '';

    public string $asuntoOc = '';

    /** '' | 'evidencia' | 'factura': correo que se está por mandar (para revisar antes). */
    public string $preparando = '';

    public string $para = '';

    public string $asunto = '';

    public string $mensaje = '';

    public string $aviso = '';

    public string $errorEnvio = '';

    public function mount(int $pedidoId): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->pedidoId = $pedidoId;
        $p = $this->pedido();
        $this->sucursalId = (string) ($p->sucursal_id ?? '');
        $this->remitente = (string) $p->oc_correo_remitente;
        $this->asuntoOc = (string) $p->oc_correo_asunto;
    }

    private function pedido(): Pedido
    {
        return Pedido::with([Pedido::CLIENTE_REGLAS, 'sucursal'])->findOrFail($this->pedidoId);
    }

    private function cambio(string $tipo, string $texto): void
    {
        PedidoActividad::registrar($this->pedidoId, $tipo, $texto);
        $this->dispatch('pedido-actualizado');
        $this->dispatch('reglas-actualizadas');
    }

    public function guardarSucursal(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $p = $this->pedido();
        $sucursal = $this->sucursalId !== ''
            ? ClienteSucursal::where('cliente_id', $p->cliente_id)->findOrFail((int) $this->sucursalId)
            : null;
        $p->update(['sucursal_id' => $sucursal?->id]);
        $this->aviso = $sucursal ? 'Plaza guardada: '.$sucursal->ubicacion().'.' : 'Se quitó la plaza.';
        $this->cambio('plaza', $sucursal ? 'asignó la plaza '.$sucursal->ubicacion() : 'quitó la plaza');
    }

    public function guardarCorreoOc(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->validate([
            'remitente' => ['nullable', 'email', 'max:255'],
            'asuntoOc' => ['nullable', 'string', 'max:255'],
        ], ['remitente.email' => 'Escribe el correo de quien mandó la OC.']);
        $this->pedido()->update([
            'oc_correo_remitente' => trim($this->remitente) !== '' ? trim($this->remitente) : null,
            'oc_correo_asunto' => trim($this->asuntoOc) !== '' ? trim($this->asuntoOc) : null,
        ]);
        $this->aviso = 'Datos del correo de la OC guardados.';
        $this->dispatch('reglas-actualizadas');
    }

    /** @return list<array{disco: string, ruta: string, nombre: string, mime: string}> */
    private function adjuntos(Pedido $p, array $tipos): array
    {
        return $p->archivos()->whereIn('tipo', $tipos)->get()
            ->map(fn ($a) => ['disco' => $a->disco, 'ruta' => $a->ruta, 'nombre' => $a->nombre_original, 'mime' => (string) $a->mime])
            ->all();
    }

    private function referencia(Pedido $p): string
    {
        return $p->numero_oc_cliente ? 'OC '.$p->numero_oc_cliente : 'pedido P-'.$p->folio;
    }

    public function preparar(string $que): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $this->errorEnvio = '';
        $this->resetValidation();
        $p = $this->pedido();
        $yo = auth()->user();

        if ($que === 'evidencia') {
            if (! $this->adjuntos($p, ['evidencia'])) {
                $this->errorEnvio = 'Primero sube las fotos de evidencia en el expediente.';

                return;
            }
            $this->para = (string) $p->oc_correo_remitente;
            $original = trim((string) $p->oc_correo_asunto);
            $this->asunto = $original !== ''
                ? (preg_match('/^\s*re\s*:/i', $original) ? $original : 'RE: '.$original)
                : 'Evidencia de entrega '.$this->referencia($p);
            $this->mensaje = "Buen día,\n\nLes comparto la evidencia de entrega de la {$this->referencia($p)}. Van adjuntas las fotos".($this->adjuntos($p, ['factura_pdf']) ? ' y la factura' : '').".\n\nQuedo al pendiente.\n\nSaludos,\n{$yo->name}";
        } elseif ($que === 'factura') {
            $sucursal = $p->sucursal;
            if (! $sucursal) {
                $this->errorEnvio = 'Primero elige la plaza del pedido.';

                return;
            }
            if (! $this->adjuntos($p, ['factura_pdf', 'factura_xml'])) {
                $this->errorEnvio = 'Primero sube la factura (PDF y XML) en el expediente.';

                return;
            }
            $this->para = implode(', ', $sucursal->correos());
            $this->asunto = 'Factura '.$this->referencia($p).' - '.$sucursal->nombre;
            $this->mensaje = "Buen día,\n\nLes comparto la factura de la {$this->referencia($p)} de la plaza {$sucursal->nombre}. Van adjuntos el PDF y el XML.\n\nSaludos,\n{$yo->name}";
        } else {
            return;
        }
        $this->preparando = $que;
    }

    public function cancelar(): void
    {
        $this->preparando = '';
    }

    public function enviar(): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        $que = $this->preparando;
        abort_unless(in_array($que, ['evidencia', 'factura'], true), 422);
        $this->errorEnvio = '';
        $yo = auth()->user();
        $cuenta = CuentaCorreo::de($yo);
        if (! $cuenta) {
            $this->errorEnvio = 'Tu usuario no tiene cuenta de correo asignada. Pídesela al administrador.';

            return;
        }
        $this->validate([
            'asunto' => ['required', 'string', 'max:255'],
            'mensaje' => ['required', 'string', 'max:5000'],
        ], ['asunto.required' => 'Escribe el asunto.', 'mensaje.required' => 'Escribe el mensaje.']);
        $para = CuentaCorreo::separar($this->para);
        if ($para === []) {
            $this->addError('para', $que === 'evidencia' ? 'Escribe el correo de quien mandó la OC.' : 'La plaza no tiene correos de facturación. Agrégalos en la ficha del cliente.');

            return;
        }
        foreach ($para as $correo) {
            if (Validator::make(['c' => $correo], ['c' => 'email'])->fails()) {
                $this->addError('para', "El correo \"$correo\" no es válido.");

                return;
            }
        }

        $p = $this->pedido();
        $archivos = $que === 'evidencia'
            ? $this->adjuntos($p, ['evidencia', 'factura_pdf'])
            : $this->adjuntos($p, ['factura_pdf', 'factura_xml']);
        $registro = ['user_id' => $yo->id, 'canal' => 'correo', 'destinatario' => mb_substr(implode(', ', $para), 0, 255), 'revision' => 0];

        try {
            Mail::mailer('smtp_'.$yo->cuenta_correo)->to($para)->cc($cuenta)
                ->send(new PedidoDocumentosMail($yo, $cuenta, $this->asunto, $this->mensaje, $archivos));
        } catch (Throwable $e) {
            $p->envios()->create($registro + ['exito' => false, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
            $this->errorEnvio = 'No se pudo enviar el correo. Revisa tu conexión e inténtalo de nuevo; si sigue fallando avisa al administrador.';

            return;
        }

        $p->envios()->create($registro + ['exito' => true]);
        if ($que === 'evidencia') {
            $p->update(['evidencia_enviada_at' => now(), 'evidencia_enviada_por' => $yo->id]);
            $this->cambio('evidencia', 'envió la evidencia de entrega por correo a '.implode(', ', $para));
        } else {
            $p->update(['factura_sucursal_enviada_at' => now(), 'factura_sucursal_enviada_por' => $yo->id]);
            $this->cambio('factura', 'envió la factura a la plaza '.$p->sucursal->nombre.' ('.implode(', ', $para).')');
        }
        $this->preparando = '';
        $this->aviso = 'Correo enviado a '.implode(', ', $para).'.';
    }

    public function render()
    {
        $p = $this->pedido()->load('evidenciaEnviadaPor:id,name', 'facturaSucursalEnviadaPor:id,name');

        return view('livewire.pedidos.reglas-cliente', [
            'pedido' => $p,
            'sucursales' => ClienteSucursal::where('cliente_id', $p->cliente_id)->orderBy('nombre')->get(),
            'porCorreo' => $p->clienteEnviaEvidenciaPorCorreo(),
            'cuenta' => CuentaCorreo::de(auth()->user()),
        ]);
    }
}
