{{-- Estatus de pago de una venta: insignia + (pagada: fecha y cuenta) o (pendiente: cuándo vence). Recibe $v y $hoy. --}}
@php
    $colores = ['pendiente' => 'bg-amber-100 text-amber-800', 'pagada' => 'bg-green-100 text-green-800', 'cancelada' => 'bg-red-100 text-red-700'];
@endphp
<span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $colores[$v->estatus] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Models\Venta::ESTATUS[$v->estatus] ?? $v->estatus }}</span>
@if ($v->estatus === 'pagada')
    @if ($v->deposito_cobro_id)
        <a href="{{ route('finanzas.depositos') }}" class="block text-xs font-semibold text-amber-800">{{ $v->depositoCobro?->estatus === 'conciliado' ? 'Depósito conciliado' : 'Depósito por confirmar o revisar' }}</a>
    @endif
    <span class="mt-0.5 block text-xs text-slate-600">{{ $v->fecha_pago?->format('d/m/Y') }}</span>
    @if ($v->cuenta)
        <span class="block text-xs text-slate-500">{{ $v->cuenta->nombre }}</span>
    @endif
@elseif ($v->estatus === 'pendiente')
    @php($vence = $v->vence())
    <span class="mt-0.5 block text-xs {{ $vence->lt($hoy) ? 'font-semibold text-red-600' : 'text-slate-500' }}">{{ $vence->lt($hoy) ? 'Vencida' : 'Vence' }} {{ $vence->format('d/m/Y') }}</span>
@endif
