<?php

namespace Tests\Unit;

use App\Services\ConciliadorOc;
use Tests\TestCase;

class ConciliadorOcTest extends TestCase
{
    private function renglon(array $o = []): array
    {
        return array_merge([
            'num_renglon' => 1, 'codigo_cliente' => null, 'descripcion' => 'ARTICULO', 'marca' => null, 'modelo' => null,
            'cantidad' => 1, 'unidad' => 'PZA', 'precio_unitario' => null, 'importe' => null, 'fecha_entrega' => null,
            'origen' => 'tabla', 'confianza' => 'alta', 'texto_original' => '',
        ], $o);
    }

    private function partida(int $id, array $o = []): array
    {
        return array_merge([
            'id' => $id, 'descripcion' => 'ARTICULO', 'modelo' => null, 'cantidad' => 1, 'unidad' => 'PZA', 'precio' => null,
        ], $o);
    }

    private function ext(array $renglones, array $o = []): array
    {
        return array_merge(['numero_oc' => null, 'precios_incluyen_iva' => null, 'renglones' => $renglones,
            'subtotal' => null, 'iva' => null, 'total' => null, 'advertencias' => []], $o);
    }

    private function conciliar(array $renglones, array $partidas, array $o = [], ?string $oc = null, array $resol = []): array
    {
        return (new ConciliadorOc)->conciliar($this->ext($renglones, $o), $partidas, $oc, $resol);
    }

    public function test_normaliza_modelo_ignorando_formato(): void
    {
        $this->assertSame('MR16LED55W', ConciliadorOc::normalizarModelo('MR16-LED/5.5W'));
        $this->assertSame('MR16LED55W', ConciliadorOc::normalizarModelo('mr16 led 5.5w'));
        $this->assertSame('', ConciliadorOc::normalizarModelo(null));
    }

    public function test_tokens_sin_acentos_ni_stopwords_y_conservan_medidas(): void
    {
        $t = ConciliadorOc::tokens('Lámpara de LED para 1/2" x 5.5W (3000K)');
        $this->assertContains('lampara', $t);
        $this->assertContains('1/2', $t);
        $this->assertContains('5.5w', $t);
        $this->assertContains('3000k', $t);
        $this->assertNotContains('de', $t);
        $this->assertNotContains('para', $t);
    }

    public function test_unidades_sinonimas(): void
    {
        foreach ([['PZA', 'PIEZAS'], ['PZ', 'PZAS'], ['CJA', 'CAJA'], ['PAQ', 'PAQUETE'], ['KG', 'KILO'], ['M', 'MTS'], ['MT', 'METRO'], ['LT', 'LITRO'], ['L', 'LT'], ['JGO', 'JUEGO'], ['ROLLO', 'RLL']] as [$a, $b]) {
            $this->assertSame(ConciliadorOc::unidadCanonica($a), ConciliadorOc::unidadCanonica($b), "$a vs $b");
        }
        $this->assertNotSame(ConciliadorOc::unidadCanonica('PZA'), ConciliadorOc::unidadCanonica('CAJA'));
    }

