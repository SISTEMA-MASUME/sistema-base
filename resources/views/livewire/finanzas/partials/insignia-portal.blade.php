{{-- Estatus del portal del cliente (por cargar, cargada, evidencia…). Recibe $v. --}}
@php
    $colores = [
        'por_cargar' => 'bg-amber-100 text-amber-800', 'cargada' => 'bg-sky-100 text-sky-800', 'evidencia' => 'bg-purple-100 text-purple-800',
        'programada' => 'bg-teal-100 text-teal-800', 'no_aplica' => 'bg-slate-200 text-slate-600',
    ];
@endphp
@if ($v->estatus_portal)
    <span class="inline-block max-w-[6.75rem] rounded-2xl px-2.5 py-0.5 text-xs font-semibold leading-snug {{ $colores[$v->estatus_portal] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Models\Venta::PORTAL[$v->estatus_portal] ?? $v->estatus_portal }}</span>
@else
    <span class="text-xs text-slate-400">—</span>
@endif
