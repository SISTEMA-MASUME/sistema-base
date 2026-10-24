{{-- Cobros de complementos de SICAR que llegaron a esta cuenta después de su último saldo (ya sumados al saldo). --}}
@if ($cobro)
    <span class="mt-0.5 block text-sm font-medium text-green-700" data-cobros-cuenta>
        + {{ '$'.number_format($cobro['total'], 2) }} de cobros
        ({{ $cobro['complementos']->map(fn ($r) => $r->folio.' '.\Illuminate\Support\Str::limit($r->cliente_nombre, 22, '…'))->implode(', ') }})
    </span>
@endif
