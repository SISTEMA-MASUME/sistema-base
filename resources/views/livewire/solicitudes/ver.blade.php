@php
    use App\Support\Solicitudes as Sol;
    $colores = ['verde' => 'bg-green-100 text-green-800', 'amarillo' => 'bg-amber-100 text-amber-800', 'rojo' => 'bg-red-100 text-red-700'];
    $estatusColor = ['pendiente' => 'bg-amber-100 text-amber-800', 'en_proceso' => 'bg-blue-100 text-blue-800', 'cotizada' => 'bg-green-100 text-green-800', 'descartada' => 'bg-slate-200 text-slate-700'];
    $esMia = $s->tomada_por === auth()->id();
@endphp
<div>
    @if ($mensaje)
        <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif
    @if ($error)
        <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ $error }}</div>
    @endif

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <a href="{{ route('solicitudes.index') }}" class="btn-secundario">← Por cotizar</a>
        @if ($s->estaAbierta())
            <a href="{{ route('solicitudes.editar', $s) }}" class="btn-secundario">Editar</a>
        @endif
    </div>

    <div class="mb-5 rounded-xl bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="break-words text-xl font-bold text-marino">{{ $s->folioTexto() }} · {{ $s->nombreCliente() }}</h2>
                <p class="mt-1 text-base text-slate-600">
                    {{ $s->canalTexto() }} · llegó {{ $s->recibida_at->format('d/m/Y H:i') }}
                    @if ($s->contacto_texto) · Contacto: {{ $s->contacto_texto }} @endif
                </p>
                <p class="text-sm text-slate-500">Anotada por {{ $s->creadaPor?->nombreReal() ?? '—' }}</p>
            </div>
            <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $estatusColor[$s->estatus] }}">{{ \App\Models\SolicitudCotizacion::ESTATUS[$s->estatus] }}</span>
        </div>

        {{-- Tiempos --}}
        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-slate-50 p-3">
                <dt class="text-sm text-slate-500">{{ $s->estatus === 'pendiente' ? 'Lleva esperando' : 'Llegada → tomada' }}</dt>
                <dd class="text-lg font-semibold">
                    @if ($s->estatus === 'pendiente')
                        <span class="rounded-full px-2 py-0.5 text-base {{ $colores[Sol::semaforo($s)] }}">{{ Sol::humano($s->recibida_at->diffInMinutes(now(), false)) }}</span>
                    @else
                        {{ Sol::humano($s->minutosHastaTomar()) }}
                    @endif
                </dd>
            </div>
            <div class="rounded-lg bg-slate-50 p-3">
                <dt class="text-sm text-slate-500">Llegada → enviada al cliente</dt>
                <dd class="text-lg font-semibold">{{ $envio ? Sol::humano($s->minutosHastaEnviar()) : 'Aún no se envía' }}</dd>
                @if ($envio)<dd class="text-xs text-slate-500">Enviada {{ $envio->format('d/m/Y H:i') }}</dd>@endif
            </div>
            <div class="rounded-lg bg-slate-50 p-3">
                <dt class="text-sm text-slate-500">Quién la trabaja</dt>
                <dd class="text-lg font-semibold">{{ $s->tomadaPor?->nombreReal() ?? '—' }}</dd>
                @if ($s->tomada_at)<dd class="text-xs text-slate-500">Tomada {{ $s->tomada_at->format('d/m/Y H:i') }}</dd>@endif
            </div>
        </dl>

        @if ($s->estatus === 'descartada')
            <p class="mt-3 rounded-lg bg-slate-100 p-3 text-base text-slate-700">Descartada: {{ $s->motivo_descarte }}</p>
        @endif

        {{-- Acciones --}}
        <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            @if ($s->estatus === 'pendiente')
                <button type="button" wire:click="tomar" wire:loading.attr="disabled" class="btn-primario">Tomar y cotizar</button>
            @elseif ($s->estatus === 'en_proceso' && ! $esMia && ! $esAdmin)
                <button type="button" disabled class="btn-primario cursor-not-allowed opacity-50">La está trabajando {{ $s->tomadaPor?->nombreReal() }}</button>
            @endif
            @if ($s->cotizacion && ($esMia || $esAdmin || $s->estatus === 'cotizada'))
                <a href="{{ route('cotizaciones.editar', $s->cotizacion) }}" class="btn-primario">Ir a la cotización {{ $s->cotizacion->folio }}</a>
            @endif
            @if ($puedeSoltar)
                <button type="button" wire:click="soltar" wire:confirm="¿Soltar la solicitud? Vuelve a pendientes y la cotización en borrador queda sin ligar." class="btn-secundario">Soltar</button>
            @endif
            @if ($puedeDescartar)
                <button type="button" wire:click="$toggle('descartando')" class="btn-secundario text-red-700">Descartar</button>
            @endif
        </div>

        @if ($s->estaAbierta() && ! count((array) $s->partidas) && filled($s->detalle))
            <p class="mt-3 rounded-lg bg-amber-50 p-3 text-base text-amber-900">
                Sin partidas. <a href="{{ route('solicitudes.editar', $s) }}" class="font-semibold underline">Acomodar en partidas con IA</a>
            </p>
        @endif
        @if ($s->estatus === 'pendiente' && ! $s->cliente_id)
            <p class="mt-3 rounded-lg bg-amber-50 p-3 text-base text-amber-900">Falta el cliente</p>
        @endif

        @if ($descartando && $puedeDescartar)
            <form wire:submit="descartar" class="mt-4 space-y-2 rounded-lg border border-red-200 bg-red-50 p-4">
                <label class="etiqueta" for="motivo">Motivo (mínimo 5 letras)</label>
                <input id="motivo" type="text" wire:model="motivo" class="campo" maxlength="500" placeholder="Ej. El cliente ya la compró en otro lado">
                <button type="submit" class="btn-primario">Confirmar descarte</button>
            </form>
        @endif

        @if ($esAdmin && $s->estatus === 'en_proceso')
            <form wire:submit="reasignar" class="mt-4 space-y-2 rounded-lg border border-slate-200 p-4">
                <label class="etiqueta" for="reasignar">Reasignar a otra persona (solo administrador)</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <select id="reasignar" wire:model="reasignarA" class="campo">
                        <option value="">Escoge…</option>
                        @foreach ($vendedores as $v)
                            @if ($v->id !== $s->tomada_por)<option value="{{ $v->id }}">{{ $v->name }}</option>@endif
                        @endforeach
                    </select>
                    <button type="submit" class="btn-secundario shrink-0">Reasignar</button>
                </div>
            </form>
        @endif

        {{-- Cliente sin dar de alta --}}
        @if ($s->estaAbierta() && ! $s->cliente_id)
            <div class="mt-4 space-y-3 rounded-lg border border-slate-200 p-4">
                <p class="text-base font-semibold text-marino">Cliente</p>
                @if ($s->cliente_texto)
                    <button type="button" wire:click="darDeAltaCliente" class="btn-secundario">Dar de alta «{{ $s->cliente_texto }}» como cliente</button>
                @endif
                <input type="search" wire:model.live.debounce.250ms="buscarCliente" class="campo" placeholder="O busca un cliente que ya existe" autocomplete="off" aria-label="Buscar cliente">
                @if ($resultados->isNotEmpty())
                    <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($resultados as $r)
                            <li wire:key="cli-{{ $r->id }}"><button type="button" wire:click="seleccionarCliente({{ $r->id }})" class="block min-h-12 w-full px-4 py-3 text-left text-base hover:bg-slate-50">{{ $r->nombre }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    <div class="mb-5 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-2 text-lg font-semibold text-marino">Qué pidió</h2>
        @if ($s->detalle)
            <p class="mb-3 whitespace-pre-line break-words text-base">{{ $s->detalle }}</p>
        @endif
        @if (count((array) $s->partidas))
            <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200">
                @foreach ($s->partidas as $p)
                    <li class="flex gap-3 px-3 py-2 text-base">
                        <span class="w-20 shrink-0 font-semibold">{{ filled($p['cantidad'] ?? null) ? rtrim(rtrim(number_format((float) $p['cantidad'], 3, '.', ','), '0'), '.') : '—' }} {{ $p['unidad'] ?? '' }}</span>
                        <span class="min-w-0 break-words">{{ $p['descripcion'] }} @if (filled($p['modelo'] ?? null))<span class="text-sm text-slate-500">· {{ $p['modelo'] }}</span>@endif
                            @if (! empty($p['ligada']))<span class="block text-xs text-green-700">Ligada a un producto ya cotizado (folio {{ $p['ligada']['folio'] ?? '' }})</span>@endif</span>
                    </li>
                @endforeach
            </ul>
        @elseif (! $s->detalle)
            <p class="text-slate-500">Sin detalle ni partidas todavía.</p>
        @endif

        @if ($s->archivos->isNotEmpty())
            <h3 class="mb-1 mt-4 text-base font-semibold text-marino">Archivos</h3>
            <div class="flex flex-wrap gap-3">
                @foreach ($s->archivos as $a)
                    <a href="{{ route('solicitudes.archivo', $a) }}" target="_blank" class="block max-w-full rounded-lg border border-slate-200 p-2 text-sm text-marino underline hover:bg-slate-50">
                        @if ($a->esImagen())<img src="{{ route('solicitudes.archivo', $a) }}" alt="{{ $a->nombre }}" class="mb-1 h-24 w-auto max-w-full rounded object-cover">@endif
                        <span class="break-all">{{ $a->nombre }}</span>
                    </a>
                @endforeach
            </div>
        @endif
        @if ($s->ia_estado === 'error')
            <p class="mt-3 text-sm text-red-700">La lectura con IA falló: {{ $s->ia_mensaje }}</p>
        @endif
    </div>

    <div class="rounded-xl bg-white p-5 shadow-sm">
        <h2 class="mb-2 text-lg font-semibold text-marino">Bitácora</h2>
        <ul class="space-y-2">
            @foreach ($s->eventos as $e)
                <li wire:key="ev-{{ $e->id }}" class="text-base"><span class="text-sm text-slate-500">{{ $e->created_at?->format('d/m/Y H:i') }}</span> · {{ $e->descripcion }}</li>
            @endforeach
        </ul>
    </div>
</div>
