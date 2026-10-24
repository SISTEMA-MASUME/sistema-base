<?php

namespace App\Services\Ia;

/** Lee una OC de cliente (PDF o imagen) y devuelve sus renglones como JSON. Solo extrae; no decide nada. */
interface ExtractorOc
{
    /**
     * Inicia la extracción (asíncrona) y devuelve el id de la respuesta.
     *
     * @throws ExtractorOcException
     */
    public function iniciar(string $ruta, string $mime, string $contexto): string;

    /**
     * Consulta el avance. `estado` es procesando | completada | error.
     * `uso` trae tokens_entrada, tokens_salida, tokens_cache y tokens_razonamiento.
     *
     * @return array{estado: string, datos?: array, uso?: array, error?: string}
     *
     * @throws ExtractorOcException
     */
    public function consultar(string $id): array;
}
