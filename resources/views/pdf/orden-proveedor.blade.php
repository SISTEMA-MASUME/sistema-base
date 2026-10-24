<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Orden de compra {{ $o->folioTexto() }}</title>
<style>
body { font-family: freesans, sans-serif; color: #1f2937; font-size: 9.5pt; }
table { border-collapse: collapse; }
.mute { color: #6b7280; }
h4 { margin: 0 0 4px 0; font-size: 7.5pt; letter-spacing: 1.5px; color: #ef7d22; font-weight: bold; }
table.top { width: 100%; border-bottom: 3px solid #ef7d22; }
table.top td { vertical-align: middle; padding: 0 0 12px 0; }
.t1 { font-size: 15pt; font-weight: bold; color: #12235a; letter-spacing: 0.5px; }
.t2 { color: #6b7280; font-size: 8.5pt; line-height: 1.5; }
.lbl { font-size: 9pt; color: #6b7280; letter-spacing: 2px; text-align: right; }
.num { font-size: 22pt; font-weight: bold; color: #12235a; text-align: right; line-height: 1; }
table.fol { margin-left: auto; font-size: 8.5pt; margin-top: 5px; }
table.fol td { padding: 1px 0 1px 10px; text-align: right; }
table.cards { width: 100%; border-collapse: separate; border-spacing: 0; margin: 12px 0 12px 0; }
td.card { background-color: #f6f7fb; border-radius: 6px; padding: 8px 12px; vertical-align: top; line-height: 1.5; }
.big { font-size: 11pt; font-weight: bold; color: #12235a; margin-bottom: 3px; }
table.items { width: 100%; }
table.items th { background-color: #12235a; color: #ffffff; font-size: 8pt; font-weight: bold; padding: 6px 5px; text-align: left; letter-spacing: 0.5px; }
table.items th.c { text-align: center; } table.items th.r { text-align: right; }
table.items td { border-bottom: 1px solid #e5e7eb; padding: 8.5px 5px; vertical-align: middle; }
td.c { text-align: center; } td.r { text-align: right; }
td.n { color: #6b7280; text-align: center; }
tr.par { page-break-inside: avoid; }
td.z { background-color: #fbfbfd; }
.imgbox { width: 13.2mm; height: 13.2mm; border: 1px solid #e5e7eb; background-color: #ffffff; text-align: center; }
.nm { font-weight: bold; font-size: 9pt; line-height: 1.3; }
.meta { color: #6b7280; font-size: 8pt; margin-top: 2px; }
table.fin { width: 100%; margin-top: 14px; page-break-inside: avoid; }
table.fin td.cond { width: 58%; vertical-align: top; font-size: 8.5pt; padding-right: 16px; line-height: 1.55; }
table.fin td.tot { vertical-align: top; }
table.tt { width: 100%; font-size: 10pt; }
table.tt td { padding: 5px 8px; }
table.tt td.v { text-align: right; }
table.tt tr.g td { background-color: #12235a; color: #ffffff; font-size: 13pt; font-weight: bold; }
</style></head><body>
<table class="top"><tr>
  <td style="width:26mm;">@if($logo)<img src="{{ $logo }}" style="height:16mm;">@endif</td>
  <td style="padding-left:2mm;">
    <div class="t1">{{ $empresa['nombre'] }}</div>
    <div class="t2">{{ $empresa['giro'] }}<br>{{ $empresa['ciudad'] }} · {{ $empresa['web'] }}<br>{{ $empresa['correo'] }} · {{ $empresa['telefono'] }}</div>
  </td>
  <td style="width:60mm; text-align:right;">
    <div class="lbl">ORDEN DE COMPRA</div>
    <div class="num">{{ $o->folioTexto() }}</div>
    <table class="fol">
      <tr><td class="mute">Fecha</td><td><b>{{ $o->created_at->format('d/m/Y') }}</b></td></tr>
      @if($o->user)<tr><td class="mute">Elaboró</td><td><b>{{ $o->user->nombreReal() }}</b></td></tr>@endif
    </table>
  </td>
</tr></table>

<table class="cards"><tr>
  <td class="card" style="width:62%;">
    <h4>PROVEEDOR</h4>
    <div class="big">{{ $o->proveedor->nombre }}</div>
    @php($datosProv = $o->proveedor->datosContacto())
    @if($datosProv['nombre'])<div>Contacto: <b>{{ $datosProv['nombre'] }}</b></div>@endif
    @if($datosProv['telefono'] || $datosProv['correo'])<div>{{ collect([$datosProv['telefono'], $datosProv['correo']])->filter()->implode(' · ') }}</div>@endif
    @if($atendio)<div>Atendió: <b>{{ $atendio }}</b></div>@endif
  </td>
  <td style="width:2.5%;"></td>
  <td class="card">
    <h4>CONDICIONES</h4>
    <div>Método de pago: <b>{{ $o->metodo_pago }}</b></div>
    {{-- Solo el número de la tarjeta del pedido; el cliente nunca sale en la OC al proveedor. --}}
    @if($o->pedido)<div>Referencia: <b>Pedido P-{{ $o->pedido->folio }}</b></div>@endif
  </td>
</tr></table>

<table class="items">
<thead><tr><th class="c" style="width:7mm;">#</th><th style="width:16mm;">Imagen</th><th>Descripción</th><th style="width:24mm;">Modelo</th><th class="c" style="width:12mm;">Cant.</th><th class="c" style="width:13mm;">Unidad</th><th class="r" style="width:22mm;">Costo unit. c/IVA</th><th class="r" style="width:22mm;">Importe c/IVA</th></tr></thead>
<tbody>
@foreach($partidas as $i => $p)
<tr class="par">
  <td class="n {{ $i % 2 ? 'z' : '' }}">{{ $i + 1 }}</td>
  <td class="{{ $i % 2 ? 'z' : '' }}" style="padding-left:2mm;">@if($p['img'])<div class="imgbox" style="padding-top:{{ $p['img']['top'] }}mm;"><img src="{{ $p['img']['ruta'] }}" style="width:{{ $p['img']['w'] }}mm; height:{{ $p['img']['h'] }}mm;"></div>@else<div style="width:13.2mm; height:13.2mm;"></div>@endif</td>
  <td class="{{ $i % 2 ? 'z' : '' }}"><div class="nm">{{ $p['descripcion'] }}</div>@if($p['marca'])<div class="meta">{{ $p['marca'] }}</div>@endif</td>
  <td class="{{ $i % 2 ? 'z' : '' }}">{{ $p['modelo'] }}</td>
  <td class="c {{ $i % 2 ? 'z' : '' }}">{{ $p['cantidad'] }}</td>
  <td class="c {{ $i % 2 ? 'z' : '' }}">{{ $p['unidad'] }}</td>
  <td class="r {{ $i % 2 ? 'z' : '' }}">{{ $p['unitario'] }}</td>
  <td class="r {{ $i % 2 ? 'z' : '' }}"><b>{{ $p['importe'] }}</b></td>
</tr>
@endforeach
</tbody></table>

<table class="fin"><tr>
  <td class="cond">
    @if($o->comentarios)<h4>COMENTARIOS</h4><div>{!! nl2br(e($o->comentarios)) !!}</div>@endif
  </td>
  <td class="tot">
    <table class="tt">
      <tr><td>Subtotal</td><td class="v">{{ $totales['subtotal'] }}</td></tr>
      <tr><td>IVA {{ $ivaPct }}%</td><td class="v">{{ $totales['iva'] }}</td></tr>
      <tr class="g"><td>TOTAL</td><td class="v">{{ $totales['total'] }}</td></tr>
    </table>
  </td>
</tr></table>
</body></html>
