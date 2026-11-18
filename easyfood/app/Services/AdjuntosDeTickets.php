<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivos que viajan en los mensajes de un ticket. Se guardan en el disco `public` (storage/app/public/tickets) con un nombre
 * aleatorio e imposible de adivinar; el nombre original solo se guarda para mostrarlo (limpio de rutas y caracteres raros).
 */
class AdjuntosDeTickets
{
    public const DISCO = 'public';

    public const CARPETA = 'tickets';

    /** Foto (JPG, PNG o WebP) o PDF, de hasta 5 MB. Nunca SVG ni ejecutables. */
    public const REGLAS = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:5120'];

    /** @return array{adjunto_ruta: string, adjunto_nombre: string, adjunto_tipo: string} */
    public function guardar(UploadedFile $archivo): array
    {
        $tipo = (string) $archivo->getMimeType();
        $extension = match ($tipo) {
            'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf', default => 'jpg',
        };
        $ruta = $archivo->storeAs(self::CARPETA, Str::random(40).".{$extension}", self::DISCO);

        return ['adjunto_ruta' => $ruta, 'adjunto_nombre' => $this->nombreLimpio($archivo->getClientOriginalName(), $extension), 'adjunto_tipo' => $tipo];
    }

    public function url(string $ruta): string
    {
        return Storage::disk(self::DISCO)->url($ruta);
    }

    private function nombreLimpio(string $original, string $extension): string
    {
        $base = Str::of(pathinfo($original, PATHINFO_FILENAME))->replaceMatches('/[^\p{L}\p{N} ._-]+/u', '')->trim()->limit(80, '')->toString();

        return ($base !== '' ? $base : 'archivo').".{$extension}";
    }
}
