<?php

namespace App\Mail;

use App\Models\ErrorSistema;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ErrorRegistradoMail extends Mailable
{
    public function __construct(public ErrorSistema $error) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.mailers.smtp_ventas.username'), 'Sistema MASUME'),
            subject: "⚠️ Error {$this->error->codigo} en Sistema MASUME",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.error-registrado', with: [
            'error' => $this->error,
            'usuario' => $this->error->usuario?->name,
            'enlace' => url('/errores?buscar='.$this->error->codigo),
        ]);
    }
}
