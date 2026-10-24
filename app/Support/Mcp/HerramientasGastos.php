<?php

namespace App\Support\Mcp;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Support\Nombres;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Registrar gastos desde Claude/ChatGPT (p. ej. con el comprobante del banco). Mismas reglas que la pantalla de Gastos:
 * categoría solo de la lista general, cuenta existente (no se inventan cuentas). Nunca toca los gastos de la nómina.
 * Evita duplicados de dos formas: la misma clave_gasto devuelve el gasto ya guardado (reintentos), y un gasto con la
 * misma fecha, monto y cuenta ya capturado se rechaza salvo que se confirme que es otro.
 */
final class HerramientasGastos
{
    /** Las crea el sistema (nómina y reparto): no se capturan a mano. */
    private const SOLO_SISTEMA = ['NOMINA', 'REPARTO SOCIO'];

    public static function definiciones(): array
    {
        $texto = fn (string $d, int $max = 250) => ['type' => 'string', 'description' => $d, 'maxLength' => $max];
        $categorias = array_values(array_diff(Gasto::CATEGORIAS, self::SOLO_SISTEMA));

        return [
            ['name' => 'registrar_gasto', 'title' => 'Registrar un gasto',
                'description' => 'Guarda un gasto real (MASUME o personal) en Finanzas → Gastos, igual que capturarlo en la pantalla. '
                    .'Usa una categoría de la lista y una cuenta existente (la que pagó; mira los nombres con gastos detalle o mis_finanzas). '
                    .'Contador y timbres van en CONTADOR Y TIMBRES; pagos al SAT en IMPUESTOS. '
                    .'Usa una clave_gasto distinta por cada gasto y consérvala al reintentar. Si ya hay uno con la misma fecha, monto y cuenta lo rechaza: '
                    .'confirma con el usuario y reintenta con confirmar_duplicado=true solo si de verdad es otro. Devuelve el gasto guardado.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'clave_gasto' => $texto('Identificador único de este gasto (por ejemplo el folio o clave de rastreo del comprobante). Conserva el mismo valor al reintentar.', 120),
                    'fecha' => $texto('Fecha del pago AAAA-MM-DD.', 10),
                    'monto' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Importe pagado en pesos, con IVA si lo trae.'],
                    'de' => ['type' => 'string', 'enum' => array_keys(Gasto::DE), 'description' => 'De quién es el gasto; masume por defecto.'],
                    'categoria' => ['type' => 'string', 'enum' => $categorias],
                    'descripcion' => $texto('Qué se pagó y a quién (por ejemplo "Contador sep y oct - Rodrigo Larios"). Incluye folio o referencia si sirve.', 1000),
                    'cuenta' => $texto('Nombre de la cuenta con la que se pagó, tal como está en el sistema (o sus últimos 4 dígitos si el nombre los trae).', 120),
                    'confirmar_duplicado' => ['type' => 'boolean', 'description' => 'Solo true si el usuario confirmó que es un gasto distinto a uno igual ya capturado.'],
                ], 'required' => ['clave_gasto', 'fecha', 'monto', 'categoria', 'cuenta']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
        ];
    }

    public static function maneja(string $nombre): bool
    {
        return $nombre === 'registrar_gasto';
    }

    public static function ejecutar(string $nombre, array $a): array
    {
        return match ($nombre) {
            'registrar_gasto' => self::registrar($a),
        };
    }

