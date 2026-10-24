<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Imagen de partida (cotizador y catálogo de imágenes): se reduce a 800 px con GD y se guarda en public/partidas. */
final class ImagenPartida
{
    private const MAX_LADO = 800;

    /** Redimensiona a máx. 800 px con GD y guarda en public/partidas. */
    public static function guardar(string $origen): ?string
    {
        $info = @getimagesize($origen);
        // Se revisa el tamaño en píxeles ANTES de abrirla con GD (una imagen chica en bytes puede pedir mucha memoria).
        if (! $info || $info[0] * $info[1] > FotoEvidencia::MAX_PIXELES) {
            return null;
        }

        $img = @imagecreatefromstring((string) file_get_contents($origen));
        if (! $img) {
            return null;
        }

        [$w, $h] = [imagesx($img), imagesy($img)];
        $escala = min(1, self::MAX_LADO / max($w, $h));
        if ($escala < 1) {
            $nw = max(1, (int) round($w * $escala));
            $nh = max(1, (int) round($h * $escala));
            $nuevo = imagecreatetruecolor($nw, $nh);
            imagealphablending($nuevo, false);
            imagesavealpha($nuevo, true);
            imagefill($nuevo, 0, 0, imagecolorallocatealpha($nuevo, 255, 255, 255, 127));
            imagecopyresampled($nuevo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $nuevo;
        }

        $tipo = $info[2];
        ob_start();
        if ($tipo === IMAGETYPE_PNG) {
            imagesavealpha($img, true);
            imagepng($img);
            $ext = 'png';
        } elseif ($tipo === IMAGETYPE_WEBP) {
            imagesavealpha($img, true);
            imagewebp($img, null, 85);
            $ext = 'webp';
        } else {
            imagejpeg($img, null, 85);
            $ext = 'jpg';
        }
        $bytes = ob_get_clean();

        $ruta = 'partidas/'.Str::random(40).'.'.$ext;
        Storage::disk('public')->put($ruta, $bytes);

        return $ruta;
    }
}
