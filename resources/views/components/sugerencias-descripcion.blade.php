@props(['indice', 'clienteId'])

{{-- Uso: <x-sugerencias-descripcion :indice="$i" :cliente-id="$cot->cliente_id" /> (lógica en resources/js/sugerencias.js) --}}
<div class="relative" x-data="sugerenciasDescripcion({{ (int) $indice }}, {{ (int) $clienteId }})">
    {{-- f, editar() y salir() vienen de la partida (partidaEditor en resources/js/partidas.js). --}}
    <textarea rows="2" x-model="f.descripcion" name="partida_{{ $indice }}_descripcion" class="campo" autocomplete="off"
              x-on:focus="enfocado = 'descripcion'; cargar()"
              x-on:input="editar('descripcion'); buscar($event.target.value)"
              x-on:keydown="tecla($event)"
              x-on:blur="salir('descripcion'); setTimeout(() => cerrar(), 150)"></textarea>

    <ul x-show="abierto" x-cloak
        class="absolute left-0 right-0 z-30 mt-1 max-h-72 overflow-auto rounded-md border border-gray-300 bg-white text-sm shadow-lg">
        <template x-for="(s, n) in lista" :key="n">
            <li x-on:mousedown.prevent="elegir(s)" x-on:mouseenter="activo = n"
                x-bind:class="n === activo ? 'bg-naranja/10' : ''"
                class="flex cursor-pointer items-center gap-3 border-b border-gray-100 px-3 py-2 last:border-0">
                <template x-if="s.im"><img :src="s.im" alt="" loading="lazy" class="h-10 w-10 shrink-0 rounded border border-gray-200 object-contain"></template>
                <div class="min-w-0 flex-1">
                    <div class="text-marino"><span x-show="s.art" class="mr-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-bold text-green-800">Catálogo</span><span x-show="s.lr" class="mr-1 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-bold text-marino" x-text="'Lista ' + (s.lista || '')"></span><span x-html="resaltar(s.d)"></span></div>
                    <div class="text-xs text-gray-500" x-text="detalle(s)"></div>
                </div>
            </li>
        </template>
    </ul>
</div>
