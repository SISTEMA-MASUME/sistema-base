// Botón "WhatsApp" del componente Compartir (cotizaciones y OC a proveedores).
// Se manda el PDF como archivo (mucha gente no tiene permitido abrir links): en celular se comparte directo;
// en la computadora se descarga y se abre WhatsApp para adjuntarlo. El enlace seguro queda como opción.

/** Igual que App\Support\Telefono::whatsapp(): 52 + 10 dígitos, o null. */
export function numeroWhatsapp(texto) {
    let d = String(texto || '').replace(/\D+/g, '');
    if (d.startsWith('00')) d = d.slice(2);
    if (d.length === 13 && d.startsWith('521')) return '52' + d.slice(3);
    if (d.length === 12 && d.startsWith('52')) return d;
    if (d.length === 13 && (d.startsWith('044') || d.startsWith('045'))) d = d.slice(3);
    else if (d.length === 12 && d.startsWith('01')) d = d.slice(2);
    return d.length === 10 ? '52' + d : null;
}

window.numeroWhatsapp = numeroWhatsapp;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('compartirDocumento', ({ telefono, texto, enlace, urlPdf, nombrePdf, esImagen = false }) => ({
        whatsapp: false,
        telefono: telefono ? '+' + telefono : '',
        texto,
        archivo: null,
        puedeArchivo: false,
        pdfDescargado: false,
        copiado: false,
        nombrePdf,
        esImagen,
        imagenBlob: null,
        imagenCopiada: false,
        // Copiar imagen al portapapeles (computadora con Chrome/Edge).
        puedeCopiarImagen: esImagen && !!(navigator.clipboard && window.ClipboardItem),

        init() {
            // La imagen de la OC se baja desde antes: el portapapeles exige escribir justo después del clic.
            if (this.puedeCopiarImagen) {
                this.$watch('whatsapp', async (abierto) => {
                    if (!abierto || this.imagenBlob) return;
                    try {
                        const r = await fetch(urlPdf, { credentials: 'same-origin' });
                        if (r.ok) this.imagenBlob = await r.blob();
                    } catch (e) { /* queda descargar */ }
                });
            }
            // Se baja el PDF desde antes: compartir un archivo exige que sea justo después del toque.
            if (!navigator.canShare) return;
            this.$watch('whatsapp', async (abierto) => {
                if (!abierto || this.archivo) return;
                try {
                    const r = await fetch(urlPdf, { credentials: 'same-origin' });
                    if (!r.ok) return;
                    const blob = await r.blob();
                    const f = new File([blob], nombrePdf, { type: blob.type || 'application/pdf' });
                    if (navigator.canShare({ files: [f] })) {
                        this.archivo = f;
                        this.puedeArchivo = true;
                    }
                } catch (e) { /* sin archivo: queda el enlace */ }
            });
        },

        numero() {
            return numeroWhatsapp(this.telefono);
        },

        async compartirArchivo() {
            if (!this.archivo) return this.descargarYAbrir();
            // Se registra al tocar el botón (antes de abrir WhatsApp): en el celular la página puede quedarse
            // en segundo plano y la respuesta perderse, y la cotización seguía en "Borrador".
            this.$wire.registrarWhatsapp(this.telefono, 'archivo');
            try {
                await navigator.share({ files: [this.archivo], text: this.texto });
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                this.descargarYAbrir();
            }
        },

        abrirWhatsapp(conEnlace = true, forma = 'enlace') {
            const n = this.numero();
            const texto = conEnlace ? this.texto + '\n\nPDF (válido 30 días):\n' + enlace : this.texto;
            const url = 'https://wa.me/' + (n || '') + '?text=' + encodeURIComponent(texto);
            window.open(url, '_blank', 'noopener');
            this.$wire.registrarWhatsapp(this.telefono, forma);
        },

        /**
         * Computadora: copia la imagen de la OC al portapapeles y abre el chat de WhatsApp con el mensaje ya
         * escrito; ahí solo se pega (Ctrl+V) y se envía. Si el navegador no deja copiar, se descarga.
         */
        async copiarImagenYAbrir() {
            try {
                const blob = this.imagenBlob
                    || await (await fetch(urlPdf, { credentials: 'same-origin' })).blob();
                const png = blob.type === 'image/png' ? blob : new Blob([blob], { type: 'image/png' });
                await navigator.clipboard.write([new window.ClipboardItem({ 'image/png': png })]);
                this.imagenCopiada = true;
                this.abrirWhatsapp(false, 'copiada');
            } catch (e) {
                this.descargarYAbrir();
            }
        },

        /** Computadora: baja el PDF y abre WhatsApp con el mensaje (sin enlace) para adjuntarlo. */
        descargarYAbrir() {
            const a = document.createElement('a');
            a.href = urlPdf;
            a.download = nombrePdf;
            document.body.appendChild(a);
            a.click();
            a.remove();
            this.pdfDescargado = true;
            this.abrirWhatsapp(false, 'descargado');
        },

        async copiar() {
            try {
                await navigator.clipboard.writeText(this.texto);
                this.copiado = true;
                setTimeout(() => (this.copiado = false), 3000);
            } catch (e) {
                window.prompt('Copia el mensaje:', this.texto);
            }
        },
    }));
});
