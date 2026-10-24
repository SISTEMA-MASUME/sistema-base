<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Proveedor;
use App\Models\ProveedorContacto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Une clientes o proveedores duplicados en uno solo (el "principal"), sin perder datos.
 * En Sheets se partía un cliente para distinguir a quién se le mandaba ("PROVEX CARLOS"); aquí ese
 * sobrante del nombre se vuelve un contacto del principal y se le ligan sus cotizaciones.
 */
class UnificarDuplicados
{
    public const TIPOS = ['clientes' => Cliente::class, 'proveedores' => Proveedor::class];

    /** Terminaciones de razón social que no distinguen a una empresa. */
    private const SUFIJOS = ['SAPIDECV', 'SDERLDECV', 'SADECV', 'SDERL', 'SCDERL', 'SAB', 'SAPI', 'SPR', 'SC', 'SA', 'AC'];

    private const CAMPOS = [
        'clientes' => ['razon_social', 'rfc', 'direccion', 'telefono', 'correo', 'portal_url'],
        'proveedores' => ['telefono', 'correo'],
    ];

    /** Llave para comparar: la de Nombres sin "S.A. de C.V." y similares al final. */
    public static function base(string $nombre): string
    {
        $llave = Nombres::llave($nombre);
        foreach (self::SUFIJOS as $s) {
            if (strlen($llave) > strlen($s) + 3 && str_ends_with($llave, $s)) {
                return substr($llave, 0, -strlen($s));
            }
        }

        return $llave;
    }

    /**
     * Ordena ids del más usado al menos usado (el primero se propone como principal).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function ordenarPorUso(string $tipo, array $ids): array
    {
        $uso = self::uso($tipo, $ids);
        usort($ids, fn ($a, $b) => ($uso[$b] ?? 0) <=> ($uso[$a] ?? 0));

        return $ids;
    }

    /** Dos bases que difieren en una o dos letras ("MAXIFERRO" / "MAXIFERO"). */
    public static function errorDeDedo(string $a, string $b): bool
    {
        $corto = min(strlen($a), strlen($b));

        return $a !== $b && $corto >= 6 && abs(strlen($a) - strlen($b)) <= 2 && levenshtein($a, $b) <= ($corto >= 10 ? 2 : 1);
    }

    /**
     * Grupos de posibles duplicados: nombres iguales sin la razón social, uno que empieza con el otro
     * ("PROVEX" / "PROVEX CARLOS") o que difieren en una o dos letras (errores de dedo).
     *
     * @return list<list<int>> ids, el más usado primero
     */
    public static function grupos(string $tipo): array
    {
        $modelos = self::TIPOS[$tipo]::query()->get(['id', 'nombre']);
        $bases = $modelos->mapWithKeys(fn ($m) => [$m->id => self::base($m->nombre)])->filter(fn ($b) => strlen($b) >= 4);
        $ids = $bases->keys()->all();

        $padre = array_combine($ids, $ids);
        $raiz = function ($x) use (&$padre, &$raiz) {
            return $padre[$x] === $x ? $x : ($padre[$x] = $raiz($padre[$x]));
        };
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $a = $bases[$ids[$i]];
            for ($j = $i + 1; $j < $n; $j++) {
                $b = $bases[$ids[$j]];
                [$corto, $largo] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
                $parecidos = $a === $b
                    || (strlen($corto) >= 5 && str_starts_with($largo, $corto))
                    || self::errorDeDedo($a, $b);
                if ($parecidos) {
                    $padre[$raiz($ids[$i])] = $raiz($ids[$j]);
                }
            }
        }

        $uso = self::uso($tipo, $ids);
        $grupos = [];
        foreach ($ids as $id) {
            $grupos[$raiz($id)][] = $id;
        }

