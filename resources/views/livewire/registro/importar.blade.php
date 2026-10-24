<div class="space-y-4">
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    @if (! $datos)
        <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">1. REGISTRO_COTIZACIONES_MASUME: Archivo → Descargar → Excel (.xlsx)</h2>
            <h2 class="pt-2 text-lg font-semibold text-marino">2. Súbelo aquí</h2>
            <input type="file" wire:model="archivo" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="text-base">
            <div wire:loading wire:target="archivo" class="text-sm text-slate-600">Leyendo el Excel…</div>
            @error('archivo') <p class="error-campo">{{ $message }}</p> @enderror
        </section>
    @else
        <section class="space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-marino">3. Revisa antes de importar</h2>

            @if ($datos['faltan'] !== [])
                <div class="rounded-lg border border-red-300 bg-red-50 p-3 text-red-800">
                    <p class="font-semibold">No puedo importar este archivo:</p>
                    <ul class="list-disc pl-5">@foreach ($datos['faltan'] as $f)<li>{{ $f }}</li>@endforeach</ul>
                </div>
            @else
                <dl class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-lg bg-green-50 p-3"><dt class="text-sm text-slate-600">Cotizaciones nuevas</dt><dd class="text-2xl font-bold text-green-800">{{ count($previa['nuevas']) }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Partidas nuevas</dt><dd class="text-2xl font-bold">{{ $previa['partidas'] }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Ya estaban en el sistema</dt><dd class="text-2xl font-bold">{{ $previa['existentes'] }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-sm text-slate-600">Renglones que no son cotización (OC internas)</dt><dd class="text-2xl font-bold">{{ $previa['ignoradas'] }}</dd></div>
                </dl>

                @if ($previa['nuevas'] !== [])
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">Folio</th><th class="px-3 py-2">Cliente</th><th class="px-3 py-2">Vendedor</th><th class="px-3 py-2">Fecha</th><th class="px-3 py-2 text-right">Partidas</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach (array_slice(array_reverse($previa['nuevas']), 0, 30) as $n)
                                    <tr><td class="px-3 py-1 font-semibold">{{ $n['folio'] }}</td><td class="px-3 py-1">{{ $n['cliente'] }}</td><td class="px-3 py-1">{{ $n['responsable'] }}</td><td class="px-3 py-1">{{ $n['fecha'] }}</td><td class="px-3 py-1 text-right">{{ $n['partidas'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if (count($previa['nuevas']) > 30)<p class="mt-1 text-xs text-slate-500">…y {{ count($previa['nuevas']) - 30 }} más (se muestran las más recientes).</p>@endif
                    </div>
                @endif

                <div class="rounded-lg border border-amber-300 bg-amber-50 p-3">
                    <label class="flex items-start gap-3 text-base">
                        <input type="checkbox" wire:model.live="ajustarConsecutivo" class="mt-1 h-5 w-5 rounded border-slate-300">
                        <span>
                            Seguir el consecutivo del REGISTRO: la siguiente será la <strong>{{ $previa['siguiente'] }}</strong> (hoy va en la {{ $previa['contador'] }})
                        </span>
                    </label>
                </div>

                <details class="text-sm text-slate-600">
                    <summary class="cursor-pointer">Columnas que reconocí</summary>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($datos['columnas'] as $hoja => $cols)
                            <li><strong>{{ $hoja }}:</strong> @foreach ($cols as $campo => $encabezado){{ $campo }} ← “{{ $encabezado }}”@if (! $loop->last), @endif @endforeach</li>
                        @endforeach
                    </ul>
                    <p class="mt-1">Consecutivo en CONFIG!B2: {{ $datos['consecutivo'] ?: 'no encontrado' }}</p>
                </details>
            @endif

            <div class="flex flex-col gap-2 sm:flex-row">
                @if ($datos['faltan'] === [])
                    <button type="button" wire:click="importar" wire:loading.attr="disabled"
                            wire:confirm="¿Importar {{ count($previa['nuevas']) }} cotizaciones{{ $ajustarConsecutivo ? ' y cambiar el consecutivo a '.$previa['siguiente'] : '' }}?"
                            class="btn-primario">Importar</button>
                @endif
                <button type="button" wire:click="cancelar" class="btn-secundario">Subir otro archivo</button>
            </div>
            <div wire:loading wire:target="importar" class="text-sm text-slate-600">Importando… no cierres la página.</div>
        </section>
    @endif
</div>
