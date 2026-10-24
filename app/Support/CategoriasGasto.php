<?php

namespace App\Support;

use App\Models\Gasto;
use Illuminate\Support\Str;

/**
 * Ordena las categorías de gastos en la lista general (`Gasto::CATEGORIAS`). Las del Excel viejo traían de todo (CARLS,
 * JJ, KFC, GSOLINA…): aquí se adivina a cuál general pertenece cada una con palabras clave sobre el texto sin acentos
 * y con tolerancia a errores de dedo. Lo que no se reconoce va a GASTOS OPERATIVOS marcado como "revisa" (segura = false).
 */
class CategoriasGasto
{
    public const GENERICA = 'GASTOS OPERATIVOS';

    /**
     * Reglas en orden: gana la primera que coincide. [categoría general, regex sobre el texto normalizado, ¿segura?].
     * Los \b son importantes: "LAP" no debe encontrarse dentro de "PLAZA".
     */
    private const REGLAS = [
        ['REPARTO SOCIO', '/^REPARTO SOCIO\b/', true],
        ['PAGO TARJETAS', '/^PAGO (?:DE )?TARJETA/', true],
        ['PAGO PRESTAMO', '/^PAGO (?:DE )?PRESTAMO/', true],
        ['NOMINA', '/\b(?:NOMINA|SUELDO|AGUINALDO)/', true],
        ['MANTENIMIENTO VEHICULOS', '/\b(?:REPARACION|REVISION) RAM\b|\bBATERIA/', true],
        ['COMPRA DE VEHICULOS Y EQUIPO', '/^RAM 700$/', true],
        ['SEGUROS', '/\bSEGURO/', true],
        ['GASOLINA', '/GASOLINA.*\b(?:OXXO|COMIDA)|\b(?:OXXO|COMIDA).*GASOLINA/', false],   // "GASOLINA/OXXO/COMIDA": mezcla, Daniel la revisa
        ['GASOLINA', '/\bGASOLINA/', true],
        ['ALIMENTOS', '/\b(?:OXXO|GALLETA|COMIDA|CARLS|KFC|KEVIN|PIZZA|BARBACOA|CARNE ASADA|DIDI FOOD|REFRESCO|PASTEL|TIENDA|ALIMENTO|DESAYUNO)/', true],
        ['MANTENIMIENTO VEHICULOS', '/\bRAM\b|\b(?:LLANTA|ACEITE|ANTICONGELANTE|MECANICO|LAVADA|ASIENTO|ESTEREO)/', true],
        ['IMPUESTOS', '/\b(?:SAT|IMPUESTO)/', true],
        ['CONTADOR Y TIMBRES', '/\b(?:CONTADOR|TIMBRE)/', true],
        ['TRANSPORTE Y ENVIOS', '/\b(?:UBER|DIDI|TAXI|CASETA|ESTACIONAMIENTO|ENVIO|CORREO|TRANSPORTE|FLETE)/', true],
        ['REPARACIONES', '/\b(?:REPARACION|ELECTRICO|PINTURA|INSTALACION|MANO DE OBRA|BOMBA|VIDRIO)/', true],
        ['PUBLICIDAD', '/\b(?:PLAYERA|PROMOCIONAL|PUBLICIDAD|LONAS?\b|VINIL|PAGINA WEB|CATALOGO|TARJETAS?\b)/', true],
        ['COMPUTO Y PAPELERIA', '/\b(?:COMPU|LAP\b|CARTUCHO|TONER|CONSUMIBLE|PAPELERIA)/', true],
        ['SERVICIOS', '/\b(?:INTERNET|MEGACABLE|CELULAR|RECARGA)/', true],
        ['SUBSCRIPCIONES', '/\b(?:SUBSCRIPCION|SUSCRIPCION)/', true],
        ['PERSONAL Y EVENTOS', '/\b(?:POSADA|REGALO|CURSO|BOTAS|CURACION|GASTOS MED)/', true],
    ];

