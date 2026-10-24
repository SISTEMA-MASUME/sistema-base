<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Contraseñas equivocadas seguidas con el mismo correo antes de bloquearlo. */
    private const INTENTOS = 5;

    private const BLOQUEO_SEGUNDOS = 15 * 60;

    public function formulario(): View
    {
        return view('auth.login');
    }

    public function entrar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe tu contraseña.',
        ]);

        // Contra quien prueba contraseñas desde muchos lugares: 5 errores con el mismo correo lo bloquean 15 minutos
        // (aparte del límite de 10 intentos por minuto por conexión; en la oficina todos salen por la misma).
        $llave = 'login:'.mb_strtolower(trim($datos['email']));
        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS)) {
            $minutos = max(1, (int) ceil(RateLimiter::availableIn($llave) / 60));

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos con este correo. Espera {$minutos} ".($minutos === 1 ? 'minuto' : 'minutos').' y vuelve a intentar.',
            ]);
        }

        // Los usuarios inactivos no pueden entrar.
        $ok = Auth::attempt([
            'email' => $datos['email'],
            'password' => $datos['password'],
            'activo' => true,
        ], $request->boolean('recordar'));

        if (! $ok) {
            RateLimiter::hit($llave, self::BLOQUEO_SEGUNDOS);
            $inactivo = User::where('email', $datos['email'])->where('activo', false)->exists();

            throw ValidationException::withMessages([
                'email' => $inactivo
                    ? 'Tu usuario está desactivado. Habla con el administrador.'
                    : 'Correo o contraseña incorrectos.',
            ]);
        }

        RateLimiter::clear($llave);
        $request->session()->regenerate();

        return redirect()->intended(route('inicio'));
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
