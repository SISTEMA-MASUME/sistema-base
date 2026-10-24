import Sortable from 'sortablejs';

let arrastrando = false;

function idComponente(el) {
    return el?.closest('[wire\\:id]')?.getAttribute('wire:id');
}

function iniciar() {
    document.querySelectorAll('[data-tablero] [data-columna]').forEach((lista) => {
        if (lista._sortable) {
            return;
        }
        lista._sortable = Sortable.create(lista, {
            group: 'pedidos',
            animation: 150,
            draggable: '[data-id]',
            ghostClass: 'opacity-40',
            chosenClass: 'rotate-2',
            // Celular: arrastrar con pulsación larga (como la app de Trello); un toque normal abre la tarjeta
            // y deslizar mueve el tablero de lado.
            delay: 250,
            delayOnTouchOnly: true,
            touchStartThreshold: 6,
            scroll: true,
            scrollSensitivity: 80,
            bubbleScroll: true,
            onStart: () => { arrastrando = true; if (navigator.vibrate) { navigator.vibrate(15); } },
            onEnd: (e) => {
                // Evita que el clic que suelta la tarjeta la abra.
                window.__finArrastre = Date.now();
                const destino = e.to;
                const id = Number(e.item.dataset.id);
                const orden = [...destino.querySelectorAll('[data-id]')].map((el) => Number(el.dataset.id));
                const comp = window.Livewire?.find(idComponente(destino));
                Promise.resolve(comp?.mover(id, destino.dataset.columna, orden)).finally(() => { arrastrando = false; });
            },
        });
    });
}

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(iniciar)));
});
document.addEventListener('livewire:navigated', iniciar);
document.addEventListener('DOMContentLoaded', iniciar);

// Refresco cada 60 s para ver movimientos de otros, sin interrumpir un arrastre en curso.
setInterval(() => {
    // No refresca mientras se arrastra, con la pestaña oculta o con una tarjeta abierta (para no estorbar al escribir).
    if (arrastrando || document.hidden || document.querySelector('.modal-tarjeta')) {
        return;
    }
    const id = idComponente(document.querySelector('[data-tablero]'));
    if (id) {
        window.Livewire?.find(id)?.$refresh();
    }
}, 60000);
