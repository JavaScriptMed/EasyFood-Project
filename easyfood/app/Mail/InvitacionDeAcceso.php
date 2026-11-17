<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** El correo con el enlace para crear la contraseña y entrar a EasyLunch. */
class InvitacionDeAcceso extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $organizacion,
        public readonly string $url,
        public readonly CarbonInterface $venceEn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Te invitaron a EasyLunch ({$this->organizacion})");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.invitacion');
    }
}
