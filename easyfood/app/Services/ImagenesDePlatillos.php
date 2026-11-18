<?php

namespace App\Services;

use App\Models\Menu;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La foto de un platillo. Se guarda en el disco `public` (storage/app/public/platillos) con un nombre aleatorio (nunca el que manda
 * quien sube el archivo) y `menus.image_url` apunta a su URL. Al cambiar la foto se borra la anterior si era una de estas.
 */
class ImagenesDePlatillos
{
    public const DISCO = 'public';

    public const CARPETA = 'platillos';

    /** Reglas de validación del archivo: foto real (no SVG), de hasta 2 MB. */
    public const REGLAS = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'];

    public function guardar(Menu $platillo, UploadedFile $archivo): Menu
    {
        $extension = match ($archivo->getMimeType()) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
        $ruta = $archivo->storeAs(self::CARPETA, "{$platillo->id}-".Str::random(24).".{$extension}", self::DISCO);

        $anterior = $this->rutaPropia($platillo->image_url);
        $platillo->update(['image_url' => Storage::disk(self::DISCO)->url($ruta)]);
        if ($anterior) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $platillo->refresh();
    }

    /** La ruta dentro del disco si la URL es de una foto que guardamos nosotros (las fotos externas no se tocan). */
    private function rutaPropia(?string $url): ?string
    {
        $base = rtrim(Storage::disk(self::DISCO)->url(self::CARPETA), '/').'/';

        return $url && str_starts_with($url, $base) ? self::CARPETA.'/'.basename($url) : null;
    }
}
