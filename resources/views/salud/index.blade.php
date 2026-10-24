@extends('layouts.app')
@section('titulo', 'Salud del sistema')
@section('contenido')
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Base de datos</h2>
            @if ($bd['ok'])
                <p class="text-green-700">Conectada correctamente.</p>
                <p class="text-slate-700">Tamaño de las tablas: {{ $bd['mb'] === null ? '—' : number_format($bd['mb'], 2).' MB' }}</p>
            @else
                <p class="font-semibold text-red-700">No se pudo conectar.</p>
            @endif
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Archivos subidos</h2>
            <p class="text-slate-700">{{ number_format($archivos['mb'], 2) }} MB en {{ number_format($archivos['total']) }} archivos</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Cuentas de correo</h2>
            @forelse ($cuentas as $nombre => $ok)
                <p class="{{ $ok ? 'text-green-700' : 'text-red-700' }}">{{ $nombre }}: {{ $ok ? 'configurada' : 'falta configurar' }}</p>
            @empty
                <p class="text-slate-700">Sin cuentas.</p>
            @endforelse
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Último respaldo</h2>
            <p class="text-slate-700">{{ $respaldo ? \Illuminate\Support\Carbon::createFromTimestamp($respaldo)->format('d/m/Y H:i') : 'sin respaldos' }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Versión</h2>
            <p class="text-slate-700">{{ $version }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm" data-php>
            <h2 class="mb-2 text-lg font-semibold text-marino">PHP del servidor</h2>
            <p class="text-slate-700">Versión {{ $php['version'] }}</p>
            @if ($php['parchado'])
                <p class="font-semibold text-green-700">Con parche de seguridad</p>
            @else
                <p class="font-semibold text-red-700">Sin parche: necesita {{ $php['minimo'] }} o más</p>
            @endif
            <p class="text-sm {{ $php['upload_progress'] ? 'text-red-700' : 'text-green-700' }}">Progreso de subida en sesión: {{ $php['upload_progress'] ? 'encendido' : 'apagado' }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm">
            <h2 class="mb-2 text-lg font-semibold text-marino">Errores sin resolver</h2>
            <p class="text-2xl font-bold {{ $errores ? 'text-red-700' : 'text-green-700' }}">{{ $errores ?? '—' }}</p>
            <a href="{{ route('errores.index') }}" class="text-naranja underline">Ver errores</a>
        </div>
    </div>
@endsection
