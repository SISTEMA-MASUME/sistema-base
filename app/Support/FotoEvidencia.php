<?php

namespace App\Support;

/** Redimensiona (GD) las fotos de evidencia: máx. 1600 px de lado, JPEG calidad 80, respetando la orientación EXIF. */
class FotoEvidencia
{
    public const MAX_LADO = 1600;

    public const CALIDAD = 80;

    public const MAX_PIXELES = 60_000_000;

    /** @return string|null Bytes del JPEG, o null si el archivo no es una imagen que GD pueda leer. */
    public static function procesar(string $rutaOrigen): ?string
    {
        $bytes = @file_get_contents($rutaOrigen);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        // Tope de píxeles antes de abrirla con GD: alcanza para fotos de celular de 50 MP.
        if (! $info || $info[0] * $info[1] > self::MAX_PIXELES) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }

        // Conserva la orientación del celular: sin exif no se rota.
        if (function_exists('exif_read_data') && function_exists('imagerotate')) {
            $exif = @exif_read_data($rutaOrigen);
            $grados = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($grados !== 0 && ($rotada = imagerotate($img, $grados, 0))) {
                $img = $rotada;
            }
        }

        [$w, $h] = [imagesx($img), imagesy($img)];
        $escala = min(1, self::MAX_LADO / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        // Fondo blanco: los PNG con transparencia no deben quedar negros en JPEG.
        $lienzo = imagecreatetruecolor($nw, $nh);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopyresampled($lienzo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($lienzo, null, self::CALIDAD);

        return (string) ob_get_clean();
    }
}