    /** Palabras (de 5 letras o más) contra las que se corrigen los errores de dedo: GSOLINA → GASOLINA, ACIETE → ACEITE. */
    private const VOCABULARIO = ['GASOLINA', 'REPARACION', 'REPARACIONES', 'ACEITE', 'ANTICONGELANTE', 'MECANICO', 'ESTEREO', 'COMIDA',
        'BARBACOA', 'REFRESCO', 'PASTEL', 'TIENDA', 'GALLETAS', 'PUBLICIDAD', 'PROMOCIONALES', 'CATALOGO', 'PAPELERIA', 'CARTUCHOS',
        'TONER', 'CONSUMIBLES', 'INTERNET', 'MEGACABLE', 'CELULAR', 'RECARGA', 'POSADA', 'REGALOS', 'CURACION', 'ESTACIONAMIENTO',
        'TRANSPORTE', 'CASETAS', 'CONTADOR', 'TIMBRES', 'IMPUESTOS', 'NOMINA', 'SUELDO', 'AGUINALDO', 'ELECTRICO', 'PINTURA',
        'INSTALACION', 'VIDRIOS', 'PLAYERAS', 'SEGURO', 'BATERIA', 'LLANTAS', 'REVISION', 'ASIENTO', 'LAVADA', 'PIZZA', 'ENVIO',
        'CORREO', 'BOMBA', 'PAPELERIA', 'ALIMENTOS', 'SEGUROS', 'SUBSCRIPCIONES', 'SUSCRIPCIONES', 'OPERATIVOS', 'PRESTAMO', 'TARJETAS'];

    /**
     * @return array{categoria: ?string, segura: bool} segura = false: Daniel debe revisar la sugerencia.
     */
    public static function sugerir(?string $categoria, ?string $descripcion = null): array
    {
        $texto = self::normalizar($categoria);
        if ($texto !== '') {
            return self::deTexto($texto) ?? ['categoria' => self::GENERICA, 'segura' => false];
        }

        // Sin categoría: se intenta con la descripción; como es una adivinanza más débil, siempre queda por revisar.
        $porDescripcion = self::deTexto(self::normalizar($descripcion));

        return $porDescripcion ? ['categoria' => $porDescripcion['categoria'], 'segura' => false] : ['categoria' => null, 'segura' => false];
    }

    /** Texto en mayúsculas, sin acentos ni espacios dobles. */
    public static function normalizar(?string $texto): string
    {
        return Str::upper(Str::squish(Str::ascii((string) $texto)));
    }

    /** @return ?array{categoria: string, segura: bool} null si no se reconoce */
    private static function deTexto(string $texto): ?array
    {
        if ($texto === '') {
            return null;
        }
        if (in_array($texto, Gasto::CATEGORIAS, true)) {
            return ['categoria' => $texto, 'segura' => true];
        }

        $corregido = self::corregir($texto);
        foreach (self::REGLAS as [$categoria, $patron, $segura]) {
            if (preg_match($patron, $corregido)) {
                return ['categoria' => $categoria, 'segura' => $segura];
            }
        }
        // Texto corto y parecido a una categoría general completa ("GASTOS OPERATIVO", "ALIMENTO").
        foreach (Gasto::CATEGORIAS as $general) {
            if (levenshtein($corregido, $general) <= 2) {
                return ['categoria' => $general, 'segura' => true];
            }
        }

        return null;
    }

    /** Cambia cada palabra con error de dedo por la palabra correcta del vocabulario. */
    private static function corregir(string $texto): string
    {
        $palabras = preg_split('/\s+/', $texto) ?: [];
        foreach ($palabras as $i => $palabra) {
            $limpia = preg_replace('/[^A-Z0-9]/', '', $palabra) ?? '';
            $largo = strlen($limpia);
            if ($largo < 5 || in_array($limpia, self::VOCABULARIO, true)) {
                continue;
            }
            $mejor = null;
            $mejorDistancia = 99;
            foreach (self::VOCABULARIO as $correcta) {
                if (abs(strlen($correcta) - $largo) > 2) {
                    continue;
                }
                $d = levenshtein($limpia, $correcta);
                // Dos letras de diferencia solo en palabras largas; en cortas, una (o dos letras juntas cambiadas de lugar).
                $maximo = $largo >= 7 ? 2 : 1;
                if ($d > $maximo && self::unaPermuta($limpia, $correcta)) {
                    $d = 1;
                }
                if ($d <= $maximo && $d < $mejorDistancia) {
                    $mejor = $correcta;
                    $mejorDistancia = $d;
                }
            }
            if ($mejor !== null) {
                $palabras[$i] = str_replace($limpia, $mejor, $palabra);
            }
        }

        return implode(' ', $palabras);
    }

    /** ¿Son iguales intercambiando dos letras vecinas? (ACIETE ↔ ACEITE) */
    private static function unaPermuta(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        for ($i = 0; $i < strlen($a) - 1; $i++) {
            $x = $a;
            [$x[$i], $x[$i + 1]] = [$x[$i + 1], $x[$i]];
            if ($x === $b) {
                return true;
            }
        }

        return false;
    }
}
