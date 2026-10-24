// Solo se usa en la pantalla de revisión; el cotizador conserva su captura habitual.
window.utilidadRevision = (partidaId, valor, version) => ({
    partidaId, valor, version,
    editando: false,
    guardando: false,
    error: '',
    iniciar(boton) {
        if (this.editando || this.guardando) return;
        this.valor = boton.dataset.valor;
        this.version = boton.dataset.version;
        this.error = '';
        this.editando = true;
        this.$dispatch('utilidad-edicion', { cambio: 1 });
        this.$nextTick(() => {
            this.$refs.porcentaje.focus();
            this.$refs.porcentaje.select();
        });
    },
    cancelar() {
        if (!this.editando || this.guardando) return;
        this.editando = false;
        this.error = '';
        this.$dispatch('utilidad-edicion', { cambio: -1 });
    },
    async guardar() {
        if (!this.editando || this.guardando) return;
        this.guardando = true;
        this.error = '';
        try {
            const resultado = await this.$wire.guardarUtilidad(this.partidaId, this.valor, this.version);
            if (resultado) {
                this.valor = resultado.valor;
                this.version = resultado.version;
                this.editando = false;
                this.$dispatch('utilidad-edicion', { cambio: -1 });
            }
        } catch {
            this.error = 'No se pudo guardar. Revisa tu conexión e inténtalo otra vez.';
        } finally {
            this.guardando = false;
        }
    },
});
