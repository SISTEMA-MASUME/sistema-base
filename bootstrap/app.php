<?php

use App\Http\Middleware\NoIndexar;
use App\Http\Middleware\Permiso;
use App\Http\Middleware\SacarInactivos;
use App\Http\Middleware\SinCampo;
use App\Http\Middleware\SoloAdmin;
use App\Http\Middleware\SoloMasume;
use App\Http\Middleware\SoloNed;
use App\Support\RegistroErrores;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => SoloAdmin::class, 'sin_campo' => SinCampo::class, 'solo_masume' => SoloMasume::class, 'solo_ned' => SoloNed::class, 'permiso' => Permiso::class]);
        $middleware->web(append: [SacarInactivos::class]);
        $middleware->append(NoIndexar::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores "normales" que no son fallas del sistema: no se registran.
        $exceptions->dontReport([
            AuthenticationException::class,
            AuthorizationException::class,
            ValidationException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
        ]);

        $exceptions->report(function (Throwable $e) {
            if ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [403, 404, 405, 419], true)) {
                return;
            }
            RegistroErrores::registrar($e);
        });

        // Abrir con el navegador (historial, recargar, sugerencias de la barra) una dirección que solo funciona al
        // apretar un botón —/salir, /livewire/update— mostraba la página "405" genérica (le pasó a Rosa, 22-oct-2026):
        // se regresa sola a la pantalla de donde venía, o al inicio. Las llamadas de programas (api, oauth, mcp) no.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->expectsJson() || $request->is('api/*', 'oauth/*', 'mcp')) {
                return null;
            }
            $previa = (string) $request->headers->get('referer');
            $mismaPagina = $previa === $request->fullUrl() || str_starts_with((string) parse_url($previa, PHP_URL_PATH), '/livewire/');

            return redirect()->to($previa !== '' && parse_url($previa, PHP_URL_HOST) === $request->getHost() && ! $mismaPagina ? $previa : url('/'));
        });
    })->create();
