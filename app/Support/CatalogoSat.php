<?php

namespace App\Support;

use App\Models\SatClave;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Búsqueda en el catálogo oficial del SAT (c_ClaveProdServ): nombres de claves y candidatas para una
 * descripción. Las candidatas se buscan por palabras (LIKE) y se ordenan en PHP; la IA elige entre ellas.
 */
class CatalogoSat
{
    public const GENERICA = '01010101';

    private const VACIAS = ['PARA', 'CON', 'SIN', 'DEL', 'LOS', 'LAS', 'UNA', 'UNO', 'POR', 'QUE', 'TIPO', 'COLOR',
        'MARCA', 'MODELO', 'MEDIDA', 'PULGADAS', 'PULG', 'PZA', 'PZAS', 'PIEZA', 'PIEZAS', 'JUEGO', 'CAJA', 'PAQ', 'PAQUETE',
        'MTS', 'METRO', 'METROS', 'LITRO', 'LITROS', 'KG', 'GRANDE', 'CHICO', 'MEDIANO', 'NEGRO', 'BLANCO', 'ROJO', 'AZUL',
        'AMARILLO', 'VERDE', 'GRIS', 'TRUPER', 'URREA', 'PRETUL', 'SURTEK', 'DEWALT', 'MAKITA', 'BOSCH', 'STANLEY', 'MILWAUKEE',
        'INDUSTRIAL', 'PROFESIONAL', 'ACERO', 'INOX', 'INOXIDABLE'];

    /** @var array<string, string> clave → descripción oficial */
    private array $nombres = [];

    public static function normalizar(string $texto): string
    {
        return Str::squish(preg_replace('/[^A-Z0-9]+/', ' ', Str::upper(Str::ascii($texto))) ?? '');
    }

    /**
     * Raíces de las palabras útiles de una descripción (sin números, medidas ni palabras vacías),
     * sin plural para que "GUANTES" encuentre "GUANTE".
     *
     * @return list<string>
     */
    public static function raices(string $descripcion): array
    {
        $raices = [];
        foreach (explode(' ', self::normalizar($descripcion)) as $p) {
            if (mb_strlen($p) < 3 || preg_match('/\d/', $p) || in_array($p, self::VACIAS, true)) {
                continue;
            }
            if (mb_strlen($p) > 4 && str_ends_with($p, 'ES') && ! preg_match('/[AEIOU]ES$/', $p)) {
                $p = substr($p, 0, -2);
            } elseif (mb_strlen($p) > 4 && str_ends_with($p, 'S')) {
                $p = substr($p, 0, -1);
            }
            $raices[$p] = true;
        }

        return array_keys($raices);
    }

    /**
     * Claves del catálogo que más se parecen a la descripción (mejor primero).
     *
     * @return Collection<int, SatClave>
     */
    public function candidatos(string $descripcion, int $n = 25): Collection
    {
        // La primera palabra suele decir qué es el artículo (GUANTE, LLAVE, DISCO); luego las más largas.
        $todas = self::raices($descripcion);
        if ($todas === []) {
            return collect();
        }
        $resto = collect(array_slice($todas, 1))->sortByDesc(fn ($r) => mb_strlen($r))->take(5)->all();
        $raices = [$todas[0], ...$resto];
        if ($raices === []) {
            return collect();
        }

        $filas = SatClave::query()->select(['clave', 'descripcion', 'similares', 'texto'])
            ->where(function ($q) use ($raices) {
                foreach ($raices as $r) {
                    $q->orWhere('texto', 'like', '%'.$r.'%');
                }
            })
            ->where('clave', '!=', self::GENERICA)
            ->limit(5000)->get();

        $puntuadas = $filas->map(function (SatClave $s) use ($raices) {
            $desc = ' '.self::normalizar($s->descripcion);
            $sim = ' '.self::normalizar((string) $s->similares);
            $puntos = 0.0;
            foreach ($raices as $i => $r) {
                // Pesa más la primera palabra de la descripción del artículo (suele ser el sustantivo).
                $peso = $i === 0 ? 3.0 : 1.0;
                if (str_contains($desc, ' '.$r)) {
                    $puntos += 2 * $peso;
                } elseif (str_contains($sim, ' '.$r)) {
                    $puntos += 1 * $peso;
                }
            }
            // A igualdad, las descripciones cortas (más específicas del concepto) primero.
            $s->setAttribute('puntos', $puntos - mb_strlen($s->descripcion) / 1000);

            return $s;
        })->filter(fn ($s) => $s->puntos > 0.5);

        return $puntuadas->sortByDesc('puntos')->take($n)->values();
    }

    /**
     * Descripción oficial de cada clave (las que no existen no aparecen).
     *
     * @param  iterable<string>  $claves
     * @return array<string, string>
     */
    public function nombres(iterable $claves): array
    {
        $faltan = collect($claves)->map(fn ($c) => trim((string) $c))->filter()->unique()
            ->reject(fn ($c) => array_key_exists($c, $this->nombres))->values();
        if ($faltan->isNotEmpty()) {
            $encontradas = SatClave::whereIn('clave', $faltan->all())->pluck('descripcion', 'clave')->all();
            foreach ($faltan as $c) {
                $this->nombres[$c] = $encontradas[$c] ?? '';
            }
        }

        return collect($claves)->mapWithKeys(fn ($c) => [trim((string) $c) => $this->nombres[trim((string) $c)] ?? ''])
            ->filter()->all();
    }

    public function nombre(?string $clave): ?string
    {
        $clave = trim((string) $clave);

        return $clave === '' ? null : ($this->nombres([$clave])[$clave] ?? null);
    }

    /** Hay catálogo cargado (en pruebas o en una instalación nueva puede estar vacío). */
    public static function cargado(): bool
    {
        return SatClave::query()->exists();
    }
}