    public function test_modelo_exacto_con_distinto_formato_coincide(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'FOCO', 'modelo' => 'MR16-LED/5.5W', 'cantidad' => 4])],
            [$this->partida(1, ['descripcion' => 'LAMPARA DICROICA COMPLETAMENTE DISTINTA', 'modelo' => 'MR16 LED 5.5W', 'cantidad' => 4])]
        );
        $this->assertSame('coincide', $r['filas'][0]['estado']);
        $this->assertSame('modelo', $r['filas'][0]['metodo']);
        $this->assertFalse($r['hay_diferencias']);
        $this->assertSame('1 coincide', $r['resumen']);
    }

    /** P-58 Central: la OC dice solo "Estopa · 5 Pieza · $53.88" y el pedido la descripción completa. */
    public function test_descripcion_corta_del_cliente_dentro_de_la_nuestra_con_misma_cantidad_y_precio_coincide(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'Estopa', 'cantidad' => 5, 'unidad' => 'Pieza', 'precio_unitario' => 53.88])],
            [$this->partida(58, ['descripcion' => 'BOLSA DE 1 KG DE ESTOPA COLOR BLANCA, KLINTEK 57000', 'modelo' => '57000', 'cantidad' => 5, 'unidad' => 'BOLSA', 'precio' => 53.88])],
            ['precios_incluyen_iva' => false]
        );
        $this->assertCount(1, $r['filas']);
        $this->assertSame('coincide', $r['filas'][0]['estado']);
        $this->assertSame('contenido', $r['filas'][0]['metodo']);
        $this->assertFalse($r['hay_diferencias']);
    }

    public function test_descripcion_corta_con_otra_cantidad_se_empareja_pero_avisa(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'Estopa', 'cantidad' => 8, 'unidad' => 'Pieza', 'precio_unitario' => 53.88])],
            [$this->partida(58, ['descripcion' => 'BOLSA DE 1 KG DE ESTOPA COLOR BLANCA', 'cantidad' => 5, 'unidad' => 'BOLSA', 'precio' => 53.88])]
        );
        $this->assertCount(1, $r['filas']);
        $this->assertSame('cantidad_distinta', $r['filas'][0]['estado']);
        $this->assertContains('dudoso', $r['filas'][0]['diferencias']);
    }

    public function test_sin_palabras_ni_cantidad_ni_precio_en_comun_no_se_empareja(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'Guantes', 'cantidad' => 3, 'precio_unitario' => 80])],
            [$this->partida(1, ['descripcion' => 'BOLSA DE ESTOPA', 'cantidad' => 5, 'precio' => 53.88])]
        );
        $this->assertCount(2, $r['filas']);
        $this->assertSame(['falta_en_pedido', 'sobra_en_pedido'], array_column($r['filas'], 'estado'));
    }

    public function test_descripciones_distintas_con_misma_cantidad_y_precio_se_emparejan_como_dudosas(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'Material de limpieza', 'cantidad' => 5, 'precio_unitario' => 53.88])],
            [$this->partida(1, ['descripcion' => 'BOLSA DE ESTOPA', 'cantidad' => 5, 'precio' => 53.88])]
        );
        $this->assertCount(1, $r['filas']);
        $this->assertSame('dudoso', $r['filas'][0]['estado']);
    }

    public function test_precio_de_centavos_distinto_se_nota_y_el_redondeo_no(): void
    {
        $pija = fn (float $precioOc) => $this->conciliar(
            [$this->renglon(['descripcion' => 'PIJA PUNTA DE BROCA 8X1', 'cantidad' => 200, 'precio_unitario' => $precioOc])],
            [$this->partida(1, ['descripcion' => 'PIJA PUNTA DE BROCA 8X1', 'cantidad' => 200, 'precio' => 0.28])]
        );
        $this->assertStringContainsString('precio_distinto', json_encode($pija(0.21)['filas']));
        $this->assertStringNotContainsString('precio_distinto', json_encode($pija(0.28)['filas']));
        $this->assertStringNotContainsString('precio_distinto', json_encode($pija(0.2846)['filas']));   // redondeo
    }

    public function test_daido_cantidad_distinta(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'LAMPARA XXXX', 'modelo' => 'XXXX', 'cantidad' => 10])],
            [$this->partida(7, ['descripcion' => 'LAMPARA XXXX', 'modelo' => 'XXXX', 'cantidad' => 1])]
        );
        $f = $r['filas'][0];
        $this->assertSame('cantidad_distinta', $f['estado']);
        $this->assertSame(['cantidad_distinta'], $f['diferencias']);
        $this->assertStringContainsString('OC 10', $f['detalles']['cantidad_distinta']);
        $this->assertTrue($r['hay_diferencias']);
    }

    public function test_falta_y_sobra(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'TALADRO PERCUTOR', 'modelo' => 'TP1']), $this->renglon(['descripcion' => 'CINTA METRICA 5 M', 'modelo' => 'CM5'])],
            [$this->partida(1, ['descripcion' => 'TALADRO PERCUTOR', 'modelo' => 'TP1']), $this->partida(2, ['descripcion' => 'MARTILLO', 'modelo' => 'MZ'])]
        );
        $por = collect($r['filas'])->keyBy('clave');
        $this->assertSame('coincide', $por['r0']['estado']);
        $this->assertSame('falta_en_pedido', $por['r1']['estado']);
        $this->assertSame('sobra_en_pedido', $por['p2']['estado']);
        $this->assertSame('1 coincide, 1 falta en el pedido, 1 sobra en el pedido', $r['resumen']);
    }

    public function test_unidad_distinta_y_sinonimos(): void
    {
        $sinon = $this->conciliar([$this->renglon(['modelo' => 'A1', 'unidad' => 'PIEZAS'])], [$this->partida(1, ['modelo' => 'A1', 'unidad' => 'PZA'])]);
        $this->assertSame('coincide', $sinon['filas'][0]['estado']);

        $dist = $this->conciliar([$this->renglon(['modelo' => 'A1', 'unidad' => 'CAJA'])], [$this->partida(1, ['modelo' => 'A1', 'unidad' => 'PZA'])]);
        $this->assertSame('unidad_distinta', $dist['filas'][0]['estado']);
    }

    public function test_precio_con_tolerancia_uno_por_ciento_o_un_peso(): void
    {
        $p = [$this->partida(1, ['modelo' => 'A1', 'precio' => 100])];
        $this->assertSame('coincide', $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 100.9])], $p)['filas'][0]['estado']);
        $this->assertSame('precio_distinto', $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 102])], $p)['filas'][0]['estado']);

        $caro = [$this->partida(1, ['modelo' => 'A1', 'precio' => 5000])];
        $this->assertSame('coincide', $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 5040])], $caro)['filas'][0]['estado']);
        $this->assertSame('precio_distinto', $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 5060])], $caro)['filas'][0]['estado']);
    }

    public function test_precio_de_la_oc_con_iva_se_compara_contra_precio_por_116(): void
    {
        $p = [$this->partida(1, ['modelo' => 'A1', 'precio' => 100])];
        $con = $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 116])], $p, ['precios_incluyen_iva' => true]);
        $this->assertSame('coincide', $con['filas'][0]['estado']);

        $sin = $this->conciliar([$this->renglon(['modelo' => 'A1', 'precio_unitario' => 116])], $p, ['precios_incluyen_iva' => false]);
        $this->assertSame('precio_distinto', $sin['filas'][0]['estado']);
    }

    public function test_sin_precio_en_la_oc_no_hay_precio_distinto(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['modelo' => 'A1', 'precio_unitario' => null, 'origen' => 'observaciones'])],
            [$this->partida(1, ['modelo' => 'A1', 'precio' => 100])]
        );
        $this->assertSame('coincide', $r['filas'][0]['estado']);
        $this->assertSame('observaciones', $r['filas'][0]['renglon']['origen']);
    }

    public function test_empareja_por_descripcion_cuando_no_hay_modelo(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'Tornillo hexagonal 1/2 x 3 grado 5'])],
            [$this->partida(1, ['descripcion' => 'TORNILLO HEXAGONAL 1/2 X 3 GRADO 5 GALVANIZADO'])]
        );
        $this->assertSame('coincide', $r['filas'][0]['estado']);
        $this->assertSame('descripcion', $r['filas'][0]['metodo']);
    }

    public function test_similitud_entre_03_y_05_es_dudoso(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'CINTA AISLANTE NEGRA'])],
            [$this->partida(1, ['descripcion' => 'CINTA AISLANTE BLANCA ROJA'])]
        );
        $this->assertSame('dudoso', $r['filas'][0]['estado']);
        $this->assertTrue($r['hay_diferencias']);
    }

    public function test_bajo_03_no_se_empareja(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'TALADRO PERCUTOR INALAMBRICO'])],
            [$this->partida(1, ['descripcion' => 'CINTA AISLANTE NEGRA'])]
        );
        $estados = collect($r['filas'])->pluck('estado')->all();
        $this->assertSame(['falta_en_pedido', 'sobra_en_pedido'], $estados);
    }

    public function test_medidas_distintas_emparejadas_por_descripcion_quedan_dudosas(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'FOCO LED 9W LUZ 3000K'])],
            [$this->partida(1, ['descripcion' => 'FOCO LED 9W LUZ 6500K'])]
        );
        $this->assertSame('dudoso', $r['filas'][0]['estado']);
        $this->assertStringContainsString('3000k', $r['filas'][0]['detalles']['dudoso']);
    }

    public function test_modelo_distinto_con_descripcion_parecida(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['descripcion' => 'FOCO LED 9W', 'modelo' => 'F9-3000K'])],
            [$this->partida(1, ['descripcion' => 'FOCO LED 9W', 'modelo' => 'F9-6500K'])]
        );
        $this->assertSame('modelo_distinto', $r['filas'][0]['estado']);
    }

    public function test_varias_diferencias_en_una_fila(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['modelo' => 'A1', 'cantidad' => 10, 'unidad' => 'CAJA', 'precio_unitario' => 50])],
            [$this->partida(1, ['modelo' => 'A1', 'cantidad' => 1, 'unidad' => 'PZA', 'precio' => 30])]
        );
        $f = $r['filas'][0];
        $this->assertSame(['cantidad_distinta', 'unidad_distinta', 'precio_distinto'], $f['diferencias']);
        $this->assertSame('cantidad_distinta', $f['estado']);
    }

    public function test_confianza_baja_nunca_es_coincide(): void
    {
        $r = $this->conciliar([$this->renglon(['modelo' => 'A1', 'confianza' => 'baja'])], [$this->partida(1, ['modelo' => 'A1'])]);
        $this->assertSame('confianza_baja', $r['filas'][0]['estado']);
        $this->assertTrue($r['hay_diferencias']);
    }

    public function test_asignacion_voraz_una_partida_por_renglon(): void
    {
        // Dos renglones con el mismo modelo y dos partidas: cada quien con la de su cantidad.
        $r = $this->conciliar(
            [$this->renglon(['modelo' => 'A1', 'cantidad' => 5]), $this->renglon(['modelo' => 'A1', 'cantidad' => 2])],
            [$this->partida(1, ['modelo' => 'A1', 'cantidad' => 2]), $this->partida(2, ['modelo' => 'A1', 'cantidad' => 5])]
        );
        $this->assertSame(2, $r['filas'][0]['partida']['id']);
        $this->assertSame(1, $r['filas'][1]['partida']['id']);
        $this->assertFalse($r['hay_diferencias']);

        // Dos renglones, una sola partida: el segundo falta.
        $r = $this->conciliar(
            [$this->renglon(['modelo' => 'A1']), $this->renglon(['modelo' => 'A1'])],
            [$this->partida(1, ['modelo' => 'A1'])]
        );
        $this->assertSame(['coincide', 'falta_en_pedido'], collect($r['filas'])->pluck('estado')->all());
    }

    public function test_numero_de_oc_normalizado_y_advertencia_si_difiere(): void
    {
        $ok = $this->conciliar([], [], ['numero_oc' => 'OC-0012345'], '12345');
        $this->assertSame([], $ok['advertencias']);

        $mal = $this->conciliar([], [], ['numero_oc' => '99999'], 'OC 12345');
        $this->assertSame('alto', $mal['advertencias'][0]['nivel']);
        $this->assertStringContainsString('99999', $mal['advertencias'][0]['texto']);

        $nulo = $this->conciliar([], [], ['numero_oc' => null], '12345');
        $this->assertStringContainsString('No se pudo leer el número', $nulo['advertencias'][0]['texto']);
    }

    public function test_suma_de_importes_contra_subtotal(): void
    {
        $r1 = $this->renglon(['importe' => 100]);
        $r2 = $this->renglon(['importe' => 50, 'descripcion' => 'OTRA COSA']);

        $cuadra = $this->conciliar([$r1, $r2], [], ['subtotal' => 150.0]);
        $this->assertSame([], $cuadra['advertencias']);

        $noCuadra = $this->conciliar([$r1, $r2], [], ['subtotal' => 200.0]);
        $this->assertSame('alto', $noCuadra['advertencias'][0]['nivel']);
        $this->assertStringContainsString('no cuadra', $noCuadra['advertencias'][0]['texto']);
    }

    public function test_suma_que_no_cuadra_por_observaciones_es_advertencia_suave(): void
    {
        $obs = $this->renglon(['descripcion' => 'RTV SILICONE 13 OZ', 'origen' => 'observaciones', 'importe' => null, 'precio_unitario' => null]);
        $r = $this->conciliar([$obs], [], ['subtotal' => 5102.8]);
        $this->assertCount(1, $r['advertencias']);
        $this->assertSame('medio', $r['advertencias'][0]['nivel']);
    }

    public function test_advertencias_de_la_ia_pasan_al_resultado(): void
    {
        $r = $this->conciliar([], [], ['advertencias' => ['posible empaque de 12 piezas']]);
        $this->assertStringContainsString('posible empaque de 12 piezas', $r['advertencias'][0]['texto']);
    }

    public function test_fila_ignorada_no_cuenta_como_diferencia_sin_resolver(): void
    {
        $r = $this->conciliar(
            [$this->renglon(['modelo' => 'A1', 'cantidad' => 10])],
            [$this->partida(1, ['modelo' => 'A1', 'cantidad' => 1])],
            [], null,
            ['r0' => ['accion' => 'ignorada', 'motivo' => 'Confirmado con el cliente', 'usuario' => 'Rosa']]
        );
        $this->assertTrue($r['filas'][0]['resuelta']);
        $this->assertFalse($r['hay_diferencias']);
        $this->assertStringContainsString('1 ignorada', $r['resumen']);
    }

    public function test_oc_sin_renglones(): void
    {
        $r = $this->conciliar([], [$this->partida(1)]);
        $this->assertSame('No se leyó ningún renglón de la OC.', $r['resumen']);
        $this->assertTrue($r['hay_diferencias']);
    }
}
