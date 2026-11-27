<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Aviso de que la contraseña cambió. Nunca incluye la contraseña ni ningún código. */
class ContrasenaCambiadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $fechaHora) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cambió la contraseña de tu cuenta - Simulador SPGTH');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contrasena-cambiada');
    }
}
