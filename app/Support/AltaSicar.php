<?php

namespace App\Support;

use App\Models\Pedido;
use App\Models\PedidoPartida;
use App\Models\SicarArticulo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resuelve, para cada partida de los pedidos elegidos, qué hay que hacer en SICAR:
 *  - existe:  ya está en el catálogo (clave y unidad de SICAR),
 *  - posible: parece uno del catálogo pero la persona debe confirmar,
 *  - nuevo:   hay que darlo de alta (clave, unidad, categoría y clave SAT propuestas o ya decididas).
 * Respeta las decisiones guardadas en la partida (sicar_art_id / sicar_clave…).
 */
class AltaSicar
{
    public const CATEGORIA = 'FERRETERIA';

    public function __construct(public readonly EmparejadorSicar $emparejador = new EmparejadorSicar) {}

    /**
     * @param  Collection<int, Pedido>  $pedidos  con partidas.proveedor y cliente cargados
     * @return Collection<int, array<string, mixed>>
     */
    public function filas(Collection $pedidos): Collection
    {
        $filas = collect();
        foreach ($pedidos as $pedido) {
            foreach ($pedido->partidas as $partida) {
                $filas->push(['pedido' => $pedido, 'partida' => $partida]);
            }
        }

        // Primero se reservan las claves ya decididas, para que las propuestas nuevas no choquen con ellas.
        foreach ($filas as $f) {
            if ($f['partida']->sicar_clave && ! $f['partida']->sicar_art_id) {
                $this->emparejador->reservar($f['partida']->sicar_clave);
            }
        }

        return $this->conNombresSat($filas->map(fn ($f) => $this->resolver($f['pedido'], $f['partida']))->values());
    }

    /** @return array{sat: string, sat_sugerida: string} */
    private function satExistente(SicarArticulo $a, PedidoPartida $p): array
    {
        $sicar = trim((string) $a->clave_prod_serv);
        $ia = trim((string) $p->sicar_clave_sat);

        return ['sat' => $sicar, 'sat_sugerida' => $ia !== '' && $ia !== $sicar ? $ia : ''];
    }

