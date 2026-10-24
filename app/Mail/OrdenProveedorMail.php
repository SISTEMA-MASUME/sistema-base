<?php

namespace App\Mail;

use App\Models\OrdenProveedor;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo al proveedor con la OC en PDF. Nunca incluye precio de venta ni utilidad. */
class OrdenProveedorMail extends Mailable
{
    public function __construct(
        public OrdenProveedor $orden,
        public User $usuario,
        public string $cuentaCorreo,
        public string $asunto,
        public string $mensajeUsuario,
        public string $pdf,
        public string $nombrePdf,
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
            view: 'mail.orden-proveedor',
            with: [
                'folio' => $this->orden->folioTexto(),
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
        return [
            Attachment::fromData(fn () => $this->pdf, $this->nombrePdf)->withMime('application/pdf'),
        ];
    }
}
