<?php

namespace App\Livewire\Registro;

use App\Support\ImportadorRegistro;
use App\Support\LectorRegistroExcel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Traer al sistema las cotizaciones del REGISTRO de Google Sheets (solo admin). Daniel descarga el
 * REGISTRO como Excel, lo sube aquí, ve qué se va a importar y confirma. Las cotizaciones hechas en el
 * sistema conservan su folio; el contador queda en el consecutivo del REGISTRO.
 */
#[Layout('layouts.app')]
#[Title('Importar REGISTRO de Sheets')]
class Importar extends Component
{
    use WithFileUploads;

    public $archivo = null;

    /** Clave del Excel ya leído (se guarda su contenido en storage/app/registro/<clave>.json). */
    #[Locked]
    public string $clave = '';

    public bool $ajustarConsecutivo = true;

    public string $mensaje = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
    }

    public function updatedArchivo(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->mensaje = '';
        $this->validate(['archivo' => ['required', 'file', 'max:51200']], ['archivo.max' => 'El archivo pesa más de 50 MB.']);
        if (strtolower($this->archivo->getClientOriginalExtension()) !== 'xlsx') {
            $this->addError('archivo', 'Sube el REGISTRO como Excel (.xlsx): en Google Sheets, Archivo → Descargar → Microsoft Excel.');

            return;
        }
        try {
            $datos = (new LectorRegistroExcel)->leer($this->archivo->getRealPath());
        } catch (\Throwable $e) {
            $this->addError('archivo', $e->getMessage());

            return;
        }
        $this->borrarLeido();
        $this->clave = (string) Str::uuid();
        Storage::disk('local')->put("registro/{$this->clave}.json", json_encode($datos));
        $this->archivo = null;
    }

    private function datos(): ?array
    {
        if ($this->clave === '' || ! Storage::disk('local')->exists("registro/{$this->clave}.json")) {
            return null;
        }

        return json_decode(Storage::disk('local')->get("registro/{$this->clave}.json"), true);
    }

    private function borrarLeido(): void
    {
        if ($this->clave !== '') {
            Storage::disk('local')->delete("registro/{$this->clave}.json");
        }
        $this->clave = '';
    }

    public function importar(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $datos = $this->datos();
        if (! $datos || $datos['faltan'] !== []) {
            $this->addError('archivo', 'Vuelve a subir el Excel.');

            return;
        }
        @set_time_limit(300);
        $r = (new ImportadorRegistro)->importar($datos, $this->ajustarConsecutivo);
        $this->borrarLeido();
        $this->mensaje = "Listo: se importaron {$r['nuevas']} cotizaciones ({$r['existentes']} ya estaban)."
            .($this->ajustarConsecutivo ? " La siguiente cotización del sistema será la {$r['siguiente']}." : '');
    }

    public function cancelar(): void
    {
        $this->borrarLeido();
    }

    public function render()
    {
        $datos = $this->datos();

        return view('livewire.registro.importar', [
            'datos' => $datos,
            'previa' => $datos && $datos['faltan'] === [] ? ImportadorRegistro::vistaPrevia($datos) : null,
        ]);
    }
}
