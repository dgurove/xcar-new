<?php

use App\Billing\Acquiring\Console\BackfillPayLinks;
use App\Billing\Acquiring\Console\SyncAcquiring;
use App\Billing\Bank\Console\PerpetualSecret;
use App\Billing\Bank\Console\SyncBank;
use App\Billing\Console\CloseMonthCommand;
use App\Billing\Console\TickBilling;
use App\Cars\Console\MergeCatalogCommand;
use App\Garage\Console\GarageReformFixCommand;
use App\Http\Middleware\ActWindowBack;
use App\Http\Middleware\DemoReadOnly;
use App\Http\Middleware\DetailBack;
use App\Http\Middleware\DeviceGate;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureOfferEditable;
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
use App\Mail\Console\PriceMisses;
use App\Mail\Console\ReadMail;
use App\Mail\Console\RebuildChains;
use App\Mail\Console\ReconcileMail;
use App\Mail\Console\SyncMail;
use App\Mail\Console\WatchMail;
use App\Media\Console\CropPages;
use App\Media\Console\DocScans;
use App\Media\Console\MoveConversionsHot;
use App\Media\Console\Restamp;
use App\Media\Console\TuneWatermarks;
use App\Media\Console\UnmarkPhotos;
use App\Offers\Console\AutoDraftsCommand;
use App\Offers\Console\DealAgreementFixCommand;
use App\Offers\Console\FillFromLettersCommand;
use App\Offers\Console\MigtorgArchive;
use App\Offers\Console\MigtorgSync;
use App\Offers\Console\PruneEmptyDrafts;
use App\Offers\Console\TickOffers;
use App\Park\Console\AutoRequestsCommand;
use App\Park\Console\FillFromDocsCommand;
use App\Park\Console\FindValuesCommand;
use App\Park\Console\ParkDigestCommand;
use App\Park\Console\ReleaseByLettersCommand;
use App\Park\Console\SpbFactCommand;
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
use App\Users\Console\TelegramAvatars;
use App\Workflow\Console\RefillVendors;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/park.php', __DIR__.'/../routes/logistics.php', __DIR__.'/../routes/crm.php', __DIR__.'/../routes/web.php', __DIR__.'/../routes/auth.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        TelegramAvatars::class,
        CreateUser::class,
        TickOffers::class,
        AutoDraftsCommand::class,
        FillFromLettersCommand::class,
        PruneEmptyDrafts::class,
        MigtorgSync::class,
        MigtorgArchive::class,
        RefillVendors::class,
        TickPark::class,
        ParkDigestCommand::class,
        StoreByLettersCommand::class,
        SpbFactCommand::class,
        AutoRequestsCommand::class,
        ReleaseByLettersCommand::class,
        TickBilling::class,
        CloseMonthCommand::class,
        SyncMail::class,
        WatchMail::class,
        ReconcileMail::class,
        ReadMail::class,
        PriceMisses::class,
        RebuildChains::class,
        BackfillMail::class,
        ArchiveStaleCandidates::class,
        MakeKeys::class,
        Restamp::class,
        UnmarkPhotos::class,
        CropPages::class,
        DocScans::class,
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
        MergeCatalogCommand::class,
        GarageReformFixCommand::class,
        DealAgreementFixCommand::class,
        FillFromDocsCommand::class,
        FindValuesCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->alias(['ability' => EnsureAbility::class, 'section' => EnsureSection::class, 'park.manage' => EnsureParkManager::class, 'offer.edit' => EnsureOfferEditable::class, 'wall' => SiteWall::class]);
        $middleware->prepend([ServerTiming::class, RedirectLegacyPaths::class, MarkPrefetch::class, ScalarSearch::class]);
        // Поверхность и технические работы решаются до `auth`: гость на закрытой стоянке видит страницу, а не вход.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveSurface::class);
        $middleware->web(append: [NormalizeNumbers::class, ResolveSurface::class, SubscriberCookie::class, MarkInstalled::class, DetailBack::class, ActWindowBack::class, Impersonated::class, DeviceGate::class, TouchSeen::class, ReadNoticesOnVisit::class, DemoReadOnly::class]);
        $middleware->encryptCookies(except: [SubscriberCookie::NAME, 'theme', MarkInstalled::COOKIE, 'xcar-reset']);
        // Уведомления провайдеров: подписи у ЮKassa нет, статус перечитывается по API (`PayController::hook`).
        $middleware->validateCsrfTokens(except: ['hooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Два сохранения одной машины в одну секунду: сторож (`Cars\Identity`) пропустил оба, второе упёрлось в индекс
        // машины (`cars`: номер убытка, VIN живой) или в «у машины одна продажа и одно хранение» — ошибка поля, как у
        // сторожа, а не 500.
        $exceptions->map(UniqueConstraintViolationException::class, function (UniqueConstraintViolationException $e) {
            $field = match (true) {
                str_contains($e->getMessage(), 'cars_claim_ref_key_unique') => 'claim_ref',
                str_contains($e->getMessage(), 'cars_vin_active_unique') => 'vin',
                str_contains($e->getMessage(), 'offers_car_id_unique'), str_contains($e->getMessage(), 'park_vehicles_car_id_unique') => 'claim_ref',
                default => null,
            };

            return $field ? ValidationException::withMessages([$field => ($field === 'vin' ? 'VIN' : 'Номер убытка').' уже у другой записи, обновите страницу']) : $e;
        });
        // Адрес с номером вне int или не числом (`/purchases/1/2610051284` — номер предложения вместо машины закупки, старые
        // ссылки, боты): привязка модели роняла запрос 500 на SQL. На чтении это просто «нет такой страницы».
        // Подмена до отчёта и вида: 404 в лог не пишется, лишнего шума нет.
        $exceptions->map(QueryException::class, fn (QueryException $e) => request()->isMethodSafe() && in_array($e->getCode(), ['22003', '22P02'], true)
            ? new NotFoundHttpException(previous: $e) : $e);
    })->create();
