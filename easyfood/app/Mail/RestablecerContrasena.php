<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** El correo con el enlace para elegir una contraseña nueva. */
class RestablecerContrasena extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Reintentos si el servidor de correo falla: la petición del usuario nunca espera ni falla por el correo. */
    public int $tries = 3;

    /** @var array<int, int> segundos de espera entre reintentos */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $nombre, public readonly string $url, public readonly int $minutos) {
        // El enlace lleva el token en claro: en la cola va CIFRADO con APP_KEY y no se encola hasta que la transacción se confirma.
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Restablece tu contraseña de EasyLunch');
    }

    public function content(): Content
    {
        return new Content(view: 'correos.restablecer');
    }
}