        return collect($grupos)->filter(fn ($g) => count($g) > 1)
            ->map(fn ($g) => collect($g)->sortByDesc(fn ($id) => $uso[$id] ?? 0)->values()->all())
            ->sortBy(fn ($g) => $modelos->firstWhere('id', $g[0])->nombre)
            ->values()->all();
    }

    /** Cuántas cotizaciones (clientes) o partidas cotizadas (proveedores) tiene cada uno. */
    private static function uso(string $tipo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $tipo === 'clientes'
            ? DB::table('cotizaciones')->whereIn('cliente_id', $ids)->groupBy('cliente_id')->selectRaw('cliente_id as id, count(*) as n')->pluck('n', 'id')->all()
            : DB::table('cotizacion_partidas')->whereIn('proveedor_id', $ids)->groupBy('proveedor_id')->selectRaw('proveedor_id as id, count(*) as n')->pluck('n', 'id')->all();
    }

    /** Lo que sobra del nombre del duplicado frente al principal ("PROVEX CARLOS" → "Carlos"). */
    public static function contactoDelNombre(string $principal, string $duplicado): ?string
    {
        $p = Nombres::limpiar($principal);
        $d = Nombres::limpiar($duplicado);
        if (! str_starts_with(Nombres::llave($d), Nombres::llave($p))) {
            return null;
        }
        // Se quitan las palabras del principal y lo que quede con letras es la persona.
        $resto = trim(preg_replace('/^'.preg_quote($p, '/').'/u', '', $d), ' .,-_;:/()');
        if ($resto === '' || $resto === $d || ! preg_match('/\pL{3,}/u', $resto) || mb_strlen($resto) > 40) {
            return null;
        }

        return Str::title(mb_strtolower($resto));
    }

    /**
     * @param  list<int>  $duplicados
     * @return list<array{id: int, nombre: string, mueve: string, contacto: ?string, conflictos: list<string>}>
     */
    public static function vistaPrevia(string $tipo, int $principalId, array $duplicados): array
    {
        $principal = self::TIPOS[$tipo]::findOrFail($principalId);
        $otros = self::TIPOS[$tipo]::whereIn('id', $duplicados)->where('id', '!=', $principalId)->orderBy('nombre')->get();
        $llaves = $tipo === 'clientes'
            ? DB::table('pedidos')->where('cliente_id', $principalId)->whereNotNull('numero_oc_llave')->pluck('numero_oc_llave')->flip()->all()
            : [];

        $filas = [];
        foreach ($otros as $o) {
            $conflictos = [];
            if ($tipo === 'clientes') {
                foreach (DB::table('pedidos')->where('cliente_id', $o->id)->whereNotNull('numero_oc_llave')->get(['folio', 'numero_oc_cliente', 'numero_oc_llave']) as $p) {
                    if (isset($llaves[$p->numero_oc_llave])) {
                        $conflictos[] = "La OC {$p->numero_oc_cliente} (P-{$p->folio}) ya existe en el cliente principal.";
                    }
                    $llaves[$p->numero_oc_llave] = true;
                }
                $mueve = DB::table('cotizaciones')->where('cliente_id', $o->id)->count().' cotizaciones, '
                    .DB::table('pedidos')->where('cliente_id', $o->id)->count().' pedidos, '
                    .DB::table('contactos')->where('cliente_id', $o->id)->count().' contactos';
            } else {
                $mueve = DB::table('cotizacion_partidas')->where('proveedor_id', $o->id)->count().' partidas cotizadas, '
                    .DB::table('ordenes_proveedor')->where('proveedor_id', $o->id)->count().' OC a proveedor, '
                    .DB::table('proveedor_contactos')->where('proveedor_id', $o->id)->count().' contactos';
            }
            $filas[] = [
                'id' => $o->id,
                'nombre' => $o->nombre,
                'mueve' => $mueve,
                'contacto' => self::contactoDelNombre($principal->nombre, $o->nombre),
                'conflictos' => $conflictos,
            ];
        }

        return $filas;
    }

    /**
     * @param  list<int>  $duplicados
     * @return int cuántos se unieron
     */
    public static function unir(string $tipo, int $principalId, array $duplicados): int
    {
        $previa = self::vistaPrevia($tipo, $principalId, $duplicados);
        abort_if($previa === [], 422, 'Elige al menos un duplicado.');
        abort_if(collect($previa)->contains(fn ($f) => $f['conflictos'] !== []), 422, 'Hay OC repetidas; revísalas antes de unir.');

        return DB::transaction(function () use ($tipo, $principalId, $previa) {
            $principal = self::TIPOS[$tipo]::lockForUpdate()->findOrFail($principalId);
            foreach ($previa as $fila) {
                $o = self::TIPOS[$tipo]::findOrFail($fila['id']);
                $tipo === 'clientes' ? self::moverCliente($principal, $o, $fila['contacto']) : self::moverProveedor($principal, $o, $fila['contacto']);
                self::completarDatos($tipo, $principal, $o);
                $o->forceFill(['unido_a_id' => $principal->id])->save();
                $o->delete();
            }
            $principal->save();

            return count($previa);
        });
    }

    private static function moverCliente(Cliente $p, Cliente $o, ?string $persona): void
    {
        // La persona del nombre ("PROVEX CARLOS") queda como contacto y se liga a sus cotizaciones sin contacto.
        $contactoId = null;
        if ($persona) {
            $contactoId = Contacto::firstOrCreate(
                ['cliente_id' => $p->id, 'nombre' => $persona],
                ['correo' => $o->correo, 'telefono' => $o->telefono],
            )->id;
            DB::table('cotizaciones')->where('cliente_id', $o->id)->whereNull('contacto_id')->update(['contacto_id' => $contactoId]);
        }
        DB::table('cotizaciones')->where('cliente_id', $o->id)->update(['cliente_id' => $p->id]);
        DB::table('pedidos')->where('cliente_id', $o->id)->update(['cliente_id' => $p->id]);
        DB::table('contactos')->where('cliente_id', $o->id)->update(['cliente_id' => $p->id]);
        DB::table('cliente_sucursales')->where('cliente_id', $o->id)->update(['cliente_id' => $p->id]);
        // Razones sociales y "No" de ligas: pasan al principal sin repetir la misma llave (único por cliente + llave).
        foreach (['cliente_razones_sociales', 'ligas_rechazadas'] as $tabla) {
            $yaTiene = DB::table($tabla)->where('cliente_id', $p->id)->pluck('nombre_llave')->flip();
            foreach (DB::table($tabla)->where('cliente_id', $o->id)->get(['id', 'nombre_llave']) as $fila) {
                if ($yaTiene->has($fila->nombre_llave)) {
                    DB::table($tabla)->where('id', $fila->id)->delete();
                } else {
                    DB::table($tabla)->where('id', $fila->id)->update(['cliente_id' => $p->id]);
                }
            }
        }
        foreach (['oc_despues_de_facturar', 'sube_factura_portal', 'envia_evidencia_por_correo'] as $bandera) {
            $p->$bandera = $p->$bandera || $o->$bandera;
        }
    }

    private static function moverProveedor(Proveedor $p, Proveedor $o, ?string $persona): void
    {
        DB::table('cotizacion_partidas')->where('proveedor_id', $o->id)->update(['proveedor_id' => $p->id]);
        DB::table('pedido_partidas')->where('proveedor_id', $o->id)->update(['proveedor_id' => $p->id]);
        DB::table('ordenes_proveedor')->where('proveedor_id', $o->id)->update(['proveedor_id' => $p->id]);
        DB::table('proveedor_contactos')->where('proveedor_id', $o->id)->update(['proveedor_id' => $p->id, 'principal' => false]);
        Articulos::moverProveedor($o->id, $p->id);
        // El contacto que tenía el duplicado (o la persona de su nombre) queda como contacto del principal.
        $nombre = $o->contacto ?: $persona;
        if ($nombre && ! ProveedorContacto::where('proveedor_id', $p->id)->where('nombre', $nombre)->exists()) {
            ProveedorContacto::create(['proveedor_id' => $p->id, 'nombre' => $nombre, 'telefono' => $o->telefono, 'correo' => $o->correo,
                'principal' => ! ProveedorContacto::where('proveedor_id', $p->id)->exists()]);
        }
    }

    /** Llena los datos vacíos del principal; los que no coinciden se guardan en sus notas. */
    private static function completarDatos(string $tipo, Cliente|Proveedor $p, Cliente|Proveedor $o): void
    {
        $diferentes = [];
        foreach (self::CAMPOS[$tipo] as $campo) {
            $valor = trim((string) $o->$campo);
            if ($valor === '') {
                continue;
            }
            if (trim((string) $p->$campo) === '') {
                $p->$campo = $valor;
            } elseif (mb_strtolower(trim((string) $p->$campo)) !== mb_strtolower($valor)) {
                $diferentes[] = str_replace('_', ' ', $campo).': '.$valor;
            }
        }
        if (filled($o->notas)) {
            $diferentes[] = 'notas: '.$o->notas;
        }
        if ($diferentes) {
            $p->notas = trim(((string) $p->notas)."\n\nDe \"{$o->nombre}\" (unificado ".now()->format('d/m/Y')."):\n".implode("\n", $diferentes));
        }
    }

    /** @return Collection<int, Cliente|Proveedor> */
    public static function modelos(string $tipo, array $ids): Collection
    {
        return self::TIPOS[$tipo]::whereIn('id', $ids)->get()->keyBy('id');
    }
}
