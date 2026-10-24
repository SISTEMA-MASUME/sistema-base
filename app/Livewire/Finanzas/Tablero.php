<?php

namespace App\Livewire\Finanzas;

use App\Support\Semanas;
use App\Support\Tablero as Calculos;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Dashboard del negocio: corte por semana, mes o año (venta − compra − gastos = lo que queda, contra el periodo anterior),
 * KPIs, métricas, top de clientes, desglose mensual, comparativo y gráficas.
 */
#[Layout('layouts.app')]
#[Title('Dashboard')]
class Tablero extends Component
{
    private const LLAVE_SESION = 'tablero.vista';

    #[Url]
    public int $anio = 0;

    /** '' = todo el año, '1'–'12' = ese mes. */
    #[Url]
    public string $mes = '';

    /** semana | mes | anio */
    #[Url]
    public string $vista = '';

    /** Cualquier día de la semana que se ve (Y-m-d); la semana es de lunes a sábado. */
    #[Url]
    public string $semana = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->puede('tablero'), 403);
        if ($this->anio === 0) {
            $this->anio = (int) today()->year;
        }
        // Sin nada en la dirección se abre como la última vez (semana, mes o año).
        if ($this->vista === '' && $this->mes === '' && $this->semana === '') {
            $this->vista = (string) session(self::LLAVE_SESION, '');
            if ($this->vista === 'mes') {
                $this->mes = (string) today()->month;
            }
        }
        if ($this->vista === 'semana') {
            if ($this->semana === '') {
                $this->semana = today()->toDateString();
            }
            $this->sincronizarConSemana();
        }
    }

    public function dehydrate(): void
    {
        session([self::LLAVE_SESION => $this->vistaActual()]);
    }

    private function vistaActual(): string
    {
        return $this->vista === 'semana' ? 'semana' : ($this->mesValido() ? 'mes' : 'anio');
    }

    private function mesValido(): ?int
    {
        return ctype_digit($this->mes) && (int) $this->mes >= 1 && (int) $this->mes <= 12 ? (int) $this->mes : null;
    }

    private function sabado(): Carbon
    {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->semana) && strtotime($this->semana) ? $this->semana : today()->toDateString();

        return Semanas::sabado($f);
    }

    /** Elegir mes o año desde los menús deja de ver la semana. */
    public function updatedMes(): void
    {
        $this->vista = $this->mesValido() ? 'mes' : 'anio';
    }

    public function updatedAnio(): void
    {
        if ($this->vista === 'semana') {
            $this->vista = $this->mesValido() ? 'mes' : 'anio';
        }
    }

    public function verSemana(): void
    {
        $hoy = today();
        // Si se estaba viendo otro mes, la semana que se abre es la última de ese mes (o la de hoy si es el mes actual).
        $mes = $this->mesValido();
        $dia = $mes && ($this->anio !== $hoy->year || $mes !== $hoy->month) ? Carbon::create($this->anio, $mes, 1)->endOfMonth() : $hoy;
        $this->vista = 'semana';
        $this->semana = $dia->toDateString();
        $this->sincronizarConSemana();
    }

    public function verMes(): void
    {
        if ($this->vista === 'semana') {
            $this->sincronizarConSemana();
        } elseif (! $this->mesValido()) {
            $this->mes = (string) ($this->anio === today()->year ? today()->month : 12);
        }
        $this->vista = 'mes';
    }

    public function verAnio(): void
    {
        if ($this->vista === 'semana') {
            $this->sincronizarConSemana();
        }
        $this->vista = 'anio';
        $this->mes = '';
    }

    /** Flechas ‹ ›: la semana, el mes o el año anterior/siguiente. */
    public function mover(int $paso): void
    {
        $paso = $paso < 0 ? -1 : 1;
        match ($this->vistaActual()) {
            'semana' => $this->semana = $this->sabado()->addWeeks($paso)->toDateString(),
            'mes' => (function () use ($paso) {
                $f = Carbon::create($this->anio, (int) $this->mes, 1)->addMonthsNoOverflow($paso);
                [$this->anio, $this->mes] = [$f->year, (string) $f->month];
            })(),
            default => $this->anio += $paso,
        };
        if ($this->vista === 'semana') {
            $this->sincronizarConSemana();
        }
    }

    /** Lo de abajo del Dashboard (métricas, gráficas, top 10…) muestra el mes de la semana que se ve. */
    private function sincronizarConSemana(): void
    {
        $s = $this->sabado();
        $this->anio = $s->year;
        $this->mes = (string) $s->month;
    }

    /**
     * Periodo del corte y el periodo contra el que se compara.
     *
     * @return array{vista: string, desde: Carbon, hasta: Carbon, antDesde: Carbon, antHasta: Carbon, titulo: string, subtitulo: string, contra: string, palabra: string}
     */
    private function periodo(int $anio, ?int $mes): array
    {
        $hoy = today();
        $vista = $this->vistaActual();
        if ($vista === 'semana') {
            $sab = $this->sabado();
            [$a, $n] = Semanas::deFecha($sab);
            $desde = $sab->copy()->subDays(6);   // el domingo cuenta para esta semana (igual que las comisiones)

            return ['vista' => 'semana', 'desde' => $desde, 'hasta' => $sab, 'antDesde' => $desde->copy()->subWeek(), 'antHasta' => $sab->copy()->subWeek(),
                'titulo' => 'Semana '.$n.' de '.$a, 'subtitulo' => preg_replace('/^Semana \d+ · /', '', Semanas::etiqueta($a, $n)).' · semana de comisión',
                'contra' => 'la semana anterior', 'palabra' => 'de la semana'];
        }
        if ($vista === 'mes') {
            $desde = Carbon::create($anio, $mes, 1)->startOfDay();
            $hasta = $desde->copy()->endOfMonth()->startOfDay();
            $enCurso = $desde->isSameMonth($hoy);
            if ($enCurso) {
                $hasta = $hoy->copy();
            }

            return ['vista' => 'mes', 'desde' => $desde, 'hasta' => $hasta, 'antDesde' => $desde->copy()->subYear(), 'antHasta' => $hasta->copy()->subYear(),
                'titulo' => ucfirst(Calculos::MESES_LARGOS[$mes - 1]).' '.$anio, 'subtitulo' => $enCurso ? 'Del 1 al '.$hoy->day.' (mes en curso)' : 'Mes completo',
                'contra' => Calculos::MESES_LARGOS[$mes - 1].' '.($anio - 1).($enCurso ? ' al mismo día' : ''), 'palabra' => 'del mes'];
        }
        $desde = Carbon::create($anio, 1, 1)->startOfDay();
        $hasta = $anio === $hoy->year ? $hoy->copy() : Carbon::create($anio, 12, 31)->startOfDay();

        return ['vista' => 'anio', 'desde' => $desde, 'hasta' => $hasta, 'antDesde' => $desde->copy()->subYear(), 'antHasta' => $hasta->copy()->subYear(),
            'titulo' => 'Año '.$anio, 'subtitulo' => $anio === $hoy->year ? 'Enero a hoy' : 'Año completo',
            'contra' => ($anio - 1).($anio === $hoy->year ? ' al mismo día' : ''), 'palabra' => 'del año'];
    }

    public function render()
    {
        $anios = Calculos::anios();
        $anio = in_array($this->anio, $anios, true) || $this->vista === 'semana' ? $this->anio : (int) today()->year;
        $mes = $this->mesValido();
        $t = new Calculos;
        $mensual = $t->mensual($anio);

        $p = $this->periodo($anio, $mes);
        $corte = $t->corte($p['desde'], $p['hasta']);
        $anterior = $t->corte($p['antDesde'], $p['antHasta']);

        return view('livewire.finanzas.tablero', [
            'anios' => $anios, 'anioActual' => $anio, 'mesActual' => $mes,
            'periodo' => $mes ? ucfirst(Calculos::MESES_LARGOS[$mes - 1]).' '.$anio : 'Todo '.$anio,
            'resumen' => $t->resumen($anio, $mes),
            'top' => $t->topClientes($anio, $mes),
            'mensual' => $mensual['filas'], 'totalMensual' => $mensual['total'],
            'comparativo' => $t->comparativo($anio, $mes),
            'ventaAnterior' => $t->ventaPorMes($anio - 1),
            'semanas' => $t->semanal($anio, $mes),
            'categorias' => $t->gastosPorCategoria($anio, $mes),
            'nomina' => $t->nominaPorEmpleado($anio),
            // Corte del periodo elegido (semana, mes o año) contra el anterior.
            'p' => $p, 'corte' => $corte, 'corteAnterior' => $anterior,
            'tendencia' => $p['vista'] === 'semana' ? $t->semanasHasta($p['hasta']) : [],
            'topSemana' => $p['vista'] === 'semana' ? $t->topClientesRango($p['desde'], $p['hasta']) : [],
        ]);
    }
}
