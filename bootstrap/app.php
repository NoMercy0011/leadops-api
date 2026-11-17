<?php

use App\Http\Middleware\AddLogContext;
use App\Http\Middleware\EnsureSubscriptionAllowsWrites;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'subscription.writes' => EnsureSubscriptionAllowsWrites::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        // Après l'authentification : le contexte n'a d'intérêt que si
        // l'utilisateur est déjà résolu, sinon company_id et role sont nuls.
        $middleware->api(append: [
            AddLogContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Tout refus d'autorisation porte le code stable du contrat (revue du
        // lot 9) : l'écran distingue les refus par leur code, jamais par le
        // message, et le message de Laravel est en anglais.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'code' => 'auth.forbidden',
                'message' => "Vous n'avez pas le droit d'effectuer cette action.",
            ], 403);
        });
    })->create();
