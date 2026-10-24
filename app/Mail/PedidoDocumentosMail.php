<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo al cliente con archivos del expediente del pedido: evidencia de entrega (ACME) o la factura
 * a una plaza (PARQUES / LUMEX). Nunca lleva costos ni proveedores.
 */
class PedidoDocumentosMail extends Mailable
{
    /**
     * @param  list<array{disco: string, ruta: string, nombre: string, mime: string}>  $archivos
     */
    public function __construct(
        public User $usuario,
        public string $cuentaCorreo,
        public string $asunto,
        public string $mensajeUsuario,
        public array $archivos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->cuentaCorreo, $this->usuario->nombreReal().' · MASUME Industrial'),
            replyTo: [new Address($this->cuentaCorreo, $this->usuario->nombreReal())],
            subject: $this->asunto,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.pedido-documentos',
            with: [
                'mensajeUsuario' => $this->mensajeUsuario,
                'nombreUsuario' => $this->usuario->nombreReal(),
                'telefonoUsuario' => $this->usuario->telefono,
                'correoUsuario' => $this->cuentaCorreo,
                'empresa' => config('masume.empresa'),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn ($a) => Attachment::fromStorageDisk($a['disco'], $a['ruta'])->as($a['nombre'])->withMime($a['mime']),
            $this->archivos,
        );
    }
}
