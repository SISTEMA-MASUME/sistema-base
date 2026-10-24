<div class="space-y-5">
    @php
        $dinero = fn ($n) => '$'.number_format((float) $n, 2);
    @endphp
    @if ($mensaje)<div role="status" class="rounded-lg border border-green-300 bg-green-50 p-4 text-green-800">{{ $mensaje }}</div>@endif
    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    @if ($pendientes)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ $pendientes }} depósitos por confirmar o revisar.</div>
    @else
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 p-4 text-green-800">No tienes depósitos pendientes de confirmar o revisar.</div>
    @endif
    <section class="rounded-xl bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-lg font-semibold text-marino">Registrar depósito provisional</h2>
        <form wire:submit="guardar" class="mt-4 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="etiqueta">Cliente<select wire:model.live="clienteId" class="campo"><option value="">Elige el cliente</option>@foreach ($clientes as $c)<option value="{{ $c->id }}">{{ $c->nombre }} · {{ $c->rfc }}</option>@endforeach</select></label>
                <label class="etiqueta">Cuenta donde recibiste el dinero<select wire:model="cuentaId" class="campo"><option value="">Elige la cuenta receptora</option>@foreach ($cuentas as $c)<option value="{{ $c->id }}">{{ $c->nombre }}</option>@endforeach</select></label>
                <label class="etiqueta">Fecha del depósito<input type="date" wire:model="fecha" class="campo"></label>
                <label class="etiqueta">Importe recibido con IVA<input type="number" step="0.01" min="0.01" wire:model="importe" class="campo" placeholder="5000.00"></label>
                <label class="etiqueta sm:col-span-2">Referencia o nota (opcional)<input wire:model="referencia" maxlength="120" class="campo" placeholder="La referencia bancaria no es necesaria para conciliar"></label>
            </div>
            @if ($clienteId)
                <label class="etiqueta">Buscar factura por folio<input type="search" wire:model.live.debounce.300ms="buscarFactura" class="campo" placeholder="Ejemplo: 5-4913"></label>
                <div class="max-h-72 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                    @forelse ($facturas as $v)
                        <label class="flex min-h-12 items-center justify-between gap-3 rounded p-2 hover:bg-slate-50" wire:key="elegir-{{ $v->id }}">
                            <span class="flex items-center gap-3"><input type="checkbox" wire:model.live="seleccion" value="{{ $v->id }}" class="h-5 w-5">{{ $v->folio }}@if ($v->nomina_id)<span class="text-xs text-green-700">Comisión ya pagada</span>@endif</span><span>{{ $dinero($v->total) }}</span>
                        </label>
                    @empty<p class="p-3 text-slate-600">No hay facturas pendientes disponibles con este filtro.</p>@endforelse
                </div>
                <p class="font-semibold text-marino">Total seleccionado: {{ $dinero($totalSeleccionado) }}</p>
            @endif
            <button class="btn-primario" wire:loading.attr="disabled">Registrar depósito y cobro provisional</button>
        </form>
    </section>
    <details class="rounded-xl bg-white p-4 shadow-sm sm:p-5">
        <summary class="cursor-pointer font-semibold text-marino">Cargar un complemento XML</summary>
        <form wire:submit="importarXml" class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="etiqueta flex-1">XML del complemento<input type="file" accept=".xml,text/xml,application/xml" wire:model="archivoXml" class="campo"></label>
            <button class="btn-secundario" wire:loading.attr="disabled">Leer complemento XML</button>
        </form>
    </details>
    <section class="rounded-xl bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold text-marino">Depósitos registrados</h2><label class="etiqueta">Mostrar<select wire:model.live="filtro" class="campo"><option value="pendientes">Por confirmar o revisar</option><option value="todos">Todo el historial</option></select></label></div>
        <div class="mt-3 space-y-2">
            @forelse ($depositos as $d)
                <button wire:click="ver({{ $d->id }})" class="flex w-full flex-col gap-1 rounded-lg border border-slate-200 p-3 text-left hover:border-naranja sm:flex-row sm:justify-between" wire:key="dep-{{ $d->id }}">
                    <span><strong class="text-marino">{{ $d->folio() }}</strong> · {{ $d->cliente->nombre }}<span class="block text-sm text-slate-600">{{ $d->fecha->format('d/m/Y') }} · {{ $d->cuenta->nombre }}</span></span>
                    <span>{{ $dinero($d->importe) }}<strong class="block text-sm {{ $d->estatus === 'conciliado' ? 'text-green-700' : 'text-amber-800' }}">{{ ['provisional' => 'Por confirmar', 'revisar' => 'Requiere revisión', 'conciliado' => 'Conciliado', 'anulado' => 'Asignación anulada'][$d->estatus] ?? $d->estatus }}</strong></span>
                </button>
                @foreach ($sugerencias[$d->id] ?? [] as $aviso)<p class="rounded bg-amber-50 p-3 text-sm font-semibold text-amber-900">{{ $aviso }}</p>@endforeach
            @empty<p class="py-4 text-slate-600">No hay depósitos con este filtro.</p>@endforelse
        </div><div class="mt-3">{{ $depositos->links() }}</div>
    </section>
    @if ($deposito)
        <section class="space-y-4 rounded-xl border-2 border-marino bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-lg font-semibold text-marino">{{ $deposito->folio() }} · {{ $deposito->cliente->nombre }} · {{ $dinero($deposito->importe) }}</h2>
            @if ($deposito->estatus === 'revisar')<p role="alert" class="rounded bg-red-50 p-3 text-red-800">Cambió una factura o el complemento. Revisa la incidencia; las comisiones pagadas conservan su historial.</p>@endif
            <h3 class="font-semibold">Asignación e historial de facturas</h3>
            <ul class="space-y-2">@foreach ($deposito->facturas as $v)
                <li class="rounded border border-slate-200 p-2">{{ $v->folio }} · {{ $dinero($v->pivot->importe) }} · {{ $v->pivot->activa ? 'Asignada al depósito' : 'Retirada de este depósito' }}<span class="block text-sm {{ $v->nomina_id ? 'text-green-700' : 'text-slate-600' }}">{{ $v->nomina_id ? 'Comisión ya pagada; no se repetirá' : 'Comisión pendiente (según las reglas de nómina)' }}{{ $v->pivot->original ? ' · Asignación original' : '' }}</span></li>
            @endforeach</ul>
            @if (in_array($deposito->estatus, ['provisional', 'revisar']))
                <label class="etiqueta">Complemento de este cliente<select wire:model.live="complementoId" class="campo"><option value="">Elige y revisa el complemento</option>@foreach ($complementos as $r)<option value="{{ $r->id }}">{{ $r->folio }} · {{ $r->fecha_pago->format('d/m/Y') }} · {{ $dinero($r->importe) }}{{ $r->cancelado ? ' · CANCELADO' : '' }}{{ $r->requiere_revision ? ' · REVISAR' : '' }}</option>@endforeach</select></label>
                @if ($errorComparacion)<p role="alert" class="rounded bg-red-50 p-3 text-red-800">{{ $errorComparacion }}</p>@endif
                @if ($comparacion)
                    <div class="space-y-2 rounded bg-amber-50 p-3 text-amber-900">
                        <p><strong>Volverán a pendientes:</strong> {{ $comparacion['quitar']->pluck('folio')->implode(', ') ?: 'Ninguna' }}.</p>
                        <p><strong>Se agregarán como cobradas:</strong> {{ $comparacion['agregar']->pluck('folio')->implode(', ') ?: 'Ninguna; la asignación coincide' }}.</p>
                        <p><strong>Facturas confirmadas:</strong> {{ $comparacion['ventas']->pluck('folio')->implode(', ') }}.</p>
                        @if ($comparacion['fecha_distinta'])<p>La fecha del complemento es distinta; se usará su fecha de pago.</p>@endif
                    </div>
                @endif
                <label class="etiqueta">Explica la confirmación o corrección<textarea wire:model="motivo" class="campo" maxlength="1000" placeholder="El cliente confirmó que este complemento corresponde a este depósito"></textarea></label>
                <div class="flex flex-wrap gap-3">
                    <button wire:click="confirmar" wire:loading.attr="disabled" @disabled(! $comparacion) class="btn-primario">Confirmar vínculo y aplicar la asignación</button>
                    @if ($complementoSeleccionado && ! $complementoSeleccionado->deposito_id && $complementoSeleccionado->estatus !== 'independiente' && ! $complementoSeleccionado->cancelado && ! $complementoSeleccionado->requiere_revision)
                        <button wire:click="independiente({{ $complementoSeleccionado->id }})" wire:confirm="Confirma que es OTRO depósito recibido. No se corregirá la asignación provisional." wire:loading.attr="disabled" class="btn-secundario">Es otro depósito; confirmar cobro</button>
                    @endif
                    @if (! $deposito->complemento_id)<button wire:click="anular" wire:confirm="¿Anular la asignación? Las facturas vigentes volverán a pendientes y conservarán sus comisiones pagadas." wire:loading.attr="disabled" class="btn-secundario">Anular asignación equivocada</button>@endif
                </div>
            @elseif ($deposito->complemento)
                <p class="rounded bg-green-50 p-3 text-green-800">Confirmado con REP {{ $deposito->complemento->folio }}. UUID: {{ $deposito->complemento->uuid ?: 'Identificado por SICAR' }}.</p>
                @if (auth()->user()->esAdmin())
                    <label class="etiqueta">Motivo para revisar una conciliación equivocada<textarea wire:model="motivo" class="campo" maxlength="1000"></textarea></label>
                    <button wire:click="revisar" wire:loading.attr="disabled" class="btn-secundario">Abrir conciliación para revisión</button>
                @endif
            @endif
            <details class="rounded border border-slate-200 p-3"><summary class="cursor-pointer font-semibold">Bitácora de movimientos</summary>
                @foreach ($deposito->eventos as $e)<div class="mt-3 border-t border-slate-200 pt-2 text-sm"><p>{{ $e->created_at->format('d/m/Y H:i') }} · {{ str_replace('_', ' ', $e->accion) }} · Usuario {{ $e->user_id ?: 'Puente SICAR' }}</p>
                    @if (isset($e->datos['motivo']))<p>{{ $e->datos['motivo'] }}</p>@endif
                    @foreach (['facturas', 'quitar', 'agregar', 'confirmadas'] as $campo)@if (isset($e->datos[$campo]))<p>{{ ucfirst($campo) }}: {{ implode(', ', $e->datos[$campo]) ?: 'Ninguna' }}</p>@endif @endforeach
                </div>@endforeach
            </details>
        </section>
    @endif
</div>
