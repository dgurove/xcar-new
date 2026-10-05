<?php

use App\Billing\Acquiring\Console\BackfillPayLinks;
use App\Billing\Acquiring\Console\SyncAcquiring;
use App\Billing\Bank\Console\PerpetualSecret;
use App\Billing\Bank\Console\SyncBank;
use App\Billing\Console\CloseMonthCommand;
use App\Billing\Console\TickBilling;
use App\Cars\Console\MergeCarsCommand;
use App\Cars\Console\MergeDuplicatesCommand;
use App\Http\Middleware\DemoReadOnly;
use App\Http\Middleware\DetailBack;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureOfferEditable;
use App\Http\Middleware\EnsureParkArea;
use App\Http\Middleware\EnsureParkManager;
use App\Http\Middleware\EnsureSection;
use App\Http\Middleware\Impersonated;
use App\Http\Middleware\MarkInstalled;
use App\Http\Middleware\MarkPrefetch;
use App\Http\Middleware\NormalizeNumbers;
use App\Http\Middleware\ReadNoticesOnVisit;
use App\Http\Middleware\RedirectLegacyPaths;
use App\Http\Middleware\ResolveSurface;
use App\Http\Middleware\ScalarSearch;
use App\Http\Middleware\ServerTiming;
use App\Http\Middleware\SiteWall;
use App\Http\Middleware\TouchSeen;
use App\Live\SubscriberCookie;
use App\Mail\Console\ArchiveStaleCandidates;
use App\Mail\Console\BackfillMail;
use App\Mail\Console\ReadMail;
use App\Mail\Console\RebuildChains;
use App\Mail\Console\ReconcileMail;
use App\Mail\Console\SyncMail;
use App\Mail\Console\WatchMail;
use App\Media\Console\MoveConversionsHot;
use App\Media\Console\Restamp;
use App\Media\Console\TuneWatermarks;
use App\Media\Console\UnmarkPhotos;
use App\Offers\Console\MigtorgArchive;
use App\Offers\Console\MigtorgSync;
use App\Offers\Console\PruneEmptyDrafts;
use App\Offers\Console\TickOffers;
use App\Park\Console\FillFromDocsCommand;
use App\Park\Console\MirrorOffersCommand;
use App\Park\Console\ParkDigestCommand;
use App\Park\Console\ReleaseByLettersCommand;
use App\Park\Console\StoreByLettersCommand;
use App\Park\Console\TickPark;
use App\Push\Console\MakeKeys;
use App\Storage\Console\Gc;
use App\Storage\Console\Report;
use App\Telegram\Console\Poll;
use App\Telegram\Console\SetProfile;
use App\Telegram\Offers\Console\Lane as OffersBotLane;
use App\Telegram\Offers\Console\Poke as OffersBotPoke;
use App\Telegram\Offers\Console\Profile as OffersBotProfile;
use App\Telegram\Offers\Console\Run as OffersBotRun;
use App\Users\Console\CreateUser;
use App\Users\Console\SeedDemo;
use App\Workflow\Console\RefillVendors;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/park.php', __DIR__.'/../routes/crm.php', __DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateUser::class,
        TickOffers::class,
        PruneEmptyDrafts::class,
        MigtorgSync::class,
        MigtorgArchive::class,
        RefillVendors::class,
        TickPark::class,
        ParkDigestCommand::class,
        StoreByLettersCommand::class,
        MirrorOffersCommand::class,
        ReleaseByLettersCommand::class,
        TickBilling::class,
        CloseMonthCommand::class,
        SyncMail::class,
        WatchMail::class,
        ReconcileMail::class,
        ReadMail::class,
        RebuildChains::class,
        BackfillMail::class,
        ArchiveStaleCandidates::class,
        MakeKeys::class,
        Restamp::class,
        UnmarkPhotos::class,
        TuneWatermarks::class,
        MoveConversionsHot::class,
        Gc::class,
        Report::class,
        Poll::class, SetProfile::class,
        OffersBotRun::class, OffersBotLane::class, OffersBotProfile::class, OffersBotPoke::class,
        SyncAcquiring::class,
        BackfillPayLinks::class,
        SyncBank::class,
        PerpetualSecret::class,
        SeedDemo::class,
        MergeCarsCommand::class,
        MergeDuplicatesCommand::class,
        FillFromDocsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['ability' => EnsureAbility::class, 'section' => EnsureSection::class, 'park.manage' => EnsureParkManager::class, 'park.area' => EnsureParkArea::class, 'offer.edit' => EnsureOfferEditable::class, 'wall' => SiteWall::class]);
        $middleware->prepend([ServerTiming::class, RedirectLegacyPaths::class, MarkPrefetch::class, ScalarSearch::class]);
        // Поверхность и технические работы решаются до `auth`: гость на закрытой стоянке видит страницу, а не вход.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveSurface::class);
        $middleware->web(append: [NormalizeNumbers::class, ResolveSurface::class, SubscriberCookie::class, MarkInstalled::class, DetailBack::class, Impersonated::class, TouchSeen::class, ReadNoticesOnVisit::class, DemoReadOnly::class]);
        $middleware->encryptCookies(except: [SubscriberCookie::NAME, 'theme', MarkInstalled::COOKIE]);
        // Уведомления провайдеров: подписи у ЮKassa нет, статус перечитывается по API (`PayController::hook`).
        $middleware->validateCsrfTokens(except: ['hooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Два сохранения одной машины в одну секунду: сторож (`Cars\Identity`) пропустил оба, второе упёрлось в индекс —
        // ошибка поля, как у сторожа, а не 500.
        $exceptions->map(UniqueConstraintViolationException::class, function (UniqueConstraintViolationException $e) {
            $field = match (true) {
                str_contains($e->getMessage(), 'offers_claim_ref_key_unique') => 'claim_ref',
                str_contains($e->getMessage(), 'park_vehicles_ref_key_unique') => 'ref',
                str_contains($e->getMessage(), '_vin_live_unique') => 'vin',
                default => null,
            };

            return $field ? ValidationException::withMessages([$field => ($field === 'vin' ? 'VIN' : 'Номер убытка').' уже у другой записи, обновите страницу']) : $e;
        });
    })->create();
