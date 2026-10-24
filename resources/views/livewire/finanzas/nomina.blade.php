@php
    $dinero = fn ($v) => '$'.number_format((float) $v, 2);
@endphp
<div class="space-y-4">
    {{-- ===== Pestañas ===== --}}
    <nav class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" role="tablist" aria-label="Secciones de nómina">
        @foreach ($pestanas as $clave => $texto)
            <button type="button" role="tab" wire:click="irA('{{ $clave }}')" aria-selected="{{ $ver === $clave ? 'true' : 'false' }}"
                    class="shrink-0 rounded-lg px-4 py-2 text-base font-semibold {{ $ver === $clave ? 'bg-marino text-white' : 'bg-white text-marino shadow-sm hover:bg-slate-50' }}">{{ $texto }}</button>
        @endforeach
    </nav>

    @error('conciliacion')<div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">{{ $message }}</div>@enderror
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    @if ($ver === 'semana')
        @include('livewire.finanzas.nomina.semana')
    @elseif ($ver === 'prestamos')
        @include('livewire.finanzas.nomina.prestamos')
    @elseif ($ver === 'reparto')
        @include('livewire.finanzas.nomina.reparto')
    @elseif ($ver === 'historial')
        @include('livewire.finanzas.nomina.historial')
    @elseif ($ver === 'empleados')
        @include('livewire.finanzas.nomina.empleados')
    @endif
</div>
