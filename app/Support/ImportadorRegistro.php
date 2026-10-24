<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\Proveedor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Importa cotizaciones del REGISTRO_COTIZACIONES_MASUME (Google Sheets): hdr, det, clientes y el
 * consecutivo (CONFIG!B2). Idempotente: los folios que ya existen se saltan (las cotizaciones hechas en el
 * sistema conservan su folio). Lo usan el comando masume:importar-registro (JSON) y la pantalla
 * "Importar REGISTRO" (Excel descargado de Sheets).
 */
final class ImportadorRegistro
{
    /** Folios de prueba del sistema mientras convivía con Sheets: no cuentan para el consecutivo. */
    public const FOLIO_PRUEBAS = 90000;

    /** Folio de la cotización: solo números enteros (las "OC INTERNA" del REGISTRO traen 6194.01C). */
    public static function folio(mixed $uid): ?int
    {
        $uid = trim((string) $uid);

        return preg_match('/^\d+(\.0+)?$/', $uid) ? (int) $uid : null;
    }

    /**
     * Qué se importaría, sin tocar nada.
     *
     * @return array{nuevas: list<array>, existentes: int, ignoradas: int, partidas: int, consecutivo: int, contador: int, siguiente: int}
     */
    public static function vistaPrevia(array $datos): array
    {
        $existentes = Cotizacion::withTrashed()->pluck('folio')->flip();
        $porFolio = collect($datos['det'] ?? [])->groupBy(fn ($d) => self::folio($d['uid']) ?? 0);
        $nuevas = [];
        $yaEstan = 0;
        $ignoradas = 0;
        $vistos = [];
        foreach ($datos['hdr'] ?? [] as $h) {
            $folio = self::folio($h['uid']);
            if ($folio === null || $folio >= self::FOLIO_PRUEBAS) {
                $ignoradas++;

                continue;
            }
            if (isset($existentes[$folio]) || isset($vistos[$folio])) {
                $yaEstan++;

                continue;
            }
            $vistos[$folio] = true;
            $nuevas[] = ['folio' => $folio, 'cliente' => $h['cliente'], 'responsable' => $h['responsable'] ?? '', 'fecha' => $h['fecha'] ?? '',
                'partidas' => $porFolio->get($folio, collect())->count()];
        }
        $contador = (int) DB::table('contadores')->where('clave', 'cotizacion')->value('ultimo');

        return [
            'nuevas' => $nuevas,
            'existentes' => $yaEstan,
            'ignoradas' => $ignoradas,
            'partidas' => array_sum(array_column($nuevas, 'partidas')),
            'consecutivo' => self::consecutivo($datos),
            'contador' => $contador,
            'siguiente' => max(self::consecutivo($datos), (int) Cotizacion::withTrashed()->where('folio', '<', self::FOLIO_PRUEBAS)->max('folio')) + 1,
        ];
    }

    /** Último folio del REGISTRO: CONFIG!B2 o el mayor folio de Sheets (sin contar los de prueba ≥ 90000). */
    public static function consecutivo(array $datos): int
    {
        $maxSheets = collect($datos['hdr'] ?? [])->map(fn ($h) => self::folio($h['uid']))->filter(fn ($f) => $f !== null && $f < self::FOLIO_PRUEBAS)->max() ?? 0;

        return max((int) ($datos['consecutivo'] ?? 0), (int) $maxSheets);
    }