    private static function registrar(array $a): array
    {
        $usuario = auth()->user();
        if (! $usuario || ! $usuario->puede('gastos')) {
            throw new DomainException('Tu usuario no tiene permiso para capturar gastos.');
        }

        $categorias = array_values(array_diff(Gasto::CATEGORIAS, self::SOLO_SISTEMA));
        $a['categoria'] = mb_strtoupper(Str::squish((string) ($a['categoria'] ?? '')));
        Validator::make($a, [
            'clave_gasto' => ['required', 'string', 'max:120'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addMonth()->toDateString()],
            'monto' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'de' => ['nullable', 'in:'.implode(',', array_keys(Gasto::DE))],
            'categoria' => ['required', 'in:'.implode(',', $categorias)],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'cuenta' => ['required', 'string', 'max:120'],
        ], [
            'required' => 'Falta el campo :attribute.', 'date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'after_or_equal' => 'La fecha no es válida.', 'before_or_equal' => 'La fecha no puede ser de más de un mes adelante.',
            'numeric' => 'El monto debe ser un número.', 'gt' => 'El monto debe ser mayor a cero.',
            'in' => 'El valor de :attribute no está permitido. Categorías: '.implode(', ', $categorias).'.',
            'max' => 'El campo :attribute es demasiado largo.',
        ])->validate();

        // Reintento con la misma clave: devuelve el que ya se guardó.
        $llave = 'mcp:'.substr(hash('sha256', $usuario->id.'|'.trim((string) $a['clave_gasto'])), 0, 36);
        $ya = Gasto::withTrashed()->where('llave_importacion', $llave)->first();
        if ($ya) {
            if ($ya->trashed()) {
                throw new DomainException('Ese gasto ya se había registrado y después se borró en el sistema; no se vuelve a crear con la misma clave.');
            }

            return self::respuesta($ya, 'Ya estaba registrado (no se duplicó).');
        }

        $cuenta = self::cuenta((string) $a['cuenta']);
        $monto = round((float) $a['monto'], 2);

        $igual = Gasto::reales()->whereDate('fecha', $a['fecha'])->where('monto', $monto)->where('cuenta_id', $cuenta->id)->first();
        if ($igual && empty($a['confirmar_duplicado'])) {
            throw new DomainException(sprintf('Ya hay un gasto del %s por $%s pagado con %s (%s, "%s"). Pregunta si es otro gasto; si sí, reintenta con confirmar_duplicado=true.',
                $a['fecha'], number_format($monto, 2), $cuenta->nombre, $igual->categoria, (string) $igual->descripcion));
        }

        $gasto = DB::transaction(fn () => Gasto::create([
            'fecha' => $a['fecha'], 'monto' => $monto, 'de' => $a['de'] ?? 'masume', 'tipo' => 'gasto',
            'categoria' => $a['categoria'], 'descripcion' => filled($a['descripcion'] ?? null) ? Str::squish((string) $a['descripcion']) : null,
            'cuenta_id' => $cuenta->id, 'origen' => 'ia', 'llave_importacion' => $llave, 'user_id' => $usuario->id,
        ]));

        return self::respuesta($gasto, 'Gasto registrado.');
    }

    /** Busca la cuenta por nombre (sin acentos/mayúsculas), por parte del nombre o por sus últimos dígitos. Nunca la crea. */
    private static function cuenta(string $texto): Cuenta
    {
        $texto = trim($texto);
        $activas = Cuenta::where('activa', true)->orderBy('orden')->orderBy('nombre')->get();
        $exacta = $activas->firstWhere('nombre_llave', Nombres::llave($texto));
        if ($exacta) {
            return $exacta;
        }
        $digitos = preg_replace('/\D/', '', $texto);
        $candidatas = $activas->filter(function (Cuenta $c) use ($texto, $digitos) {
            $llave = Nombres::llave($c->nombre);

            return str_contains($llave, Nombres::llave($texto))
                || (strlen($digitos) >= 3 && str_contains(preg_replace('/\D/', '', $c->nombre), $digitos));
        })->values();
        if ($candidatas->count() === 1) {
            return $candidatas->first();
        }
        $lista = ($candidatas->isNotEmpty() ? $candidatas : $activas)->pluck('nombre')->implode(', ');

        throw new InvalidArgumentException(($candidatas->isEmpty() ? "No encontré la cuenta \"{$texto}\"." : "Hay varias cuentas que coinciden con \"{$texto}\".")
            .' Elige una de estas: '.$lista.'.');
    }

    private static function respuesta(Gasto $g, string $mensaje): array
    {
        $g->loadMissing('cuenta:id,nombre');

        return [
            'mensaje' => $mensaje, 'id' => $g->id, 'fecha' => $g->fecha->toDateString(), 'monto' => (float) $g->monto,
            'de' => $g->de, 'categoria' => $g->categoria, 'descripcion' => $g->descripcion, 'cuenta' => $g->cuenta?->nombre,
            'enlace' => url('/finanzas/gastos'),
        ];
    }
}
