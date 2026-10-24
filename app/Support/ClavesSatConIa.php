<?php

namespace App\Support;

use App\Services\Ia\SugeridorClaveSat;
use Illuminate\Support\Collection;

/**
 * Pide a la IA la clave SAT de las partidas que aún no revisa (nuevas, posibles y existentes cuya clave en SICAR
 * está vacía, es la genérica o no existe en el catálogo) y la guarda en la partida (sicar_clave_sat + sicar_sat_ia).
 * La usan la pantalla de Alta en SICAR y el comando masume:sugerir-claves-sat.
 */
class ClavesSatConIa
{
    /** Máximo por clic: cada lote de 10 tarda unos segundos y la petición web tiene 360 s. */
    public const MAXIMO = 40;

    public function __construct(private readonly SugeridorClaveSat $ia = new SugeridorClaveSat) {}

    /**
     * Filas (de AltaSicar::filas) que conviene mandar a la IA.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    public static function pendientes(Collection $filas): Collection
    {
        return $filas->filter(function ($f) {
            if ($f['sat_ia']) {
                return false; // ya la revisó la IA
            }
            if ($f['estado'] !== 'existe') {
                return true;
            }

            return $f['sat'] === '' || $f['sat'] === CatalogoSat::GENERICA || ($f['sat_nombre'] ?? '') === '';
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{revisadas: int, sin_respuesta: int, faltan: int}
     */
    public function aplicar(Collection $filas): array
    {
        $pendientes = self::pendientes($filas);
        $lote = $pendientes->take(self::MAXIMO);
        if ($lote->isEmpty()) {
            return ['revisadas' => 0, 'sin_respuesta' => 0, 'faltan' => 0];
        }

        $r = $this->ia->sugerir($lote->map(fn ($f) => [
            'id' => (int) $f['partida']->id,
            'descripcion' => trim($f['partida']->descripcion.' '.($f['partida']->modelo ? '(modelo '.$f['partida']->modelo.')' : '')),
            'unidad' => (string) $f['unidad'],
            'clave_actual' => $f['estado'] === 'existe' ? $f['sat'] : null,
        ])->values()->all());

        foreach ($lote as $f) {
            $s = $r[(int) $f['partida']->id] ?? null;
            // Sin respuesta válida se marca "baja" con clave vacía para no volver a pagar por ella en cada clic.
            $f['partida']->update(['sicar_clave_sat' => $s['clave'] ?? $f['partida']->sicar_clave_sat, 'sicar_sat_ia' => $s['confianza'] ?? 'baja']);
        }

        return ['revisadas' => count($r), 'sin_respuesta' => $lote->count() - count($r), 'faltan' => $pendientes->count() - $lote->count()];
    }
}
