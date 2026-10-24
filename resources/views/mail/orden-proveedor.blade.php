<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Orden de compra {{ $folio }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr>
        <td style="background:#12235a;padding:20px 28px;">
            <span style="font-size:22px;font-weight:bold;color:#ffffff;letter-spacing:1px;">{{ $empresa['nombre'] }}</span>
            <div style="font-size:13px;color:#ffffff;opacity:.8;">Orden de compra {{ $folio }}</div>
        </td>
    </tr>
    <tr><td style="background:#ef7d22;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr>
        <td style="padding:28px;font-size:16px;line-height:1.5;">
            <div style="white-space:pre-line;">{{ $mensajeUsuario }}</div>
            <p style="margin-top:24px;font-size:14px;color:#64748b;">La orden de compra {{ $folio }} va adjunta en PDF.</p>
        </td>
    </tr>
    <tr>
        <td style="padding:16px 28px;background:#f8fafc;font-size:13px;color:#64748b;">
            {{ $nombreUsuario }} · {{ $correoUsuario }}@if ($telefonoUsuario) · Tel. {{ $telefonoUsuario }}@endif<br>
            {{ $empresa['nombre'] }} · {{ $empresa['ciudad'] }}
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