    /**
     * Agrega el nombre oficial de cada clave SAT (catálogo del SAT) y avisa si la clave no existe o es la genérica.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    public function conNombresSat(Collection $filas): Collection
    {
        if (! CatalogoSat::cargado()) {
            return $filas->map(fn ($f) => [...$f, 'sat_nombre' => '', 'sat_sugerida_nombre' => '']);
        }
        $nombres = (new CatalogoSat)->nombres($filas->flatMap(fn ($f) => [$f['sat'], $f['sat_sugerida']])->filter()->all());

        return $filas->map(function ($f) use ($nombres) {
            $f['sat_nombre'] = $nombres[$f['sat']] ?? '';
            $f['sat_sugerida_nombre'] = $nombres[$f['sat_sugerida']] ?? '';
            $quien = $f['estado'] === 'existe' ? 'La clave SAT que tiene SICAR' : 'La clave SAT';
            if ($f['sat'] === CatalogoSat::GENERICA) {
                $f['avisos'][] = "{$quien} es la genérica 01010101: no sirve para facturar este artículo.";
            } elseif ($f['sat'] !== '' && $f['sat_nombre'] === '') {
                $f['avisos'][] = "{$quien} ({$f['sat']}) no existe en el catálogo del SAT.";
            } elseif ($f['sat'] === '' && $f['estado'] === 'existe') {
                $f['avisos'][] = 'El artículo no tiene clave SAT en SICAR.';
            }
            if ($f['sat_sugerida'] !== '') {
                $f['avisos'][] = "La IA sugiere cambiar la clave SAT en SICAR a {$f['sat_sugerida']} ({$f['sat_sugerida_nombre']}).";
            }

            return $f;
        });
    }

    /** @return array<string, mixed> */
    public function resolver(Pedido $pedido, PedidoPartida $p): array
    {
        $datos = ['descripcion' => $p->descripcion, 'modelo' => $p->modelo, 'unidad' => $p->unidad, 'costo' => $p->costo];
        $base = ['pedido' => $pedido, 'partida' => $p, 'articulo' => null, 'avisos' => [], 'decidido' => false,
            'clave' => '', 'unidad' => '', 'categoria' => self::CATEGORIA, 'sat' => '', 'parecidos' => [], 'candidato' => null, 'similitud' => 0.0,
            // Clave SAT propuesta por la IA para un artículo que ya existe (la de SICAR puede estar mal).
            'sat_sugerida' => '', 'sat_ia' => $p->sicar_sat_ia];

        // Decisión guardada: "es el artículo X de SICAR".
        if ($p->sicar_art_id && ($a = $this->emparejador->porId((int) $p->sicar_art_id))) {
            return [...$base, ...$this->satExistente($a, $p), 'estado' => 'existe', 'decidido' => true, 'articulo' => $a, 'clave' => (string) $a->clave, 'unidad' => (string) $a->unidad,
                'avisos' => $this->avisosDe($datos, $a)];
        }

        // Decisión guardada: "es nuevo" con esta clave. Si SICAR ya la tiene (se importó y se sincronizó), ya existe.
        if ($p->sicar_clave) {
            if ($a = $this->emparejador->porClave($p->sicar_clave)) {
                return [...$base, ...$this->satExistente($a, $p), 'estado' => 'existe', 'decidido' => true, 'articulo' => $a, 'clave' => (string) $a->clave, 'unidad' => (string) $a->unidad,
                    'avisos' => $this->avisosDe($datos, $a)];
            }
            $prop = $this->emparejador->proponer($datos);

            return [...$base, 'estado' => 'nuevo', 'decidido' => true, 'clave' => $p->sicar_clave,
                'unidad' => $this->unidad($p->sicar_unidad ?: $p->unidad),
                'categoria' => $p->sicar_categoria ?: self::CATEGORIA,
                'sat' => (string) ($p->sicar_clave_sat ?: $prop['clave_sat']), 'parecidos' => $prop['parecidos']];
        }

        $r = $this->emparejador->emparejar($datos);
        if ($r['estado'] === 'existe') {
            return [...$base, ...$this->satExistente($r['articulo'], $p), 'estado' => 'existe', 'articulo' => $r['articulo'], 'clave' => (string) $r['articulo']->clave,
                'unidad' => (string) $r['articulo']->unidad, 'avisos' => $r['avisos'], 'similitud' => $r['similitud']];
        }

        $prop = $this->emparejador->proponer($datos);
        [$unidad, $avisoUnidad] = $this->unidadConAviso($p->unidad);
        $avisosNuevo = $avisoUnidad ? [$avisoUnidad] : [];
        $nuevo = ['clave' => $prop['clave'], 'unidad' => $unidad, 'sat' => (string) ($p->sicar_clave_sat ?: $prop['clave_sat']), 'parecidos' => $prop['parecidos']];
        if ($r['estado'] === 'posible') {
            return [...$base, ...$nuevo, 'estado' => 'posible', 'candidato' => $r['articulo'], 'avisos' => [...$r['avisos'], ...$avisosNuevo], 'similitud' => $r['similitud']];
        }

        return [...$base, ...$nuevo, 'estado' => 'nuevo', 'avisos' => $avisosNuevo];
    }