    /**
     * Importa las cotizaciones nuevas y, si se pide, pone el contador en el consecutivo del REGISTRO: la
     * siguiente cotización del sistema sale con el folio que sigue en Sheets.
     *
     * @return array{nuevas: int, existentes: int, siguiente: int}
     */
    public function importar(array $datos, bool $ajustarConsecutivo = true): array
    {
        $admin = User::where('rol', 'admin')->orderBy('id')->firstOrFail();
        $this->importarDirectorio($datos['clientes'] ?? []);

        $partidas = collect($datos['det'] ?? [])->groupBy(fn ($d) => self::folio($d['uid']) ?? 0);
        $existentes = Cotizacion::withTrashed()->pluck('folio')->flip();
        $nuevas = 0;
        $saltadas = 0;

        foreach (array_chunk($datos['hdr'] ?? [], 200) as $bloque) {
            DB::transaction(function () use ($bloque, $partidas, &$existentes, $admin, &$nuevas, &$saltadas) {
                foreach ($bloque as $h) {
                    $folio = self::folio($h['uid']);
                    if ($folio === null || $folio >= self::FOLIO_PRUEBAS) {
                        continue;
                    }
                    if (isset($existentes[$folio])) {
                        $saltadas++;

                        continue;
                    }
                    Bitacora::sinRegistro(fn () => $this->importarCotizacion($h, $folio, $partidas->get($folio, collect()), $admin));
                    $existentes[$folio] = true;
                    $nuevas++;
                }
            });
        }

        // Nunca hacia atrás: si ya se hicieron cotizaciones en el sistema con el consecutivo nuevo, se sigue desde la mayor.
        $ultimo = max(self::consecutivo($datos), (int) Cotizacion::withTrashed()->where('folio', '<', self::FOLIO_PRUEBAS)->max('folio'));
        $actual = (int) DB::table('contadores')->where('clave', 'cotizacion')->value('ultimo');
        if ($ajustarConsecutivo && $ultimo > 0) {
            // Se pone tal cual (aunque sea menor que los folios de prueba 90000+ ya usados en el sistema).
            DB::table('contadores')->updateOrInsert(['clave' => 'cotizacion'], ['ultimo' => $ultimo, 'updated_at' => now()]);
            $actual = $ultimo;
        }

        return ['nuevas' => $nuevas, 'existentes' => $saltadas, 'siguiente' => $actual + 1];
    }

    /** Iniciales conocidas por nombre en el REGISTRO. */
    private const INICIALES = [
        'Alberto Ramos' => 'ALBY', 'Rosa Ruiz' => 'ROSY', 'Irene Rocha' => 'ILE',
        'Ivonne Garza' => 'ISA',
    ];

    private array $clientes = [];

    private array $proveedores = [];

    private array $usuarios = [];

    private function importarDirectorio(array $filas): void
    {
        foreach ($filas as $f) {
            $cliente = $this->cliente($f['nombre']);
            if (! $cliente) {
                continue;
            }
            $cliente->direccion ??= trim($f['direccion']) ?: null;
            $correo = filter_var(trim($f['correo']), FILTER_VALIDATE_EMAIL) ?: null;
            $cliente->correo ??= $correo;
            $cliente->save();

            $contacto = trim($f['contacto']);
            if ($contacto && Nombres::llave($contacto) !== $cliente->nombre_llave
                && ! $cliente->contactos()->where('nombre', $contacto)->exists()) {
                Contacto::create(['cliente_id' => $cliente->id, 'nombre' => $contacto, 'correo' => $correo]);
            }
        }
    }

    private function importarCotizacion(array $h, int $folio, Collection $filas, User $admin): void
    {
        $cliente = $this->cliente($h['cliente']) ?? $this->cliente('SIN CLIENTE');
        $colocada = $filas->contains(fn ($d) => strtoupper((string) $d['status']) === 'COLOCADO');

        $cot = Cotizacion::create([
            'folio' => $folio,
            'cliente_id' => $cliente->id,
            'vendedor_id' => $this->usuario($h['responsable'], $admin)->id,
            'fecha' => $this->fecha($h['fecha']),
            'estado' => $colocada ? 'aceptada' : 'enviada',
            'condiciones_entrega' => config('masume.cotizacion.condiciones_entrega'),
        ]);
        $cot->forceFill(['origen' => 'registro', 'pdf_original_url' => $h['link_pdf'] ?: null])->save();

        $orden = 0;
        $importes = [];
        foreach ($filas->sortBy(fn ($d) => (float) $d['uid_linea']) as $d) {
            $cantidad = $this->num($d['cantidad']) ?? 1;
            $costo = $this->num($d['costo_sin']) ?? (($c = $this->num($d['costo_con'])) !== null ? Precios::costoSinIva($c) : null);
            $precio = $this->num($d['precio_sin']) ?? (($p = $this->num($d['precio_neto'])) !== null ? $p / (1 + Precios::iva()) : 0.0);
            $precio = round($precio, 2);
            $importe = Precios::importe($precio, $cantidad);
            $importes[] = $importe;
            $utilidad = $costo ? Precios::utilidadDesdePrecio($costo, $precio) : $this->num($d['utilidad']);
            if ($utilidad !== null && abs($utilidad) > 9999) {
                $utilidad = null;   // captura errónea en Sheets (p. ej. costo 1, precio 12,000)
            }

            CotizacionPartida::create([
                'cotizacion_id' => $cot->id,
                'orden' => ++$orden,
                'incluir' => true,
                'descripcion' => trim((string) $d['descripcion']) ?: '(sin descripción)',
                'modelo' => Str::limit(trim((string) $d['modelo']), 250, '') ?: null,
                'unidad' => Str::limit(strtoupper(trim((string) $d['um'])) ?: 'PZA', 10, ''),
                'cantidad' => $cantidad,
                'proveedor_id' => $this->proveedor($d['proveedor'])?->id,
                'proveedor_vendedor' => Str::limit(trim((string) $d['vendedor_prov']), 250, '') ?: null,
                'costo' => $costo,
                'utilidad_pct' => $utilidad,
                'precio' => $precio,
                'importe' => $importe,
                'tiempo_entrega' => Str::limit(trim((string) $d['leadtime']), 250, '') ?: null,
            ]);
        }

        $cot->forceFill(Precios::totales($importes))->save();
    }

