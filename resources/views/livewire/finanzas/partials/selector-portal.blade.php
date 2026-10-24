{{-- Lista para cambiar el estatus del portal de una sola venta, con el color de su estatus. Recibe $v. --}}
@php
    $colores = [
        'por_cargar' => 'bg-amber-100 text-amber-800 border-amber-200', 'cargada' => 'bg-sky-100 text-sky-800 border-sky-200',
        'evidencia' => 'bg-purple-100 text-purple-800 border-purple-200', 'programada' => 'bg-teal-100 text-teal-800 border-teal-200',
        'no_aplica' => 'bg-slate-200 text-slate-600 border-slate-300',
    ];
@endphp
<select wire:key="portal-{{ $v->id }}-{{ $v->estatus_portal }}"
        x-on:change="$wire.cambiarPortal({{ $v->id }}, $event.target.value)"
        aria-label="Estatus del portal de {{ $v->folio }}"
        class="w-full max-w-[8.5rem] cursor-pointer rounded-2xl border px-2 py-1 text-xs font-semibold {{ $colores[$v->estatus_portal] ?? 'border-slate-200 bg-white text-slate-500' }}">
    <option value="" @selected(! $v->estatus_portal)>—</option>
    @foreach (\App\Models\Venta::PORTAL as $clave => $texto)
        <option value="{{ $clave }}" @selected($v->estatus_portal === $clave)>{{ $texto }}</option>
    @endforeach
</select>
