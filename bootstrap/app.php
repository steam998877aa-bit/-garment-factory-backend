<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Routing\Exceptions\InvalidSignatureException $e, $request) {
            if ($request->is('api/employees/*/id-card') || $request->is('api/employees/*/cv')) {
                return response()->json([
                    'url' => null,
                    'message' => 'الملف غير موجود، يرجى إعادة الرفع',
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'حجم الملفات المرفوعة كبير جداً. الحد الأقصى المسموح به هو 32 ميجابايت.',
                    'errors' => [
                        'file' => ['حجم الملفات المرفوعة يتجاوز الحد المسموح.']
                    ]
                ], 422);
            }
        });
    })->create();
