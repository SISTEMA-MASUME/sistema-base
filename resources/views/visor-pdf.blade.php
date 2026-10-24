<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#12235a">
    <title>{{ $titulo }} · MASUME</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="manifest" href="/manifest.webmanifest">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; }
        header { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px 10px; background: #12235a; color: #fff; }
        .espacio { flex: 1; }
        header button, header a { display: inline-flex; align-items: center; justify-content: center; min-height: 46px; padding: 0 10px; border: 0; border-radius: 8px; font: 600 14px system-ui, -apple-system, sans-serif; text-decoration: none; cursor: pointer; }
        .regresar { background: #ef7d22; color: #fff; }
        .accion { background: rgba(255, 255, 255, .15); color: #fff; }
        .titulo { order: 9; flex-basis: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 14px; opacity: .85; }
        header button, header a { white-space: nowrap; }
        #paginas { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 12px 8px 32px; }
        #paginas canvas, #paginas img { width: 100%; max-width: 900px; height: auto; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .2); }
        #estado { padding: 32px 16px; text-align: center; font-size: 16px; }
    </style>
</head>
<body>
<header>
    <button type="button" class="regresar" id="regresar" aria-label="Regresar">← Regresar</button>
    <span class="espacio"></span>
    <span class="titulo">{{ $titulo }}</span>
    <button type="button" class="accion" id="compartir" hidden>Compartir</button>
    <a class="accion" href="{{ $descargar }}" download>Descargar</a>
</header>
<p id="estado">Abriendo…</p>
<div id="paginas"></div>

<script type="module">
// PDF.js 4.10 (corrige CVE-2024-4367 de la 3.x) y además sin evaluación de código (isEvalSupported: false).
const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/';
(() => {
    const url = @js($url);
    const volver = @js($volver);
    const nombre = @js($titulo.'.pdf');
    const estado = document.getElementById('estado');
    const paginas = document.getElementById('paginas');

    // Regresar a la pantalla de donde se abrió (si se llegó directo, a la dirección "volver").
    document.getElementById('regresar').addEventListener('click', () => {
        if (history.length > 1 && document.referrer && new URL(document.referrer).origin === location.origin) history.back();
        else location.href = volver;
    });

    // El aviso se arma con elementos (no con texto HTML): la dirección llega por la liga y no debe interpretarse.
    const mostrarError = () => {
        estado.textContent = 'No se pudo mostrar aquí. ';
        const a = document.createElement('a');
        a.href = url;
        a.textContent = 'Abrir el archivo';
        a.style.color = '#12235a';
        a.style.fontWeight = '600';
        estado.appendChild(a);
    };

    // Si en 20 s no se pudo dibujar (internet lento), se ofrece abrir el archivo directo.
    setTimeout(() => { if (document.getElementById('estado') && !paginas.children.length) mostrarError(); }, 20000);

    fetch(url, { credentials: 'same-origin' }).then(async (r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const blob = await r.blob();
        const tipo = blob.type || '';

        const btn = document.getElementById('compartir');
        const archivo = new File([blob], tipo.startsWith('image/') ? nombre.replace(/\.pdf$/i, '.' + tipo.split('/')[1]) : nombre, { type: tipo || 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [archivo] })) {
            btn.hidden = false;
            btn.addEventListener('click', () => navigator.share({ files: [archivo] }).catch(() => {}));
        }

        if (tipo.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(blob);
            img.alt = '';
            paginas.appendChild(img);
            estado.remove();
            return;
        }
        const pdfjsLib = await import(PDFJS + 'pdf.min.mjs');
        // El "worker" se baja y se usa desde un blob: un Worker de otro dominio (la CDN) el navegador no lo deja crear.
        const codigoWorker = await (await fetch(PDFJS + 'pdf.worker.min.mjs')).text();
        pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(new Blob([codigoWorker], { type: 'text/javascript' }));
        const pdf = await pdfjsLib.getDocument({ data: await blob.arrayBuffer(), isEvalSupported: false }).promise;
        estado.remove();
        const escala = Math.min(2.5, (window.devicePixelRatio || 1) * 1.5);
        for (let n = 1; n <= pdf.numPages; n++) {
            const pagina = await pdf.getPage(n);
            const vista = pagina.getViewport({ scale: escala });
            const canvas = document.createElement('canvas');
            canvas.width = vista.width;
            canvas.height = vista.height;
            paginas.appendChild(canvas);
            await pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vista }).promise;
        }
    }).catch(mostrarError);
})();
</script>
</body>
</html>
