<?php

namespace App\Livewire\Catalogo;

use App\Models\ProductoImagen;
use App\Support\CatalogoImagenes;
use App\Support\ImagenPartida;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Catálogo de imágenes por producto: las que se pusieron en cotizaciones y se ponen solas al volver a
 * cotizar el producto. Aquí se buscan, se cambian o se quitan (quitar = ya no se pone sola).
 */
#[Layout('layouts.app')]
#[Title('Catálogo de imágenes')]
class Imagenes extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $busqueda = '';

    /** Imagen nueva por ficha (id → archivo). */
    public array $archivos = [];

    public string $mensaje = '';

    public function updatingBusqueda(): void
    {
        $this->resetPage();
    }

    public function updatedArchivos($valor, string $id): void
    {
        $id = (int) $id;
        try {
            $this->validate(["archivos.$id" => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [
                "archivos.$id.image" => 'El archivo debe ser una imagen.',
                "archivos.$id.mimes" => 'Usa una imagen JPG, PNG o WEBP.',
                "archivos.$id.max" => 'La imagen pesa más de 5 MB.',
            ]);
        } catch (ValidationException $e) {
            unset($this->archivos[$id]);
            throw $e;
        }
        $archivo = $this->archivos[$id] ?? null;
        unset($this->archivos[$id]);
        $fila = ProductoImagen::find($id);
        if (! $fila || ! $archivo instanceof TemporaryUploadedFile) {
            return;
        }
        $ruta = ImagenPartida::guardar($archivo->getRealPath());
        if (! $ruta) {
            $this->addError("archivos.$id", 'No se pudo procesar la imagen.');

            return;
        }
        CatalogoImagenes::vincular($fila->modelo, $fila->descripcion, $ruta, auth()->id());
        $this->mensaje = 'Imagen cambiada. Las cotizaciones nuevas de este producto usarán la nueva.';
    }

    public function quitar(int $id): void
    {
        $fila = ProductoImagen::find($id);
        if ($fila) {
            CatalogoImagenes::eliminar($fila);
            $this->mensaje = 'Imagen quitada: ya no se pondrá sola en este producto.';
        }
    }

    public function render()
    {
        $q = CatalogoImagenes::buscar($this->busqueda)->with('user:id,name');

        return view('livewire.catalogo.imagenes', [
            'fichas' => $q->orderByDesc('updated_at')->paginate(24),
            'total' => ProductoImagen::count(),
        ]);
    }
}
