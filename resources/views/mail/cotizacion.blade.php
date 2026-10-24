<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cotización {{ $folio }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
    <tr>
        <td style="background:#12235a;padding:20px 28px;">
            <span style="font-size:22px;font-weight:bold;color:#ffffff;letter-spacing:1px;">{{ $empresa['nombre'] }}</span>
            <div style="font-size:13px;color:#ffffff;opacity:.8;">{{ $empresa['giro'] }}</div>
        </td>
    </tr>
    <tr><td style="background:#ef7d22;height:4px;font-size:0;line-height:0;">&nbsp;</td></tr>
    <tr>
        <td style="padding:28px;font-size:16px;line-height:1.5;">
            <div style="white-space:pre-line;">{{ $mensajeVendedor }}</div>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;border:1px solid #e2e8f0;border-radius:6px;">
                <tr>
                    <td style="padding:12px 16px;font-size:14px;color:#64748b;">Cotización</td>
                    <td style="padding:12px 16px;font-size:16px;font-weight:bold;color:#12235a;" align="right">#{{ $folio }}</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-size:14px;color:#64748b;border-top:1px solid #e2e8f0;">Total con IVA</td>
                    <td style="padding:12px 16px;font-size:18px;font-weight:bold;color:#ef7d22;border-top:1px solid #e2e8f0;" align="right">${{ $total }} MXN</td>
                </tr>
                <tr>
                    <td style="padding:12px 16px;font-size:14px;color:#64748b;border-top:1px solid #e2e8f0;">Vigencia</td>
                    <td style="padding:12px 16px;font-size:16px;border-top:1px solid #e2e8f0;" align="right">{{ $vigenciaDias }} días (hasta el {{ $vigenciaHasta }})</td>
                </tr>
            </table>

            <p style="margin:0 0 24px;font-size:14px;color:#475569;">Encontrarás el detalle en el PDF adjunto.</p>

            <div style="border-top:2px solid #12235a;padding-top:16px;font-size:15px;">
                <strong style="color:#12235a;">{{ $nombreVendedor }}</strong><br>
                MASUME Industrial<br>
                @if ($telefonoVendedor) Tel. {{ $telefonoVendedor }}<br> @endif
                <a href="mailto:{{ $correoVendedor }}" style="color:#12235a;">{{ $correoVendedor }}</a>
            </div>
        </td>
    </tr>
    <tr>
        <td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#94a3b8;">
            {{ $empresa['ciudad'] }} · {{ $empresa['web'] }}
        </td>
    </tr>
</table>
</td></tr>
</table>
</body>
</html>
