@php $fecha = fn ($f) => $f?->timezone('America/Mexico_City')->format('d/m/Y H:i'); @endphp
<div class="space-y-4">
    @if ($mensaje)
        <div role="status" class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ $mensaje }}</div>
    @endif

    <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">Dirección del sistema para la IA</h2>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center" x-data="{ copiado: false }">
            <code class="flex-1 rounded-lg bg-slate-100 px-3 py-2 text-base">{{ $urlMcp }}</code>
            <button type="button" class="btn-secundario" x-on:click="navigator.clipboard.writeText(@js($urlMcp)).then(() => { copiado = true; setTimeout(() => copiado = false, 2500) })" x-text="copiado ? '✓ Copiada' : 'Copiar'">Copiar</button>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-2">
        <div class="space-y-2 rounded-xl bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-marino">Claude (claude.ai)</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
                <li>Entra a <strong>claude.ai</strong> → tu nombre → <strong>Configuración</strong> → <strong>Conectores</strong>.</li>
                <li><strong>Agregar conector personalizado</strong>. Nombre: <em>MASUME</em>. URL: la de arriba.</li>
                <li>Dale <strong>Conectar</strong>: se abre el sistema, entras con tu usuario y das <strong>Sí, permitir</strong>.</li>
            </ol>
        </div>
        <div class="space-y-2 rounded-xl bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-marino">ChatGPT</h3>
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
                <li>En <strong>chatgpt.com</strong> → <strong>Configuración</strong> → <strong>Aplicaciones y conectores</strong> (activa el "modo desarrollador" si te lo pide).</li>
                <li><strong>Crear</strong> conector. Nombre: <em>MASUME</em>. URL: la de arriba. Autenticación: <strong>OAuth</strong>.</li>
                <li>Se abre el sistema: entras con tu usuario y das <strong>Sí, permitir</strong>.</li>
            </ol>
        </div>
    </section>

    <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">Asistentes conectados</h2>
        <ul class="divide-y divide-slate-200">
            @forelse ($activas as $t)
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="mcp-{{ $t->id }}">
                    <div>
                        <p class="font-semibold">{{ $t->nombre }}</p>
                        <p class="text-sm text-slate-600">Desde {{ $fecha($t->created_at) }} · último uso: {{ $fecha($t->ultimo_uso_at) ?? 'todavía no' }}</p>
                    </div>
                    <button type="button" wire:click="revocar({{ $t->id }})" wire:confirm="¿Quitarle el acceso a {{ $t->nombre }}?" class="btn-secundario !text-red-600">Quitar acceso</button>
                </li>
            @empty
                <li class="py-4 text-sm text-slate-500">Todavía no hay ningún asistente conectado.</li>
            @endforelse
        </ul>
    </section>

    <section class="space-y-3 rounded-xl bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-marino">Llave personal</h2>
        @if ($llaveNueva)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3" x-data="{ copiado: false }">
                <p class="text-sm font-semibold text-amber-900">Cópiala ahora: no se vuelve a mostrar.</p>
                <code class="mt-1 block break-all rounded bg-white px-2 py-1 text-sm">{{ $llaveNueva }}</code>
                <button type="button" class="btn-secundario mt-2" x-on:click="navigator.clipboard.writeText(@js($llaveNueva)).then(() => { copiado = true })" x-text="copiado ? '✓ Copiada' : 'Copiar llave'">Copiar llave</button>
            </div>
        @else
            <button type="button" wire:click="crearLlave" class="btn-secundario">Crear llave personal</button>
        @endif
    </section>
</div>
