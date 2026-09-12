<?php

use App\Http\Middleware\EnsurePurchases;
use App\Http\Middleware\EnsureSection;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ResolveSurface;
use App\Live\SubscriberCookie;
use App\Mail\Console\ReconcileMail;
use App\Mail\Console\SyncMail;
use App\Mail\Console\WatchMail;
use App\Notifications\Console\SendDigest;
use App\Offers\Console\TickOffers;
use App\Media\Console\MovePapers;
use App\Push\Console\MakeKeys;
use App\Users\Console\CreateUser;
use App\Workflow\Console\RefillInsurers;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/park.php', __DIR__.'/../routes/crm.php', __DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateUser::class,
        TickOffers::class,
        RefillInsurers::class,
        SendDigest::class,
        SyncMail::class,
        WatchMail::class,
        ReconcileMail::class,
        MakeKeys::class,
        MovePapers::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/vhod');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['staff' => EnsureStaff::class, 'section' => EnsureSection::class, 'purchases' => EnsurePurchases::class]);
        $middleware->web(append: [ResolveSurface::class, SubscriberCookie::class]);
        $middleware->encryptCookies(except: [SubscriberCookie::NAME]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
