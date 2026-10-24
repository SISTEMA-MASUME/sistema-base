@extends('layouts.app')

@section('titulo', 'Conectar asistente de IA')

@section('contenido')
    <div class="mx-auto max-w-xl space-y-4 rounded-xl bg-white p-6 shadow-sm">
        <h2 class="text-xl font-bold text-marino">¿Permitir que “{{ $cliente->nombre ?: 'Asistente' }}” entre a tu sistema?</h2>
        <p class="text-base text-slate-700">Podrá:</p>
        <ul class="list-disc space-y-1 pl-6 text-base text-slate-700">
            <li><strong>Consultar</strong> cotizaciones, precios anteriores, pedidos, OC a proveedores, clientes y proveedores (incluye costos y utilidad).</li>
            <li><strong>Crear cotizaciones en borrador</strong>, agregarles partidas y editar cotizaciones.</li>
            <li><strong>Ver finanzas</strong> (ventas, cobranza, nómina, gastos, dashboard, mis finanzas).</li>
        </ul>
        <div class="rounded-lg border p-3 {{ $destino['conocido'] ? 'border-slate-200 bg-slate-50' : 'border-red-300 bg-red-50' }}">
            <p class="text-base text-slate-700">La llave se enviará a: <strong class="text-lg {{ $destino['conocido'] ? 'text-marino' : 'text-red-700' }}">{{ $destino['host'] ?: 'sitio desconocido' }}</strong></p>
            @unless ($destino['conocido'])
                <p class="mt-1 text-base font-semibold text-red-700">⚠ Ese sitio NO es Claude ni ChatGPT. Si no lo reconoces, aprieta "No": alguien podría estar intentando entrar a tu sistema con un nombre falso.</p>
            @endunless
        </div>
        <p class="text-base text-slate-700">No podrá mandar nada a clientes ni proveedores, ni cambiar pedidos u OC. Puedes quitarle el acceso cuando quieras en <strong>Conexión con IA</strong>.</p>
        <form method="POST" action="{{ url('/oauth/autorizar') }}" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            @csrf
            @foreach ($parametros as $clave => $valor)
                <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
            @endforeach
            @unless ($destino['conocido'])
                <label class="flex items-start gap-2 text-base text-red-800 sm:basis-full">
                    <input type="checkbox" name="confirmo_destino" value="1" required class="mt-1 h-5 w-5">
                    Entiendo que ese sitio no es Claude ni ChatGPT y sí es mío.
                </label>
            @endunless
            <button type="submit" name="permitir" value="1" class="btn-primario">Sí, permitir</button>
            <button type="submit" name="permitir" value="0" formnovalidate class="btn-secundario">No</button>
        </form>
    </div>
@endsection