    /** Sinónimos → nombre canónico (en mayúsculas) de la unidad de SICAR. */
    private const SINONIMOS = [
        'PZA' => ['PZ', 'PZA', 'PZAS', 'PZS', 'PIEZA', 'PIEZAS', 'PC', 'PCS', 'UNIDAD', 'UNIDADES', 'UND', 'EA', 'EACH', 'TMO', 'TRAMO', 'SERV', 'SERVICIO'],
        'JUEGO' => ['JGO', 'JGOS', 'JUEGO', 'JUEGOS', 'SET'],
        'BOLSA' => ['BOLSA', 'BOLSAS', 'BOL', 'BSA'],
        'PAQ' => ['PAQ', 'PAQS', 'PAQUETE', 'PAQUETES', 'PQT', 'PK'],
        'CAJA' => ['CAJA', 'CAJAS', 'CJA', 'CJ', 'CAJ'],
        'METRO' => ['M', 'MT', 'MTS', 'METRO', 'METROS', 'ML'],
        'PAR' => ['PAR', 'PARES'],
        'CUBET' => ['CUBET', 'CUBETA', 'CUBETAS', 'CUB'],
        'KG' => ['KG', 'KGS', 'KILO', 'KILOS', 'KILOGRAMO', 'KILOGRAMOS'],
        'ROLLO' => ['ROLLO', 'ROLLOS', 'RLL', 'ROL'],
        'KIT' => ['KIT', 'KITS'],
        'LT' => ['LT', 'LTS', 'L', 'LITRO', 'LITROS'],
        'GALON' => ['GALON', 'GALONES', 'GAL'],
        'COMBO' => ['COMBO', 'COMBOS'],
    ];

    /** @var array<string, string>|null  MAYÚSCULAS → nombre exacto en SICAR ("BOLSA" → "bolsa"). */
    private ?array $unidadesSicar = null;

    /**
     * Nombres exactos de las unidades que usa SICAR, tomados del catálogo sincronizado. En la pantalla de
     * importación de SICAR se escribe la unidad, así que debe coincidir letra por letra.
     *
     * @return array<string, string>
     */
    public function unidadesSicar(): array
    {
        if ($this->unidadesSicar === null) {
            $this->unidadesSicar = SicarArticulo::query()->where('status', 1)->whereNotNull('unidad')->where('unidad', '!=', '')
                ->distinct()->pluck('unidad')
                ->reject(fn ($u) => ! preg_match('/[A-Za-z]/', (string) $u) || Str::upper((string) $u) === 'NA')
                ->mapWithKeys(fn ($u) => [Str::upper(Str::ascii((string) $u)) => (string) $u])->all();
        }

        return $this->unidadesSicar;
    }

    /** Unidad con el nombre exacto de SICAR; si no se reconoce, PZA. */
    public function unidad(?string $unidad): string
    {
        return $this->unidadConAviso($unidad)[0];
    }

    /** @return array{0: string, 1: ?string} unidad de SICAR y aviso si la original no existe en SICAR */
    public function unidadConAviso(?string $unidad): array
    {
        $original = trim((string) $unidad);
        $u = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($original)) ?? '');
        $canonica = null;
        foreach (self::SINONIMOS as $nombre => $variantes) {
            if (in_array($u, $variantes, true)) {
                $canonica = $nombre;
                break;
            }
        }
        $sicar = $this->unidadesSicar();
        if ($canonica === null && isset($sicar[$u])) {
            $canonica = $u;
        }

        if ($canonica === null) {
            $pza = $sicar['PZA'] ?? 'PZA';

            return [$pza, $original === '' ? null : "La unidad «{$original}» no existe en SICAR; se usará {$pza}."];
        }

        $exacta = $sicar[$canonica] ?? $canonica;
        $aviso = in_array($u, self::SINONIMOS['PZA'], true) && ! in_array($u, ['PZ', 'PZA', 'PZAS', 'PZS', 'PIEZA', 'PIEZAS', 'PC', 'PCS'], true)
            ? "La unidad «{$original}» se dará de alta como {$exacta}."
            : null;

        return [$exacta, $aviso];
    }

    /** @return list<string> */
    private function avisosDe(array $datos, SicarArticulo $a): array
    {
        return $this->emparejador->emparejar([...$datos, 'modelo' => $a->clave])['avisos'];
    }
}
