<div>

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <input type="search" wire:model.live.debounce.300ms="busqueda" placeholder="Buscar por descripción o modelo" aria-label="Buscar producto" class="campo sm:max-w-md">
        <p class="text-sm text-slate-500">{{ $total }} {{ $total === 1 ? 'producto' : 'productos' }} con imagen</p>
    </div>

    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if ($fichas->isEmpty())
        <p class="rounded-xl bg-white p-6 text-base text-slate-600 shadow-sm">
            {{ $busqueda !== '' ? 'No hay productos con imagen que coincidan.' : 'Sin imágenes' }}
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($fichas as $f)
                <div wire:key="ficha-{{ $f->id }}" class="flex flex-col rounded-xl bg-white shadow-sm">
                    <div class="flex h-48 items-center justify-center overflow-hidden rounded-t-xl bg-slate-50 p-2">
                        <img src="{{ Storage::disk('public')->url($f->imagen_path) }}" alt="" loading="lazy" class="max-h-full max-w-full object-contain">
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <p class="line-clamp-3 text-sm font-semibold text-marino" title="{{ $f->descripcion }}">{{ $f->descripcion }}</p>
                        @if ($f->modelo)
                            <p class="text-xs text-slate-600">Modelo: <span class="font-mono font-semibold">{{ $f->modelo }}</span></p>
                        @endif
                        <p class="text-xs text-slate-500">
                            {{ $f->veces_usada > 0 ? 'Se puso sola '.$f->veces_usada.' '.($f->veces_usada === 1 ? 'vez' : 'veces').' · ' : '' }}{{ $f->updated_at?->timezone('America/Mexico_City')->format('d/m/Y') }}{{ $f->user ? ' · '.$f->user->name : '' }}
                        </p>
                        <div class="mt-auto flex flex-wrap gap-2 pt-2">
                            <label class="btn-secundario !min-h-10 cursor-pointer">
                                Cambiar imagen
                                <input type="file" wire:model="archivos.{{ $f->id }}" accept="image/jpeg,image/png,image/webp" class="sr-only">
                            </label>
                            <button type="button" wire:click="quitar({{ $f->id }})" wire:confirm="¿Quitar la imagen de este producto? Ya no se pondrá sola al cotizarlo." class="btn-secundario !min-h-10 !text-red-600">Quitar</button>
                        </div>
                        <span wire:loading wire:target="archivos.{{ $f->id }}" class="text-sm text-slate-500">Subiendo…</span>
                        @error('archivos.'.$f->id) <p class="error-campo">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $fichas->links() }}</div>
    @endif
</div>
