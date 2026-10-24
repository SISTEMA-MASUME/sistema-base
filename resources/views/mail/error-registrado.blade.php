<div style="font-family:Arial,sans-serif;font-size:15px;color:#222">
    <h2 style="color:#12235a">Error {{ $error->codigo }}</h2>
    <p><strong>Qué pasó:</strong> {{ \Illuminate\Support\Str::limit($error->mensaje, 300) }}</p>
    <p><strong>Dónde:</strong> {{ $error->metodo }} {{ $error->url }}</p>
    <p><strong>A quién le pasó:</strong> {{ $usuario ?? 'Sin sesión / sistema' }}</p>
    <p><strong>Veces que ha ocurrido:</strong> {{ $error->ocurrencias }}</p>
    <p><a href="{{ $enlace }}" style="color:#ef7d22;font-weight:bold">Ver el error en el sistema</a></p>
</div>
