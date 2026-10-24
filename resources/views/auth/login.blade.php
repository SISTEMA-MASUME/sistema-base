<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · MASUME</title>
    <link rel="icon" href="/favicon.ico">
    @include('partials.app-movil')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-marino px-4 font-sans antialiased">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl sm:p-8">
        <img src="/logo-masume.png" alt="MASUME" class="mx-auto mb-6 h-14 w-auto">
        <h1 class="mb-6 text-center text-xl font-bold text-marino">Entrar al sistema</h1>

        <form method="POST" action="{{ route('login.entrar') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="etiqueta">Correo</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="campo">
                @error('email') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="etiqueta">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="campo">
                @error('password') <p class="error-campo">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="recordar" value="1" class="h-5 w-5 rounded border-slate-300"> Mantener mi sesión abierta
            </label>
            <button type="submit" class="btn-primario w-full">Entrar</button>
        </form>
    </div>
</body>
</html>
