<?php

namespace App\Livewire\Finanzas;

use App\Support\ImportadorGastosSheets;
use App\Support\ImportadorNominaSheets;
use App\Support\ImportadorSaldosSheets;
use App\Support\ImportadorVentasSheets;
use App\Support\RegistroErrores;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Traer al sistema el historial del Google Sheets de Daniel (solo admin). Se descarga el Sheets como Excel, se sube
 * aquí, se ve qué se va a importar por hoja (VENTAS 2026, 2025, 2024, 2023 y, si las trae, HISTORIAL NOMINA y GASTOS 2025, 2024, 2023) y se
 * confirma. Solo se agrega lo que todavía no está: se puede subir el Excel otra vez sin duplicar.
 */
#[Layout('layouts.app')]
#[Title('Importar del Sheets')]
class Importar extends Component
{
    use WithFileUploads;

    public $archivo = null;

    /** Clave de la copia temporal del Excel (storage/app/private/finanzas/<clave>.xlsx). */
    #[Locked]
    public string $clave = '';

    /** Vista previa (ImportadorVentasSheets::analizar), calculada una sola vez al subir el Excel. */
    #[Locked]
    public array $previa = [];

    /** Vista previa del historial de nómina (ImportadorNominaSheets::analizar); vacía si el Excel no trae esa hoja. */
    #[Locked]
    public array $previaNomina = [];

    /** Vista previa de las hojas GASTOS (ImportadorGastosSheets::analizar); vacía si el Excel no trae esas hojas. */
    #[Locked]
    public array $previaGastos = [];

    /** Resumen de lo que se importó (ImportadorVentasSheets::importar). */
    #[Locked]
    public array $resultado = [];

    /** Resumen de lo que se importó del historial de nómina. */
    #[Locked]
    public array $resultadoNomina = [];

    /** Resumen de lo que se importó de las hojas de gastos. */
    #[Locked]
    public array $resultadoGastos = [];

    /** Vista previa de CONTROL y SALDOS (ImportadorSaldosSheets::analizar); vacía si el Excel no trae esas hojas. */
    #[Locked]
    public array $previaSaldos = [];

    /** Resumen de lo que se importó de saldos (cuentas y corte). */
    #[Locked]
    public array $resultadoSaldos = [];

    /** Importar los saldos otra vez aunque ya haya un corte "Importado del Sheets". */
    public bool $reimportarSaldos = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
    }

    public function updatedArchivo(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->resultado = [];
        $this->resultadoNomina = [];
        $this->resultadoGastos = [];
        $this->resultadoSaldos = [];
        $this->reimportarSaldos = false;
        $this->validate(['archivo' => ['required', 'file', 'max:51200']], ['archivo.max' => 'El archivo pesa más de 50 MB.']);
        if (strtolower($this->archivo->getClientOriginalExtension()) !== 'xlsx') {
            $this->addError('archivo', 'Sube el Excel (.xlsx): en Google Sheets, Archivo → Descargar → Microsoft Excel (.xlsx).');

            return;
        }

        $this->quitarTemporal();
        $this->limpiarViejos();
        $clave = (string) Str::uuid();
        $disco = Storage::disk('local');
        $disco->putFileAs('finanzas', $this->archivo, "{$clave}.xlsx");
        $this->archivo = null;

        @set_time_limit(300);
        try {
            $ruta = $disco->path("finanzas/{$clave}.xlsx");
            $hayNomina = ImportadorNominaSheets::hayHoja($ruta);
            $hayGastos = ImportadorGastosSheets::hayHojas($ruta);
            $haySaldos = ImportadorSaldosSheets::hayHojas($ruta);
            // Un Excel solo con el historial de nómina o solo con gastos también sirve; si no trae ninguna hoja conocida, avisa lo de ventas.
            $this->previa = ! ($hayNomina || $hayGastos || $haySaldos) || ImportadorVentasSheets::hayHojas($ruta) ? (new ImportadorVentasSheets)->analizar($ruta) : [];
            $this->previaNomina = $hayNomina ? (new ImportadorNominaSheets)->analizar($ruta) : [];
            $this->previaGastos = $hayGastos ? (new ImportadorGastosSheets)->analizar($ruta) : [];
            $this->previaSaldos = $haySaldos ? (new ImportadorSaldosSheets)->analizar($ruta) : [];
        } catch (Throwable $e) {
            $disco->delete("finanzas/{$clave}.xlsx");
            $this->addError('archivo', $this->mensajeDe($e));

            return;
        }
        $this->clave = $clave;
    }

    public function importar(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $ruta = "finanzas/{$this->clave}.xlsx";
        if ($this->clave === '' || ! Storage::disk('local')->exists($ruta)) {
            $this->quitarTemporal();
            $this->addError('archivo', 'Vuelve a subir el Excel.');

            return;
        }

        // Una sola importación a la vez (si le dan clic dos veces o dos personas, la segunda espera su turno).
        $candado = Cache::lock('finanzas-importar-ventas', 600);
        if (! $candado->get()) {
            $this->addError('archivo', 'Ya hay una importación en curso. Espera a que termine y vuelve a intentar.');

            return;
        }
        @set_time_limit(300);
        try {
            $archivo = Storage::disk('local')->path($ruta);
            if ($this->previa) {
                $this->resultado = (new ImportadorVentasSheets)->importar($archivo);
            }
            if ($this->previaNomina) {
                $this->resultadoNomina = (new ImportadorNominaSheets)->importar($archivo);
            }
            if ($this->previaGastos) {
                $this->resultadoGastos = (new ImportadorGastosSheets)->importar($archivo);
            }
            if ($this->previaSaldos && $this->previaSaldos['cuentas']) {
                $this->resultadoSaldos = (new ImportadorSaldosSheets)->importar($archivo, $this->reimportarSaldos);
            }
        } catch (Throwable $e) {
            $this->addError('archivo', $this->mensajeDe($e));

            return;
        } finally {
            $candado->release();
        }
        $this->quitarTemporal();
    }

    public function cancelar(): void
    {
        $this->quitarTemporal();
        $this->resultado = [];
        $this->resultadoNomina = [];
        $this->resultadoGastos = [];
        $this->resultadoSaldos = [];
        $this->reimportarSaldos = false;
        $this->resetErrorBag();
    }

    /** Mensaje para quien usa la pantalla: los de lectura del Excel tal cual; los demás con el código del error. */
    private function mensajeDe(Throwable $e): string
    {
        if ($e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }
        $registro = RegistroErrores::registrar($e);

        return 'No pude importar el Excel por un error inesperado'.($registro ? " (código {$registro->codigo})" : '').'.';
    }

    private function quitarTemporal(): void
    {
        if ($this->clave !== '') {
            Storage::disk('local')->delete("finanzas/{$this->clave}.xlsx");
        }
        $this->clave = '';
        $this->previa = [];
        $this->previaNomina = [];
        $this->previaGastos = [];
        $this->previaSaldos = [];
    }

    /** Copias que se quedaron sin importar ni cancelar (más de un día). */
    private function limpiarViejos(): void
    {
        $disco = Storage::disk('local');
        $limite = time() - 86400;
        foreach ($disco->files('finanzas') as $archivo) {
            if ($disco->lastModified($archivo) < $limite) {
                $disco->delete($archivo);
            }
        }
    }

    public function render()
    {
        return view('livewire.finanzas.importar');
    }
}
