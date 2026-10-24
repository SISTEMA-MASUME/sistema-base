<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<style>
    body { font-family: freesans; font-size: 9.5pt; color: #111827; }
    .titulo { color: #8b1a1a; font-size: 15pt; font-weight: bold; text-align: right; }
    .fechas { text-align: right; font-size: 8.5pt; color: #374151; }
    .caja-de { background: #e5e7eb; padding: 8px 10px; vertical-align: top; }
    .caja-hacia { border: 1px solid #6b7280; padding: 8px 10px; vertical-align: top; }
    .nombre { font-weight: bold; color: #1e1b4b; font-size: 10.5pt; }
    table.partidas { border-collapse: collapse; width: 100%; margin-top: 4px; }
    table.partidas th { border: 1px solid #6b7280; font-weight: normal; text-align: left; padding: 3px 5px; font-size: 9pt; }
    table.partidas td { border-left: 1px solid #6b7280; border-right: 1px solid #6b7280; padding: 5px; vertical-align: top; }
    table.partidas tr.fin td { border-bottom: 1px solid #6b7280; }
    .num { text-align: right; }
    .totales td { padding: 2px 5px; }
    .gris { background: #e5e7eb; }
</style></head><body>
<table width="100%"><tr>
    <td width="50%" style="vertical-align:top;">
        @if ($logo)<img src="{{ $logo }}" style="max-height:22mm;max-width:75mm;">@else<span style="font-size:20pt;font-weight:bold;color:#1e3a8a;">{{ $datos->empresa }}</span>@endif
    </td>
    <td width="50%" style="vertical-align:top;">
        <div class="titulo">Cotización ({{ $c->folioTexto() }})</div>
        <div class="fechas">Fecha: {{ $c->fecha->format('d/m/y') }}<br>Vigencia: {{ $vence }} ({{ $c->vigencia_dias }} días)</div>
    </td>
</tr></table>

<table width="100%" style="margin-top:8mm;" cellspacing="0"><tr>
    <td width="48%" style="font-size:8pt;padding-bottom:2px;">De</td><td width="4%"></td><td width="48%" style="font-size:8pt;padding-bottom:2px;">Hacia</td>
</tr><tr>
    <td class="caja-de">
        <div class="nombre">{{ $datos->nombre ?: $datos->empresa }}</div>
        <div style="white-space:pre-line;">{!! nl2br(e($datos->direccion)) !!}</div>
        <br>
        @if ($datos->telefono)Teléfono: {{ $datos->telefono }}<br>@endif
        @if ($datos->correo)Correo: {{ $datos->correo }}<br>@endif
        @if ($datos->web)Página de internet: {{ $datos->web }}@endif
    </td>
    <td></td>
    <td class="caja-hacia">
        <div class="nombre">{{ $c->cliente_nombre }}</div>
        @if ($c->cliente_direccion)<div>{!! nl2br(e($c->cliente_direccion)) !!}</div>@endif
        @if ($c->cliente_contacto)<div>At'n: {{ $c->cliente_contacto }}</div>@endif
        @if ($c->cliente_correo || $c->cliente_telefono)<div>{{ collect([$c->cliente_correo, $c->cliente_telefono])->filter()->implode(' · ') }}</div>@endif
    </td>
</tr></table>

<div style="text-align:right;font-size:8pt;margin-top:6mm;">Monto en moneda México Peso</div>
<table class="partidas">
    <thead><tr>
        <th width="52%">Descripción</th><th width="8%">IVA</th><th width="13%" class="num">P.U.</th><th width="10%" class="num">Cant.</th><th width="17%" class="num">Importe</th>
    </tr></thead>
    <tbody>
        @foreach ($partidas as $p)
            <tr class="{{ $loop->last ? 'fin' : '' }}">
                <td>{{ $p->descripcion }}<br><span style="font-size:8pt;color:#6b7280;">{{ $p->unidad }}</span></td>
                <td class="num">16%</td>
                <td class="num">{{ $dinero($p->precio) }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.') }}</td>
                <td class="num">{{ $dinero($p->importe) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table width="100%" style="margin-top:3px;"><tr>
    <td width="58%" style="vertical-align:top;font-size:8.5pt;padding-top:4px;">
        ({{ $letra }})
        @if ($c->notas)<p style="margin-top:6px;white-space:pre-line;">{{ $c->notas }}</p>@endif
        @if ($datos->condiciones)<p style="margin-top:6px;color:#374151;white-space:pre-line;">{{ $datos->condiciones }}</p>@endif
    </td>
    <td width="42%" style="vertical-align:top;">
        <table width="100%" class="totales">
            <tr><td>Subtotal</td><td class="num">{{ $dinero($c->subtotal) }}</td></tr>
            <tr><td>Total IVA 16%</td><td class="num">{{ $dinero($c->iva) }}</td></tr>
            <tr class="gris"><td>Total</td><td class="num">{{ $dinero($c->total) }}</td></tr>
        </table>
    </td>
</tr></table>
</body></html>
