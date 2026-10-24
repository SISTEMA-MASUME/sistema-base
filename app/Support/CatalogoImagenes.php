<?php

namespace App\Support;

use App\Models\CotizacionPartida;
use App\Models\PedidoPartida;
use App\Models\ProductoImagen;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Catálogo de imágenes por producto. La imagen que se le pone a una partida queda ligada al producto y se
 * pone sola cuando se vuelve a cotizar. El producto se reconoce por su MODELO/SKU (si tiene) o por su
 * DESCRIPCIÓN, ignorando mayúsculas, acentos, espacios y puntuación. Quitar la imagen de una partida
 * desliga al producto (ya no se pone sola); las cotizaciones hechas conservan la suya.
 *
 * Partidas y catálogo comparten el mismo archivo: solo se borra cuando ya nadie lo usa.
 */
final class CatalogoImagenes
{
    private const MIN_DESCRIPCION = 4;

    public static function llaveModelo(?string $modelo): ?string
    {
        $modelo = trim((string) $modelo);
        if ($modelo === '' || LinkCompra::esUrl($modelo)) {
            return null;
        }
        $llave = Nombres::llave($modelo);

        return strlen($llave) >= 2 ? sha1($llave) : null;
    }

    public static function llaveDescripcion(?string $descripcion): ?string
    {
        $llave = Nombres::llave((string) $descripcion);

        return strlen($llave) >= self::MIN_DESCRIPCION ? sha1($llave) : null;
    }

    /**
     * $exacta = true: la ficha de ESTE producto (para guardarla o reemplazarla).
     * $exacta = false: la imagen que le queda (si el modelo no está en el catálogo, se busca por descripción
     * entre las fichas sin modelo, para no ponerle la foto de otro modelo).
     */
    public static function buscarFila(?string $modelo, ?string $descripcion, bool $exacta = false): ?ProductoImagen
    {
        $lm = self::llaveModelo($modelo);
        $ld = self::llaveDescripcion($descripcion);
        if ($lm) {
            $fila = ProductoImagen::where('llave_modelo', $lm)->latest('updated_at')->first();
            if ($fila || $exacta) {
                return $fila;
            }
        }
        if (! $ld) {
            return null;
        }

        // Por descripción solo cuentan las fichas sin modelo: con modelo, la foto es de ese modelo en particular.
        return ProductoImagen::where('llave_descripcion', $ld)->whereNull('llave_modelo')->latest('updated_at')->first();
    }

    /** Búsqueda por palabras en descripción o modelo (pantalla del catálogo y "Buscar en catálogo"). */
    public static function buscar(string $texto): Builder
    {
        $q = ProductoImagen::query();
        foreach (array_slice(preg_split('/\s+/u', trim($texto)) ?: [], 0, 6) as $palabra) {
            $palabra = str_replace(['%', '_'], '', $palabra);
            if ($palabra !== '') {
                $q->where(fn ($w) => $w->where('descripcion', 'like', "%{$palabra}%")->orWhere('modelo', 'like', "%{$palabra}%"));
            }
        }

        return $q;
    }

    private const PALABRAS_VACIAS = ['para', 'marca', 'como', 'tipo', 'color', 'pieza', 'piezas', 'modelo', 'incluye', 'medida'];