    private function cliente(?string $nombre): ?Cliente
    {
        $nombre = trim((string) $nombre);
        $llave = Nombres::llave($nombre);
        if ($llave === '') {
            return null;
        }

        return $this->clientes[$llave] ??= $this->resolver(Cliente::class, $nombre, $llave);
    }

    private function proveedor(?string $nombre): ?Proveedor
    {
        $nombre = trim((string) $nombre);
        $llave = Nombres::llave($nombre);
        if ($llave === '') {
            return null;
        }

        return $this->proveedores[$llave] ??= $this->resolver(Proveedor::class, Str::limit($nombre, 250, ''), $llave);
    }

    /**
     * Busca el cliente/proveedor sin duplicarlo:
     * 1) el que existe con ese nombre; si se unió a otro en Duplicados, el que se quedó;
     * 2) uno activo que difiere en una o dos letras ("MAXIFERO" → MAXIFERRO);
     * 3) si no hay ninguno, se da de alta.
     */
    private function resolver(string $clase, string $nombre, string $llave): Cliente|Proveedor
    {
        $existente = $clase::withTrashed()->where('nombre_llave', $llave)->first();
        for ($i = 0; $existente && $existente->trashed() && $existente->unido_a_id && $i < 10; $i++) {
            $existente = $clase::withTrashed()->find($existente->unido_a_id) ?? $existente;
        }
        if ($existente && ! $existente->trashed()) {
            return $existente;
        }

        // Si estaba borrado sin saber a cuál se unió (uniones de antes), también vale "empieza con"
        // ("PROVEX CARLOS" → PROVEX), como los propone la pantalla de Duplicados.
        $base = UnificarDuplicados::base($nombre);
        $parecido = $clase::query()->get(['id', 'nombre'])->first(function ($c) use ($base, $existente) {
            $otra = UnificarDuplicados::base($c->nombre);
            [$corto, $largo] = strlen($otra) <= strlen($base) ? [$otra, $base] : [$base, $otra];

            return $otra === $base || UnificarDuplicados::errorDeDedo($base, $otra)
                || ($existente && strlen($corto) >= 5 && str_starts_with($largo, $corto));
        });
        if ($parecido) {
            return $clase::find($parecido->id);
        }

        if ($existente) {
            // Borrado (no unido) y sin otro parecido: se reactiva en vez de crear otro igual.
            $existente->restore();

            return $existente;
        }

        return $clase::create(['nombre' => $nombre]);
    }

    /** Vendedores del REGISTRO: se crean inactivos; el admin les pone correo real y contraseña. */
    private function usuario(?string $nombre, User $admin): User
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '' || in_array(strtoupper($nombre), ['ENTREGA', 'MOSTRADOR', 'DANIEL PÉREZ'], true)) {
            return $admin;
        }

        return $this->usuarios[$nombre] ??= User::whereIn('name', [$nombre, $nombre.User::SUFIJO_ELIMINADO])->first() ?? User::create([
            'name' => $nombre,
            'email' => Str::slug($nombre, '.').'@masume.local',
            'password' => Hash::make(Str::random(40)),
            'rol' => 'vendedor',
            'iniciales' => self::INICIALES[$nombre] ?? strtoupper(Str::substr($nombre, 0, 4)),
            'activo' => false,
        ]);
    }

    private function fecha(?string $texto): string
    {
        try {
            return Carbon::createFromFormat('d/m/Y', trim((string) $texto))->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }

    private function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $v = str_replace(['$', ',', ' ', '%'], '', (string) $v);

        return is_numeric($v) ? (float) $v : null;
    }
}
