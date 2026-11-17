<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** El correo con el enlace para elegir una contraseña nueva. */
class RestablecerContrasena extends Mailable
{
    public function __construct(public readonly string $nombre, public readonly string $url, public readonly int $minutos) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Restablece tu contraseña de EasyLunch');
    }

    public function content(): Content
    {
        return new Content(view: 'correos.restablecer');
    }
}
