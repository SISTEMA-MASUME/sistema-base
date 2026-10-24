// Campos de cada partida del cotizador.
//
// Antes eran wire:model.blur: si el servidor (Hostinger) tardaba en contestar el guardado de un campo,
// su respuesta pisaba lo que el usuario ya estaba escribiendo en el siguiente (se perdían letras y los
// números "se movían" de un campo a otro). Ahora el valor vive en el navegador (x-model) y del servidor
// solo se copian los campos que él calcula (costo con IVA, utilidad, precio…), nunca el que se está
// escribiendo ni uno que todavía no termina de guardarse.

document.addEventListener('alpine:init', () => {
    // Aviso "Guardando… / ✓ Guardado" de toda la cotización: cuenta los guardados que no han regresado, para que nadie
    // abra o mande el PDF antes de que se guarde el último precio.
    window.Alpine.store('guardado', {
        pendientes: 0,
        error: false,
        listo: false,      // se guardó algo hace poco (muestra "✓ Guardado" un rato)
        timer: null,
        inicio() {
            this.pendientes++;
            this.error = false;
            this.listo = false;
        },
        fin(ok) {
            this.pendientes = Math.max(0, this.pendientes - 1);
            if (!ok) this.error = true;
            if (this.pendientes === 0 && !this.error) {
                this.listo = true;
                clearTimeout(this.timer);
                this.timer = setTimeout(() => (this.listo = false), 4000);
            }
        },
    });

    window.Alpine.data('partidaEditor', (id, inicial, abierta) => ({
        id,
        abierta,
        f: { ...inicial },
        sucios: {},      // se escribió y aún no se manda (sin salir del campo)
        enviando: {},    // se mandó y aún no regresa la respuesta
        enfocado: null,

        init() {
            // Cada vez que cambia el estado del servidor (respuesta de Livewire) se sincroniza.
            this.$watch('$wire.partidas', () => this.sincronizar());
        },

        idx() {
            return (this.$wire.partidas || []).findIndex((p) => Number(p.id) === Number(this.id));
        },

        sincronizar() {
            const s = (this.$wire.partidas || [])[this.idx()];
            if (!s) return;
            for (const k of Object.keys(this.f)) {
                // Solo se respeta lo que el usuario escribió (sucio) o lo que aún no termina de guardarse;
                // un campo enfocado pero sin tocar sí se actualiza (p. ej. el teléfono del vendedor guardado).
                if (this.sucios[k] || this.enviando[k]) continue;
                const v = s[k] ?? '';
                if (String(this.f[k] ?? '') !== String(v)) this.f[k] = v;
            }
        },

        editar(k) {
            this.sucios[k] = true;
        },

        async salir(k) {
            this.enfocado = null;
            // Sin cambios: por si el servidor llenó este campo mientras estaba enfocado (p. ej. el teléfono
            // del vendedor que ya estaba guardado).
            if (!this.sucios[k]) return this.sincronizar();
            await this.mandar({ [k]: this.f[k] });
        },

        /**
         * Se eligió una opción de la lista (proveedor o vendedor guardado): se guarda en ese momento, sin
         * esperar a salir del campo, para que el teléfono del vendedor se llene solo luego luego.
         */
        elegidoDeLista(k, el) {
            const opciones = el?.list ? [...el.list.options].map((o) => o.value) : [];
            if (this.sucios[k] && opciones.includes(this.f[k])) {
                this.mandar({ [k]: this.f[k] });
            }
        },

        /** Manda uno o varios campos en una sola petición y espera la respuesta. */
        async mandar(valores) {
            for (const k of Object.keys(valores)) {
                delete this.sucios[k];
                this.enviando[k] = true;
            }
            const aviso = window.Alpine.store('guardado');
            aviso.inicio();
            let ok = false;
            try {
                // Llamada con los valores (no $wire.set): una respuesta anterior no puede borrarlos.
                await this.$wire.guardarCampos(this.id, valores);
                ok = true;
            } finally {
                for (const k of Object.keys(valores)) delete this.enviando[k];
                aviso.fin(ok);
                this.sincronizar();
            }
        },

        /** Se eligió una sugerencia de descripción: se llenan los campos y se guardan juntos. */
        aplicar(valores) {
            for (const [k, v] of Object.entries(valores)) {
                if (k in this.f) this.f[k] = v;
            }
            this.mandar(valores);
        },
    }));
});
