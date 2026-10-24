{{--
    Layout principal. Dos formas de usarlo:

    1) Vista Blade normal:
         @extends('layouts.app')
         @section('titulo', 'Clientes')
         @section('contenido') ... @endsection

    2) Componente Livewire de página completa (Livewire 3):
         #[Layout('layouts.app')]
         #[Title('Clientes')]
         class Index extends Component { ... }
       (el contenido llega en $slot y el título en $title)
--}}
@php
    $tituloPagina = $title ?? trim($__env->yieldContent('titulo'));
    $usuario = auth()->user();
    $menuPedidos = ['Pedidos', '/pedidos', 'pedidos*', 'M4 5h4v14H4zM10 5h4v9h-4zM16 5h4v6h-4z'];
    // Por recoger: globito con las partidas que el proveedor entregó a medias.
    $menuPorRecoger = null;
    if ($usuario && ! $usuario->esNed()) {
        try {
            $parcialesRecoger = \App\Support\PorRecoger::parciales();
        } catch (\Throwable) {
            $parcialesRecoger = 0;
        }
        $menuPorRecoger = ['Por recoger', '/por-recoger', 'por-recoger*', 'M3 7h13v10H3zM16 10h4l1 3v4h-5M7 18a1.6 1.6 0 100 .1M18 18a1.6 1.6 0 100 .1', $parcialesRecoger];
    }
    $menu = $usuario?->esNed() ? [
        ['Mis cotizaciones', '/ned', 'ned', 'M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v14l-3-2-2 2-2-2-2 2-2-2-3 2V6a2 2 0 012-2z'],
        ['Mis datos y logo', '/ned/datos', 'ned/datos', 'M12 11a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0'],
    ] : ($usuario?->esCampo() ? [$menuPedidos, $menuPorRecoger] : [
        ['Cotizaciones', '/cotizaciones', 'cotizaciones*', 'M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v14l-3-2-2 2-2-2-2 2-2-2-3 2V6a2 2 0 012-2z'],
        $menuPedidos,
        $menuPorRecoger,
        ['Facturación', '/facturacion', 'facturacion*', 'M9 14l2 2 4-4M7 3h8l4 4v12a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z'],
        ['Alta en SICAR', '/sicar/alta', 'sicar/alta', 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zm0 0v5c0 1.7 3.6 3 8 3s8-1.3 8-3V7m-16 5v5c0 1.7 3.6 3 8 3s8-1.3 8-3v-5'],
        ['Lotes SICAR', '/sicar/lotes', 'sicar/lotes', 'M5 13l4 4L19 7'],
        ['OC a proveedores', '/ordenes-proveedor', 'ordenes-proveedor*', 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
        ['Clientes', '/clientes', 'clientes*', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-4.13a4 4 0 11-8 0 4 4 0 018 0zm6 0a3 3 0 11-6 0 3 3 0 016 0z'],
        ['Proveedores', '/proveedores', 'proveedores*', 'M3 7l9-4 9 4-9 4-9-4zm0 5l9 4 9-4M3 17l9 4 9-4'],
        ['Artículos', '/articulos', 'articulos*', 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['Catálogo de imágenes', '/catalogo-imagenes', 'catalogo-imagenes*', 'M4 5h16v14H4zM4 15l4-4 4 4 3-3 5 5M15 9h.01'],
    ]);
    // Bandeja "Por cotizar" (admin y vendedores): globito con las pendientes (una consulta con índice).
    if ($usuario && ! $usuario->esNed() && ! $usuario->esCampo()) {
        try {
            $porCotizar = \App\Models\SolicitudCotizacion::where('estatus', 'pendiente')->count();
        } catch (\Throwable) {
            $porCotizar = 0;
        }
        array_unshift($menu, ['Por cotizar', '/por-cotizar', 'por-cotizar*', 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-4l-1 2H9l-1-2H4', $porCotizar]);
    }
    // Finanzas: solo el admin o a quien él le dio permiso (Usuarios → palomitas).
    if ($usuario && ! $usuario->esNed() && $usuario->puede('tablero')) {
        $menu[] = ['Dashboard', '/finanzas/tablero', 'finanzas/tablero*', 'M4 20V10m6 10V4m6 16v-7m4 7H2'];
    }
    if ($usuario && ! $usuario->esNed() && $usuario->puede('ventas')) {
        $menu[] = ['Ventas', '/finanzas/ventas', 'finanzas/ventas*', 'M12 3v18m4-14H10a3 3 0 000 6h4a3 3 0 010 6H7'];
        try {
            $pendientesDeposito = \App\Models\DepositoCobro::whereIn('estatus', ['provisional', 'revisar'])->count();
        } catch (\Throwable) {
            $pendientesDeposito = 0;
        }
        $menu[] = ['Depósitos', '/finanzas/depositos', 'finanzas/depositos*', 'M3 10h18M7 15h2m4 0h4M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z', $pendientesDeposito];
    }
    if ($usuario && ! $usuario->esNed() && $usuario->puede('cobranza')) {
        $menu[] = ['Cobranza', '/finanzas/cobranza', 'finanzas/cobranza*', 'M3 10h18M7 15h2m4 0h4M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z'];
    }
    if ($usuario && ! $usuario->esNed() && $usuario->puede('nomina')) {
        $menu[] = ['Nómina', '/finanzas/nomina', 'finanzas/nomina*', 'M12 8c-1.7 0-3 .9-3 2s1.3 2 3 2 3 .9 3 2-1.3 2-3 2m0-8V6m0 10v2m9-6a9 9 0 11-18 0 9 9 0 0118 0z'];
    }
    if ($usuario && ! $usuario->esNed() && $usuario->puede('gastos')) {
        $menu[] = ['Gastos', '/finanzas/gastos', 'finanzas/gastos*', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'];
    }
    if ($usuario && ! $usuario->esNed() && $usuario->puede('finanzas')) {
        $menu[] = ['Mis finanzas', '/finanzas/mis-finanzas', 'finanzas/mis-finanzas*', 'M3 7h18v12H3zM3 7l3-3h12l3 3M16 13h2'];
    }
    // A quién le debo (compras a crédito): globito con cuántas deudas a proveedores siguen pendientes.
    if ($usuario?->esAdmin()) {
        try {
            $porPagar = \App\Models\CuentaPorPagar::where('estatus', 'pendiente')->count();
        } catch (\Throwable) {
            $porPagar = 0;
        }
        $menu[] = ['Metas con proveedores', '/programas', 'programas*', 'M3 17l6-6 4 4 8-8M14 7h7v7'];
        $menu[] = ['A quién le debo', '/finanzas/por-pagar', 'finanzas/por-pagar*', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z', $porPagar];
    }
    if ($usuario && ! $usuario->esNed()) {
        $menu[] = ['Vacaciones', '/vacaciones', 'vacaciones*', 'M3 20h18M6 20v-6l6-8 6 8v6M12 6V3'];
    }
    if ($usuario?->nedDesdeMasume()) {
        $menu[] = ['Ned Tracking', '/ned', 'ned*', 'M9 12h6m-6 4h6M7 4h10a2 2 0 012 2v14l-3-2-2 2-2-2-2 2-2-2-3 2V6a2 2 0 012-2z'];
    }
    if ($usuario?->esAdmin()) {
        try {
            $preciosPorAutorizar = \App\Models\CambioPrecio::where('estatus', 'pendiente')->count();
        } catch (\Throwable) {
            $preciosPorAutorizar = 0;
        }
        $menu[] = ['Precios por autorizar', '/cambios-precio', 'cambios-precio*', 'M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z', $preciosPorAutorizar];
        $menu[] = ['Duplicados', '/duplicados', 'duplicados*', 'M8 7h8M8 12h8m-8 5h5M4 4h16v16H4z'];
        $menu[] = ['Conexión con IA', '/conexion-ia', 'conexion-ia*', 'M13 10V3L4 14h7v7l9-11h-7z'];
        // "Importar REGISTRO" ya no va en el menú (Daniel ya no lo usa, 14-oct-2026); la pantalla sigue en /importar-registro.
        $menu[] = ['Usuarios', '/usuarios', 'usuarios*', 'M12 11a4 4 0 100-8 4 4 0 000 8zm-7 9a7 7 0 0114 0'];
        try {
            $erroresPendientes = \App\Models\ErrorSistema::where('resuelto', false)->count();
        } catch (\Throwable) {
            $erroresPendientes = 0;
        }
        $menu[] = ['Errores', '/errores', 'errores*', 'M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z', $erroresPendientes];
        $menu[] = ['Salud', '/salud', 'salud*', 'M4.3 12.7L12 20l7.7-7.3a5 5 0 00-7.1-7L12 6l-.6-.6a5 5 0 00-7.1 7.3z'];
    }
    // Palabras extra para el buscador de módulos (además del nombre), por ruta.
    $menuPalabras = [
        '/por-cotizar' => 'solicitudes pendientes pedir cotizacion', '/cotizaciones' => 'cotizar presupuesto folio',
        '/pedidos' => 'tablero trello oc cliente entregas', '/por-recoger' => 'recoger proveedor parcial faltan',
        '/facturacion' => 'facturas portal reglas', '/sicar/alta' => 'articulos productos sicar', '/sicar/lotes' => 'sicar alta lotes',
        '/ordenes-proveedor' => 'oc compras proveedor', '/clientes' => 'empresas contactos', '/proveedores' => 'contactos compras',
        '/catalogo-imagenes' => 'fotos imagenes productos', '/articulos' => 'catalogo productos inventario clave sat codigo barras', '/finanzas/tablero' => 'tablero utilidad graficas corte',
        '/finanzas/ventas' => 'facturas notas cobradas', '/finanzas/depositos' => 'depositos pagos complementos rep banco',
        '/finanzas/cobranza' => 'cobrar vencidas clientes deben', '/finanzas/nomina' => 'sueldos comisiones prestamos reparto empleados pagar',
        '/finanzas/gastos' => 'gastos masume categorias', '/finanzas/mis-finanzas' => 'patrimonio tarjetas saldos cuentas bancos',
        '/finanzas/por-pagar' => 'proveedores credito deudas por pagar', '/programas' => 'urrea programa comercial trimestre descuento bono metas',
        '/vacaciones' => 'dias descanso', '/ned' => 'ned tracking pedro', '/cambios-precio' => 'precios autorizar cambios',
        '/duplicados' => 'unir clientes proveedores repetidos', '/conexion-ia' => 'claude chatgpt mcp ia', '/usuarios' => 'permisos empleados contraseñas',
        '/errores' => 'fallas problemas', '/salud' => 'servidor revision estado',
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tituloPagina ? $tituloPagina.' · ' : '' }}MASUME</title>
    <link rel="icon" href="/favicon.ico">
    @include('partials.app-movil')
    <link rel="preload" href="/fonts/instrument-sans-latin.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-fondo font-sans text-slate-900 antialiased">
<div x-data="{ abierto: false }" class="min-h-screen lg:flex">

    {{-- Barra superior (celular) --}}
    <header class="sticky top-0 z-30 flex items-center justify-between bg-marino px-4 py-3 text-white lg:hidden">
        <a href="{{ url('/') }}" class="flex items-center gap-3" aria-label="MASUME Industrial — inicio">
                <img src="/logo-masume.png" alt="" class="h-8 w-auto">
                <span class="border-l border-white/25 pl-3 leading-none" style="font-family:'Barlow Condensed',sans-serif">
                    <span class="block text-xl font-bold uppercase tracking-wide text-white">Masume</span>
                    <span class="mt-1 block text-[9px] font-semibold uppercase tracking-[0.3em] text-naranja">Industrial</span>
                </span>
            </a>
        @unless ($usuario?->esNed())
        <form class="mx-2 min-w-0 flex-1" x-data="{ folio: '' }"
              @submit.prevent="if (folio.trim() !== '') window.location = '{{ url('/ir') }}/' + encodeURIComponent(folio.trim())">
            <input type="search" x-model="folio" autocapitalize="characters" placeholder="Ir a folio" aria-label="Ir a folio: número de cotización, P-45, OC-12 o S-8" title="Cotización (90123), pedido (P-45), orden (OC-12) o solicitud (S-8)"
                   class="w-full rounded-lg border-0 bg-white/15 px-3 py-2 text-base text-white placeholder-white/60 focus:bg-white focus:text-slate-900 focus:placeholder-slate-400">
        </form>
        @endunless
        <button type="button" @click="abierto = !abierto" aria-label="Abrir menú"
                class="flex h-12 w-12 items-center justify-center rounded-lg hover:bg-white/10">
            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
    </header>

    {{-- Fondo oscuro al abrir el menú en celular --}}
    <div x-show="abierto" x-cloak @click="abierto = false" class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

    {{-- Barra lateral --}}
    <aside :class="abierto ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-marino text-white transition-transform lg:translate-x-0">
        <div class="hidden shrink-0 px-6 py-6 lg:block [@media(max-height:820px)]:py-3">
            <a href="{{ url('/') }}" class="flex items-center gap-3" aria-label="MASUME Industrial — inicio">
                <img src="/logo-masume.png" alt="" class="h-12 w-auto">
                <span class="border-l border-white/25 pl-3 leading-none" style="font-family:'Barlow Condensed',sans-serif">
                    <span class="block text-3xl font-bold uppercase tracking-wide text-white">Masume</span>
                    <span class="mt-1 block text-[11px] font-semibold uppercase tracking-[0.3em] text-naranja">Industrial</span>
                </span>
            </a>
        </div>
        @unless ($usuario?->esNed())
        <form class="hidden shrink-0 px-4 pb-3 lg:block" x-data="{ folio: '' }"
              @submit.prevent="if (folio.trim() !== '') window.location = '{{ url('/ir') }}/' + encodeURIComponent(folio.trim())">
            <input type="search" x-model="folio" autocapitalize="characters" placeholder="Ir a folio" aria-label="Ir a folio: número de cotización, P-45, OC-12 o S-8" title="Cotización (90123), pedido (P-45), orden (OC-12) o solicitud (S-8)"
                   class="w-full rounded-lg border-0 bg-white/15 px-3 py-2 text-base text-white placeholder-white/60 focus:bg-white focus:text-slate-900 focus:placeholder-slate-400">
        </form>
        @endunless
        {{-- Buscar módulo: filtra el menú mientras se escribe (sin acentos); Enter abre el primero que coincide. --}}
        <div class="contents" x-data="{
                q: '',
                limpio(t) { return (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim() },
                coincide(t) { const q = this.limpio(this.q); return q === '' || q.split(/\s+/).every((p) => this.limpio(t).includes(p)) },
                hay() { return this.q.trim() === '' || [...this.$refs.menu.querySelectorAll('a[data-buscar]')].some((a) => this.coincide(a.dataset.buscar)) },
                abrirPrimero() { const a = [...this.$refs.menu.querySelectorAll('a[data-buscar]')].find((a) => this.coincide(a.dataset.buscar)); if (a) window.location = a.href },
             }">
        @if (count(array_filter($menu)) > 6)
        <div class="shrink-0 px-4 pb-3 pt-4 lg:pt-0" role="search">
            <input type="search" x-model="q" @keydown.enter.prevent="abrirPrimero()" @keydown.escape="q = ''" placeholder="Buscar módulo…" aria-label="Buscar módulo"
                   class="w-full rounded-lg border-0 bg-white/15 px-3 py-2 text-base text-white placeholder-white/60 focus:bg-white focus:text-slate-900 focus:placeholder-slate-400" data-buscar-modulo>
        </div>
        @endif
        {{-- En pantallas bajitas (laptops) el menú no cabe: se desplaza con su propia barra. --}}
        <nav class="menu-lateral min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 {{ count(array_filter($menu)) > 6 ? '' : 'mt-4 lg:mt-0' }}" x-ref="menu"
             x-init="$el.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'nearest' })">
            @foreach ($menu as $item)
                @php([$texto, $ruta, $patron, $icono] = $item)
                @php($contador = $item[4] ?? 0)
                <a href="{{ url($ruta) }}" @if (request()->is($patron)) aria-current="page" @endif
                   data-buscar="{{ $texto }} {{ $menuPalabras[$ruta] ?? '' }}" x-show="coincide($el.dataset.buscar)"
                   class="flex min-h-12 items-center gap-3 rounded-lg px-4 py-3 text-base font-medium [@media(max-height:820px)]:min-h-10 [@media(max-height:820px)]:py-2 {{ request()->is($patron) ? 'bg-naranja text-white' : 'text-white/85 hover:bg-white/10' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icono }}"/></svg>
                    {{ $texto }}
                    @if ($contador > 0)
                        <span class="ml-auto rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold text-white">{{ $contador }}</span>
                    @endif
                </a>
            @endforeach
            <p x-show="!hay()" x-cloak class="px-4 py-3 text-sm text-white/70">No hay módulos con «<span x-text="q"></span>».</p>
        </nav>
        </div>
        <div class="shrink-0 border-t border-white/15 p-4 [@media(max-height:820px)]:p-3">
            <p class="truncate text-sm font-semibold">{{ $usuario?->name }}</p>
            <p class="mb-3 truncate text-xs text-white/60">{{ ['admin' => 'Administrador', 'vendedor' => 'Vendedor', 'campo' => 'Campo', 'nedtracking' => 'Ned Tracking'][$usuario?->rol] ?? '' }}</p>
            <form method="POST" action="{{ route('salir') }}">
                @csrf
                <button type="submit" class="btn w-full bg-white/10 text-white hover:bg-white/20">Salir</button>
            </form>
        </div>
    </aside>

    {{-- Contenido --}}
    <main class="min-w-0 flex-1 lg:ml-72">
        {{-- El tablero de pedidos usa todo el ancho en la PC (las columnas no caben en 7xl). --}}
        <div class="mx-auto {{ request()->routeIs('pedidos.index') ? 'max-w-none' : 'max-w-7xl' }} px-4 py-6 sm:px-6 lg:px-8">
            @if (session('exito'))
                <div role="status" class="mb-4 rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-base text-green-800">{{ session('exito') }}</div>
            @endif
            @if (session('error'))
                <div role="alert" class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-base text-red-800">{{ session('error') }}</div>
            @endif

            @if ($tituloPagina)
                <h1 class="mb-6 text-2xl font-bold text-marino">{{ $tituloPagina }}</h1>
            @endif

            {{ $slot ?? '' }}
            @yield('contenido')
        </div>
    </main>
</div>
@livewireScripts
</body>
</html>