    /** @return list<string> palabras significativas (sin acentos, 4+ letras) */
    private static function palabras(?string $texto): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii((string) $texto))) ?: [],
            fn ($p) => strlen($p) >= 4 && ! in_array($p, self::PALABRAS_VACIAS, true),
        )));
    }

    /**
     * Productos del catálogo que se parecen (comparten al menos 2 palabras y la mitad de las suyas) pero
     * son otra ficha: para avisar "ya hay uno parecido" y no llenar el catálogo de duplicados.
     *
     * @return Collection<int, ProductoImagen>
     */
    public static function parecidos(?string $descripcion, ?int $excepto = null): Collection
    {
        $mias = self::palabras($descripcion);
        if (count($mias) < 2) {
            return collect();
        }
        $q = ProductoImagen::query()->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->where(function ($w) use ($mias) {
                foreach (array_slice($mias, 0, 8) as $p) {
                    $w->orWhere('descripcion', 'like', "%{$p}%");
                }
            });

        return $q->latest('updated_at')->limit(200)->get()
            ->map(function (ProductoImagen $f) use ($mias) {
                $suyas = self::palabras($f->descripcion);
                $f->setAttribute('comunes', count(array_intersect($mias, $suyas)));
                $f->setAttribute('minimo', max(2, (int) ceil(min(count($suyas), count($mias)) / 2)));

                return $f;
            })
            ->filter(fn ($f) => $f->comunes >= $f->minimo)
            ->sortByDesc('comunes')->take(3)->values();
    }

    /** Liga (o cambia) la imagen del producto. */
    public static function vincular(?string $modelo, ?string $descripcion, string $ruta, ?int $userId = null): ?ProductoImagen
    {
        $ld = self::llaveDescripcion($descripcion);
        if (! $ld) {
            return null;
        }
        $modelo = trim((string) $modelo);
        $datos = [
            'llave_modelo' => self::llaveModelo($modelo),
            'llave_descripcion' => $ld,
            'descripcion' => mb_substr(trim((string) $descripcion), 0, 2000),
            'modelo' => self::llaveModelo($modelo) ? mb_substr($modelo, 0, 191) : null,
            'imagen_path' => $ruta,
            'user_id' => $userId,
        ];
        $fila = self::buscarFila($modelo, $descripcion, true);
        if (! $fila) {
            return ProductoImagen::create($datos);
        }
        $anterior = $fila->imagen_path;
        $fila->update($datos);
        if ($anterior !== $ruta) {
            self::borrarSiSobra($anterior);
        }

        return $fila;
    }

    /** Desliga la imagen del producto: ya no se pondrá sola. */
    public static function desvincular(?string $modelo, ?string $descripcion): bool
    {
        $fila = self::buscarFila($modelo, $descripcion);
        if (! $fila) {
            return false;
        }
        self::eliminar($fila);

        return true;
    }

    public static function eliminar(ProductoImagen $fila): void
    {
        $ruta = $fila->imagen_path;
        $fila->delete();
        self::borrarSiSobra($ruta);
    }

    /** Ruta de la imagen guardada para ese producto (o null), si el archivo existe. */
    public static function imagenPara(?string $modelo, ?string $descripcion): ?string
    {
        $fila = self::buscarFila($modelo, $descripcion);
        if (! $fila || ! Storage::disk('public')->exists($fila->imagen_path)) {
            return null;
        }
        $fila->increment('veces_usada');

        return $fila->imagen_path;
    }

    /** ¿Esa ruta es la imagen guardada de algún producto? (la partida la tomó del catálogo) */
    public static function esDelCatalogo(?string $ruta): bool
    {
        return $ruta !== null && $ruta !== '' && ProductoImagen::where('imagen_path', $ruta)->exists();
    }

    public static function enUso(string $ruta, int $exceptoPartidaId = 0): bool
    {
        return CotizacionPartida::where('imagen_path', $ruta)->where('id', '!=', $exceptoPartidaId)->exists()
            || PedidoPartida::where('imagen_path', $ruta)->exists()
            || ProductoImagen::where('imagen_path', $ruta)->exists();
    }

    /** Borra el archivo si ya nadie lo usa (otras partidas, pedidos o el catálogo). */
    public static function borrarSiSobra(?string $ruta, int $exceptoPartidaId = 0): void
    {
        if ($ruta && ! self::enUso($ruta, $exceptoPartidaId) && Storage::disk('public')->exists($ruta)) {
            Storage::disk('public')->delete($ruta);
        }
    }
}
