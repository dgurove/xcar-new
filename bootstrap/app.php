<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/park.php', __DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php', __DIR__.'/../routes/admin.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        App\Users\Console\CreateUser::class,
        App\Offers\Console\TickOffers::class,
        App\Notifications\Console\SendDigest::class,
        App\Mail\Console\SyncMail::class,
        App\Mail\Console\WatchMail::class,
        App\Mail\Console\ReconcileMail::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/vhod');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['staff' => App\Http\Middleware\EnsureStaff::class, 'section' => App\Http\Middleware\EnsureSection::class]);
        $middleware->web(append: [App\Http\Middleware\ParkHost::class, App\Live\SubscriberCookie::class]);
        $middleware->encryptCookies(except: [App\Live\SubscriberCookie::NAME]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
