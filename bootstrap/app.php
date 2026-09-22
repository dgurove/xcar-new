<?php

use App\Billing\Console\CloseMonthCommand;
use App\Billing\Console\TickBilling;
use App\Cars\Console\LearnVins;
use App\Http\Middleware\EnsureManager;
use App\Http\Middleware\EnsureParkManager;
use App\Http\Middleware\EnsurePurchases;
use App\Http\Middleware\EnsureSection;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\MarkInstalled;
use App\Http\Middleware\PeekBack;
use App\Http\Middleware\RedirectLegacyPaths;
use App\Http\Middleware\ResolveSurface;
use App\Http\Middleware\ServerTiming;
use App\Http\Middleware\SiteWall;
use App\Http\Middleware\TouchSeen;
use App\Live\SubscriberCookie;
use App\Mail\Console\ArchiveStaleCandidates;
use App\Mail\Console\BackfillMail;
use App\Mail\Console\CandidateCardsCommand;
use App\Mail\Console\ReadMail;
use App\Mail\Console\RebuildChains;
use App\Mail\Console\ReconcileMail;
use App\Mail\Console\SyncMail;
use App\Mail\Console\WatchMail;
use App\Media\Console\MoveConversionsHot;
use App\Media\Console\MovePapers;
use App\Media\Console\Restamp;
use App\Offers\Console\TickOffers;
use App\Park\Console\FactCommand;
use App\Park\Console\ParkDigestCommand;
use App\Park\Console\TickPark;
use App\Push\Console\MakeKeys;
use App\Storage\Console\Gc;
use App\Storage\Console\Report;
use App\Telegram\Console\Poll;
use App\Users\Console\CreateUser;
use App\Workflow\Console\RefillVendors;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
        RefillVendors::class,
        TickPark::class,
        ParkDigestCommand::class,
        FactCommand::class,
        TickBilling::class,
        CloseMonthCommand::class,
        SyncMail::class,
        WatchMail::class,
        ReconcileMail::class,
        CandidateCardsCommand::class,
        ReadMail::class,
        RebuildChains::class,
        BackfillMail::class,
        ArchiveStaleCandidates::class,
        MakeKeys::class,
        Restamp::class,
        MovePapers::class,
        MoveConversionsHot::class,
        Gc::class,
        Report::class,
        Poll::class,
        LearnVins::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['staff' => EnsureStaff::class, 'manager' => EnsureManager::class, 'section' => EnsureSection::class, 'park.manage' => EnsureParkManager::class, 'purchases' => EnsurePurchases::class, 'wall' => SiteWall::class]);
        $middleware->prepend(RedirectLegacyPaths::class);
        // Поверхность и технические работы решаются до `auth`: гость на закрытой стоянке видит страницу, а не вход.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveSurface::class);
        $middleware->web(append: [ResolveSurface::class, SubscriberCookie::class, ServerTiming::class, MarkInstalled::class, PeekBack::class, TouchSeen::class]);
        $middleware->encryptCookies(except: [SubscriberCookie::NAME, 'theme', MarkInstalled::COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
