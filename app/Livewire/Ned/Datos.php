<?php

namespace App\Livewire\Ned;

use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedDatos;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Datos y logo de Ned Tracking que salen en sus cotizaciones. Los edita el propio Pedro. */
#[Layout('layouts.app')]
#[Title('Mis datos y logo')]
class Datos extends Component
{
    use WithFileUploads;

    public string $empresa = '';

    public string $nombre = '';

    public string $rfc = '';

    public string $regimen = '';

    public string $direccion = '';

    public string $telefono = '';

    public string $correo = '';

    public string $web = '';

    public string $condiciones = '';

    public $logo = null;

    public int|string $proximoFolio = '';

    public string $mensaje = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
        $d = NedDatos::actuales();
        foreach (['empresa', 'nombre', 'rfc', 'regimen', 'direccion', 'telefono', 'correo', 'web', 'condiciones'] as $c) {
            $this->$c = (string) $d->$c;
        }
        $this->proximoFolio = NedCotizacion::proximoFolio();
    }

    /** Cambia el consecutivo: la próxima cotización nueva sale con este número. */
    public function guardarFolio(): void
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
        $this->validate(['proximoFolio' => ['required', 'integer', 'min:1', 'max:999999']], [
            'proximoFolio.*' => 'Escribe un número entero de 1 a 999999.',
        ]);
        $n = (int) $this->proximoFolio;
        if (NedCotizacion::withTrashed()->where('folio', $n)->exists()) {
            $this->addError('proximoFolio', 'Ya tienes una cotización con ese número. Elige otro.');

            return;
        }
        NedCotizacion::fijarProximoFolio($n);
        $this->mensaje = 'Listo: tu próxima cotización será la '.(new NedCotizacion(['folio' => $n]))->folioTexto().'.';
    }

    public function guardar(): void
    {
        $v = $this->validate([
            'empresa' => ['required', 'string', 'max:255'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'regimen' => ['nullable', 'string', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:1000'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'web' => ['nullable', 'string', 'max:255'],
            'condiciones' => ['nullable', 'string', 'max:3000'],
        ], ['empresa.required' => 'Escribe el nombre de la empresa.', 'correo.email' => 'Escribe un correo válido.']);
        NedDatos::actuales()->update(array_map(fn ($x) => is_string($x) && trim($x) === '' ? null : $x, $v));
        $this->mensaje = 'Datos guardados.';
    }

    public function updatedLogo(): void
    {
        $this->validate(['logo' => ['image', 'mimes:jpg,jpeg,png', 'max:4096']], [
            'logo.image' => 'El logo debe ser una imagen.', 'logo.mimes' => 'Usa un logo JPG o PNG.', 'logo.max' => 'El logo pesa más de 4 MB.',
        ]);
        $datos = NedDatos::actuales();
        $anterior = $datos->logo_path;
        $datos->update(['logo_path' => $this->logo->store('ned', 'local')]);
        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }
        $this->logo = null;
        $this->mensaje = 'Logo actualizado.';
    }

    public function render()
    {
        return view('livewire.ned.datos');
    }
}
