<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel installs a default guest redirect that resolves route('login').
        // This application defines no such route — signing in belongs to the SPA
        // — and the callback is evaluated inside the auth middleware, so an
        // unauthenticated request that did not ask for JSON raised a routing
        // error before the exception renderer ever saw it, and answered 500.
        // With no redirect target the AuthenticationException reaches the
        // renderer, which answers 401 in the standard envelope.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->append(HandleCors::class);

        // Applied to every response, including file downloads and the
        // exception envelope, so a failure is protected as well as a success.
        $middleware->append(SecurityHeaders::class);

        // Behind a TLS-terminating proxy the application cannot tell that the
        // original request was HTTPS unless the proxy is trusted, and HSTS is
        // then never sent. Unset by default, which trusts nothing and leaves
        // behaviour exactly as it was.
        if (($proxies = env('TRUSTED_PROXIES')) !== null && $proxies !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        // Laravel does not rate limit the API group on its own. Without this
        // every endpoint — sign-in included — accepts unlimited attempts.
        // The limits themselves are defined in AppServiceProvider.
        $middleware->throttleApi();

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API failure is translated into the documented JSON envelope so
        // that stack traces, SQL errors and raw exceptions never reach a user.
        $exceptions->render(fn (Throwable $e, $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
