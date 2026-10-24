<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · MASUME</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:#12235a;color:#fff;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center}
        img.logo{height:56px;width:auto;margin-bottom:28px}
        h1{font-size:28px;margin:0 0 12px}
        p{font-size:18px;line-height:1.4;margin:0 0 12px;color:rgba(255,255,255,.88);max-width:34rem}
        .codigo{display:inline-block;font-size:44px;font-weight:800;letter-spacing:.08em;color:#ef7d22;background:rgba(255,255,255,.08);border:2px dashed #ef7d22;border-radius:12px;padding:10px 24px;margin:8px 0 16px;user-select:all}
        .botones{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:20px}
        .btn{display:inline-block;min-height:48px;line-height:48px;padding:0 24px;border-radius:10px;font-size:17px;font-weight:600;text-decoration:none;cursor:pointer;border:0}
        .btn-p{background:#ef7d22;color:#fff}
        .btn-s{background:rgba(255,255,255,.15);color:#fff}
    </style>
</head>
<body>
    <img class="logo" src="/logo-masume.png" alt="MASUME Industrial">
    <h1>@yield('titulo')</h1>
    @yield('cuerpo')
    <div class="botones">
        @yield('botones')
    </div>
</body>
</html>
