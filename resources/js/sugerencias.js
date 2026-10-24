// Sugerencias de descripción de partidas (componente <x-sugerencias-descripcion>).
// El JSON del cliente se pide una sola vez por página y se comparte en window.__sugerenciasCliente.

const norm = (t) => (t || '').toString().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const HTML = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (t) => (t || '').toString().replace(/[&<>"']/g, (c) => HTML[c]);
const dinero = (n) => '$' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

document.addEventListener('alpine:init', () => {
    window.Alpine.data('sugerenciasDescripcion', (indice, clienteId) => ({
        i: indice,
        cid: clienteId,
        abierto: false,
        lista: [],
        activo: 0,
        q: '',
        timer: null,
        globalTimer: null,
        articulosTimer: null,

        async cargar() {
            window.__sugerenciasCliente = window.__sugerenciasCliente || {};
            if (window.__sugerenciasCliente[this.cid] !== undefined) return;
            window.__sugerenciasCliente[this.cid] = null; // cargando
            try {
                const r = await fetch('/clientes/' + this.cid + '/sugerencias', { headers: { Accept: 'application/json' } });
                const datos = r.ok ? await r.json() : [];
                window.__sugerenciasCliente[this.cid] = datos.map((s) => ({ ...s, _n: norm(s.d) }));
            } catch (e) {
                delete window.__sugerenciasCliente[this.cid];
            }
        },

        buscar(texto) {
            this.q = texto;
            clearTimeout(this.timer);
            clearTimeout(this.globalTimer);
            clearTimeout(this.articulosTimer);
            this.timer = setTimeout(() => this.filtrar(), 120);
        },

        palabras() {
            return norm(this.q).split(/\s+/).filter(Boolean);
        },

        filtrar() {
            const ps = this.palabras();
            if (this.q.trim().length < 2 || !ps.length) return this.cerrar();
            // "¿Es este?": los artículos del catálogo van primero (el vendedor elige; nunca se elige solo).
            if (this.q.trim().length >= 3) {
                const consulta = this.q;
                this.articulosTimer = setTimeout(() => this.articulos(consulta), 200);
            }
            const base = (window.__sugerenciasCliente && window.__sugerenciasCliente[this.cid]) || [];
            const res = [];
            for (const s of base) {
                if (ps.every((p) => s._n.includes(p))) {
                    res.push(s);
                    if (res.length >= 8) break;
                }
            }
            this.lista = res;
            this.activo = 0;
            this.abierto = res.length > 0;
            if (res.length < 3 && this.q.trim().length >= 4) {
                const consulta = this.q;
                this.globalTimer = setTimeout(() => this.globales(consulta), 250);
            }
        },

        async globales(consulta) {
            try {
                const r = await fetch('/sugerencias/global?q=' + encodeURIComponent(consulta), { headers: { Accept: 'application/json' } });
                if (!r.ok || consulta !== this.q) return;
                const ya = new Set(this.lista.map((s) => s._n));
                for (const s of await r.json()) {
                    s._n = norm(s.d);
                    if (!ya.has(s._n) && this.lista.length < 8) this.lista.push(s);
                }
                this.abierto = this.lista.length > 0;
            } catch (e) {
                /* sin sugerencias globales */
            }
        },

        async articulos(consulta) {
            try {
                const r = await fetch('/sugerencias/articulos?q=' + encodeURIComponent(consulta), { headers: { Accept: 'application/json' } });
                if (!r.ok || consulta !== this.q) return;
                const arts = (await r.json()).map((s) => ({ ...s, _n: norm(s.d) }));
                if (!arts.length) return;
                const ya = new Set(arts.map((s) => s._n));
                this.lista = [...arts, ...this.lista.filter((s) => !s.art && !s.lr && !ya.has(s._n))].slice(0, 8);
                this.activo = 0;
                this.abierto = true;
            } catch (e) {
                /* sin artículos del catálogo */
            }
        },

        resaltar(texto) {
            texto = (texto || '').toString();
            const plano = norm(texto);
            if (plano.length !== texto.length) return esc(texto); // el acento cambió la longitud
            const rangos = [];
            for (const p of this.palabras()) {
                let desde = 0;
                let k;
                while ((k = plano.indexOf(p, desde)) !== -1) {
                    rangos.push([k, k + p.length]);
                    desde = k + p.length;
                }
            }
            rangos.sort((a, b) => a[0] - b[0]);
            let out = '';
            let ult = 0;
            for (const [a, b] of rangos) {
                if (a < ult) continue;
                out += esc(texto.slice(ult, a)) + '<mark class="bg-naranja/30 rounded-sm">' + esc(texto.slice(a, b)) + '</mark>';
                ult = b;
            }
            return out + esc(texto.slice(ult));
        },

        detalle(s) {
            if (s.lr) {
                const l = ['Lista ' + s.lista + ' · clave ' + s.mo];
                if (s.c > 0) l.push('tu costo ' + dinero(s.c));
                l.push('aún no se ha comprado');
                return l.join(' · ');
            }
            if (s.art) {
                const a = [s.provs ? 'Comprado con ' + s.provs : s.pr || 'Sin proveedor aún'];
                if (s.p > 0) a.push('vendido a ' + dinero(s.p));
                if (s.ma) a.push(s.ma);
                return a.join(' · ');
            }
            const t = ['último precio ' + dinero(s.p)];
            if (s.mo) t.push('modelo ' + s.mo);
            if (s.im) t.push('📷 ya tiene imagen');
            if (s.f) t.push('cot. ' + s.f);
            if (s.fe) t.push(s.fe);
            if (s.pr) t.push(s.pr);
            if (s.cl) t.push('otro cliente: ' + s.cl);
            return t.join(' · ');
        },

        elegir(s) {
            // La partida (partidaEditor en partidas.js) llena sus campos y los guarda juntos.
            const valores = {};
            const poner = (campo, valor) => {
                if (valor !== null && valor !== undefined && valor !== '') valores[campo] = valor;
            };
            const costo = s.c > 0;
            const util = s.ut !== null && s.ut !== undefined;
            poner('descripcion', s.d);
            poner('unidad', s.u);
            poner('modelo', s.mo);
            poner('marca', s.ma);
            poner('proveedor', s.pr);
            poner('proveedor_vendedor', s.pv);
            poner('link_compra', s.lk);
            poner('proveedor_telefono', s.pt);
            if (costo) poner('costo_sin', String(s.c));
            if (costo && util) poner('utilidad_pct', String(s.ut)); // el servidor recalcula el precio
            poner('tiempo_entrega', s.te);
            if (!(costo && util) && s.p > 0) poner('precio', Number(s.p).toFixed(2));
            if (s.art) poner('articulo_id', String(s.art)); // queda ligada al artículo del catálogo
            if (s.lr) poner('lista_renglon', String(s.lr)); // nace el artículo desde la lista de precios
            this.cerrar();
            this.$dispatch('partida-sugerencia', valores);
        },

        cerrar() {
            this.abierto = false;
            this.lista = [];
            clearTimeout(this.timer);
            clearTimeout(this.globalTimer);
            clearTimeout(this.articulosTimer);
        },

        tecla(e) {
            if (!this.abierto || !this.lista.length) return; // no roba Enter con la lista cerrada
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.activo = (this.activo + 1) % this.lista.length;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.activo = (this.activo - 1 + this.lista.length) % this.lista.length;
            } else if (e.key === 'Enter') {
                e.preventDefault();
                this.elegir(this.lista[this.activo]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.cerrar();
            }
        },
    }));
});
