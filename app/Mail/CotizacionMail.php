<?php

namespace App\Mail;

use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo al cliente con la cotización en PDF. Nunca incluye costos ni proveedores. */
class CotizacionMail extends Mailable
{
    public function __construct(
        public Cotizacion $cotizacion,
        public User $vendedor,
        public string $cuentaCorreo,
        public string $asunto,
        public string $mensajeVendedor,
        public string $pdf,
        public string $nombrePdf,
        /** Otros PDF a adjuntar (nombre => contenido), p. ej. el de Ned Tracking. */
        public array $extras = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->cuentaCorreo, $this->vendedor->nombreReal().' · MASUME Industrial'),
            replyTo: [new Address($this->cuentaCorreo, $this->vendedor->nombreReal())],
            subject: $this->asunto,
        );
    }

    public function content(): Content
    {
        $cot = $this->cotizacion;

        return new Content(
            view: 'mail.cotizacion',
            with: [
                'folio' => $cot->folio,
                'total' => number_format((float) $cot->total, 2),
                'vigenciaHasta' => $cot->fecha->copy()->addDays((int) $cot->vigencia_dias)->format('d/m/Y'),
                'vigenciaDias' => (int) $cot->vigencia_dias,
                'mensajeVendedor' => $this->mensajeVendedor,
                'nombreVendedor' => $this->vendedor->nombreReal(),
                'telefonoVendedor' => $this->vendedor->telefono,
                'correoVendedor' => $this->cuentaCorreo,
                'empresa' => config('masume.empresa'),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $adjuntos = [Attachment::fromData(fn () => $this->pdf, $this->nombrePdf)->withMime('application/pdf')];
        foreach ($this->extras as $nombre => $bytes) {
            $adjuntos[] = Attachment::fromData(fn () => $bytes, $nombre)->withMime('application/pdf');
        }

        return $adjuntos;
    }
}
