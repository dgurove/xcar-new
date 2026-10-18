<?php

namespace App\Http\Admin;

use App\Cars\Car;
use App\Cars\Vin\Vin;
use App\Chats\Chat;
use App\Garage\Car as GarageCar;
use App\Garage\GarageView;
use App\Live\Rows;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Scan\AutoScan;
use App\Mail\Thread;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\MergeOffers;
use App\Offers\Actions\PurgeOffer;
use App\Offers\Actions\ResolveProposal;
use App\Offers\Actions\ReturnToSale;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Actions\SendToAuction;
use App\Offers\Actions\ShowInGallery;
use App\Offers\Actions\UnlistParkOffer;
use App\Offers\Actions\UnscheduleOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Jobs\DropEmptyDraft;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\LegacyClaim;
use App\Offers\Margin;
use App\Offers\MigtorgDiff;
use App\Offers\Offer;
use App\Offers\OfferFiles;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Offers\Slots;
use App\Offers\Tag;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Sort;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class OfferController
{
    /**
     * Вкладки — шаги работы (владелец 04.10.2026): модератор заполнил закупочную — машина ушла в «Без продажной цены»
     * (05.10.2026: «Без цены» разделена — администратору нужен список только своего), админ оценил — в «Оцененные»,
     * отправили пачкой — в «Публикацию» (по слотам), вышла — в «Опубликованные», где выбирают победителя. Черновики из
     * закупок идут со всеми. Модератору те же вкладки: цены продажи, галочек и отправки у него нет.
     * «Есть подтверждения» (07.10.2026, начальник): опубликованное с хотя бы одним активным подтверждением уходит туда само,
     * последнее отклонили или отозвали — возвращается в «Опубликованные». Модератору подтверждения не видны — этой вкладки
     * у него нет, «Опубликованные» — всё открытое (`presets`).
     */
    public const PRESETS = [
        'nofloor' => 'Без закупочной цены', 'unpriced' => 'Без продажной цены', 'priced' => 'Оцененные', 'slots' => 'Публикация', 'published' => 'Опубликованные',
        'closed' => 'Приём закрыт', 'bids' => 'Есть подтверждения', 'archive' => 'Архив', 'legacy' => 'Из таблицы Кати', 'auction' => 'На аукцион',
    ];

    /**
     * Опубликованное: «Опубликованные», «Приём закрыт» и «Есть подтверждения» — одни правила сортировки и чипов. «Приём
     * закрыт» — своя вкладка с 17.10.2026 (начальник): была группой внизу «Опубликованных».
     */
    private const OPEN = ['published', 'closed', 'bids'];

    /** Вкладки, где порядок — когда предложение в них попало (`offers.tab_at`) и столбец даты — оно же. */
    public const BY_TAB = ['nofloor', 'unpriced', 'priced', 'archive', 'auction'];

    /** Вкладки человека: подтверждения, «Приём закрыт», «Из таблицы Кати» и «На аукцион» видит только админ (срок приёма модератору не виден). */
    public static function presets(User $user): array
    {
        return $user->canManageCrm() ? self::PRESETS : array_diff_key(self::PRESETS, ['bids' => true, 'closed' => true, 'legacy' => true, 'auction' => true]);
    }

    /** Прежние пилюли — в новые вкладки: ссылки из Telegram, закладки, `LegacyAdmin`. */
    private const LEGACY = [
        'draft' => 'unpriced', 'purchase' => 'unpriced', 'slot' => 'slots', 'open' => 'published', 'recommended' => 'published',
    ];

    /** Вкладки, где идёт работа по шагам: всегда таблица, без сортировки — порядок задаёт сам шаг. */
    private const STEPS = ['nofloor', 'unpriced', 'priced', 'slots', 'published', 'closed', 'bids'];

    /** Вкладки, где карточка после дела идёт к следующей строке. */
    private const TODO = ['nofloor', 'unpriced'];

    /** Вкладки дела: строка ушла — карточка сама к следующей (оценили, заполнили закупочную, приняли подтверждение). */
    private const ADVANCE = [...self::TODO, 'bids'];

    /** Вкладка по умолчанию — дело человека: модератору закупочная, админу цена продажи. */
    public static function home(User $user): string
    {
        return $user->canManageCrm() ? 'unpriced' : 'nofloor';
    }

    /** Есть ли у человека дело по строке на его шаге: закупочная — у всех, цена продажи и подтверждения — у админа. */
    public static function todo(Offer $offer, User $user): bool
    {
        // Админу дело и у открытого с подтверждениями («Есть подтверждения»): принять — и к следующему.
        return ($offer->state === OfferState::Draft && ! $offer->slot_at && ! $offer->asking_price && (! $offer->floor_price || $user->canManageCrm()))
            || ($offer->state === OfferState::Open && $user->canManageCrm() && (int) $offer->active_bids_count > 0);
    }

    /**
     * Поля сортировки (App\Support\Sort, владелец 06.10.2026: сортировка на всех вкладках, поле и направление). На вкладке —
     * только поля её столбцов (`sorts`): подтверждений нет там, где их быть не может.
     */
    public const SORTS = [
        'tab' => ['Во вкладке', 'desc'],
        'created' => ['Дата заведения', 'desc'],
        'published' => ['Дата публикации', 'desc'],
        'closing' => ['Закрытие приёма', 'asc'],
        'number' => ['Номер', 'desc'],
        'vendor' => ['Вендор', 'asc'],
        'floor' => ['Закупочная', 'asc'],
        'price' => ['Цена продажи', 'asc'],
        'bids' => ['Подтверждения', 'desc'],
    ];

    /** Сортировка вкладки: поля её столбцов, номер всегда, закрытие приёма — у опубликованных. */
    private static function sortFor(Request $request, string $preset, ?array $cols, bool $admin): Sort
    {
        $options = array_filter(self::SORTS, fn ($_, $key) => match ($key) {
            'number' => true,
            'closing' => in_array($preset, self::OPEN, true),
            'tab' => in_array($preset, self::BY_TAB, true),
            // Во вкладках шагов время, порядок и аватар — про приход во вкладку (18.10.2026, начальник: сортировал по
            // заведению, а в столбце время прихода — «непонятно, что значит»). Запомненная «Дата заведения» тут — умолчание.
            'created' => ! in_array($preset, self::BY_TAB, true),
            default => $cols === null || in_array($key, $cols, true),
        }, ARRAY_FILTER_USE_BOTH);
        // Шаги работы — по тому, когда предложение сюда пришло, новые сверху (15.10.2026, владелец: оценил закупочную — и ищи
        // машину внизу «Без продажной»); опубликованные у админа — по закрытию приёма; остальное — по заведению.
        $default = match (true) {
            $preset === 'closed' => '-closing',
            in_array($preset, self::OPEN, true) => $admin ? 'closing' : '-published',
            in_array($preset, self::BY_TAB, true) => '-tab',
            default => '-created',
        };

        return Sort::from($request->query('sort'), $options, $default);
    }

    public function index(Request $request)
    {
        $legacy = self::LEGACY[(string) $request->query('preset')] ?? null;
        if ($legacy) {
            $query = ['preset' => $legacy] + ($request->query('preset') === 'recommended' ? ['recommended' => '1'] : []) + $request->except(['preset', 'sort']);

            return redirect('/?'.http_build_query($query), 301);
        }
        // ?peek=номер — карточка строки рядом (first — первая строка без цены, адрес получает её номер ниже).
        $detail = Detail::of($request, fn (string $key) => $this->detailOf($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $admin = $request->user()->canManageCrm();
        // У каждой вкладки своя ссылка у всех; без вкладки в адресе — на вкладку человека по умолчанию (модератору
        // «Без закупочной цены», админу «Без продажной цены»). Поиск лупой вкладки не знает — его не уводим.
        $presets = self::presets($request->user());
        if (! array_key_exists((string) $request->query('preset'), $presets) && blank($request->query('q'))) {
            return redirect($request->fullUrlWithQuery(['preset' => self::home($request->user())]));
        }
        $preset = array_key_exists((string) $request->query('preset'), $presets) ? (string) $request->query('preset') : self::home($request->user());
        $step = in_array($preset, self::STEPS, true);
        // «Без цены» — без сортировки и вида, чипы есть (владелец 04.10.2026: «не хватает фильтров»), по умолчанию — без
        // Каркаде. «Рекомендуем» — переключатель у опубликованных.
        $facets = Facets::for($request, 'crm-offers', ...match ($preset) {
            // «Новые» (опубликовано за сутки) и «Горящие» (приём закрывается за 12 ч) — как пилюли каталога, голубой и рыжей
            // точкой и своим цветом включённого (12.10.2026, владелец); подтверждения и сроки видит только админ.
            'published', 'closed', 'bids' => [
                ...self::facets(),
                ...($admin ? [
                    Facet::toggle('urgent', 'Срочные', fn ($o) => $o->urgent())->dot('urgent'),
                    Facet::toggle('fresh', 'Новые', fn ($o) => $o->where('offers.state', OfferState::Open)->where('offers.published_at', '>=', now()->subHours(Offer::NEW_HOURS)))->dot('fresh'),
                    Facet::toggle('ending', 'Горящие', fn ($o) => CatalogQuery::ending($o))->dot('hot'),
                    Facet::toggle('loss', 'В минус', fn ($o) => Margin::scope($o))->dot('loss'),
                ] : []),
                Facet::toggle('recommended', 'Рекомендуем', fn ($o) => $o->where('recommended', true)),
            ],
            // Продажная у самой закупочной или ниже (`Offers\Margin`, 14.10.2026) — чип, пока такие есть.
            'priced', 'slots' => [
                ...self::facets(),
                ...($admin ? [Facet::toggle('loss', 'В минус', fn ($o) => Margin::scope($o))->dot('loss')] : []),
            ],
            default => self::facets(),
        })->always();
        ListPrefs::sync($request, 'crm-offers', keep: $facets->keys());
        $cols = self::columns($preset, $request->user());
        $sort = self::sortFor($request, $preset, $cols, $admin);
        // Поиск лупой идёт по всему разделу — мимо вкладки и чипов.
        $searching = $facets->searching();
        // Вендор ни разу не выбирали — все, кроме Каркаде (их десятки из закупки, оценивают отдельно), на любой вкладке:
        // фильтр при переключении вкладок не меняется (владелец 04.10.2026). Сняли чип — ListPrefs помнит пустое, и
        // умолчание больше не встаёт. В ссылки вкладок оно не едет: после чипов параметр убирается (ниже).
        $carcadeHidden = ! $searching && ! $request->query->has('vendor') && $this->hideCarcade($request, $preset);

        // Пустой «+ Новый» в списке не стоит: его либо заполнят, либо он удалится, как только из него уйдут.
        $q = Offer::query()->inCrm($request->user())->whereNot(fn ($o) => $o->emptyDraft())->with(['settlement', 'parkVehicle:id,car_id,state,accepted_at,created_at', 'deal', 'garageCar'])
            ->withCount(['activeBids', 'interests', 'activeBids as garage_bids_count' => fn ($b) => $b->where('kind', BidKind::Garage)])->withMax('activeBids as top_bid', 'amount');

        if ($searching) {
            $q->searchCrm(trim((string) $request->query('q')))->orderByDesc('updated_at');
        } else {
            self::scopeFor($preset, $q, $admin);
            self::order($q, $preset, $sort, $admin);
        }
        $facets->apply($q);
        // Числа вкладок — с тем же выбором, что и список (и «кроме Каркаде» по умолчанию).
        $counts = self::counts($request->user(), $facets, $carcadeHidden);
        if ($carcadeHidden) {
            $facets->chips();
            $request->query->remove('vendor');
        }

        $count = $q->count();
        // Вид — на любой вкладке (начальник 04.10.2026: «вернуть кнопку видов»); по умолчанию в CRM таблица, поиск — таблицей.
        // «Из таблицы Кати» — только таблица: призрачные строки есть только у неё.
        // Пачки («Публикация» по слоту, «Опубликованные» и «Есть подтверждения» у админа) — вид «Лентами»: ленты вбок, весь
        // список одной страницей (11.10.2026): пачку, порванную страницей, в ленте не долистать. «Плитками» — одна сетка со
        // страницами (15.10.2026).
        $packable = ! $searching && ($preset === 'slots' || (in_array($preset, ['published', 'bids'], true) && $request->user()->isAdmin()));
        $view = $searching || $preset === 'legacy' ? ListView::TABLE : ListView::pick($request, $count, shelves: $packable);
        $packed = $view === ListView::SHELF;
        // Живое обновление строкой (`Live\Rows`) — после итога и чисел вкладок: они в ответе настоящие.
        Rows::only($q, 'offers.number');
        $offers = ListView::isTable($view) ? $q->paginate(max($count, 1), total: $count)->withQueryString() : ListView::paginate($request, $q, $count, whole: $packed);
        // Кадры нужны плиткам и строкам; в таблице их нет — кроме «Оцененных»: там по ним видно, готово ли к продаже.
        if (! ListView::isTable($view) || ($preset === 'priced' && ! $searching)) {
            $offers->loadMissing('media');
        }
        // Кто завёл — аватар у даты (столбец «Создано» на ПК у черновика, краткая таблица телефона — у всех строк, 09.10.2026).
        $offers->getCollection()->load(['moderator.media', 'tabBy.media']);
        // Оценки менеджеров — на месте «Оценить» у черновика без цены продажи (`x-offer.rate-chip`).
        if ($admin) {
            $offers->getCollection()->load(['openEstimates.user', ...User::withAvatar('openEstimates.user.media')]);
        }
        // «Оценить» с чипа или из закупки: first — первая строка, где у человека есть дело.
        if (Detail::key($request) === 'first') {
            $first = $offers->first(fn ($o) => self::todo($o, $request->user())) ?? $offers->first();

            return redirect($request->fullUrlWithQuery(['peek' => $first?->number]));
        }

        return view('admin.offers.index', [
            'offers' => $offers,
            'detail' => $detail,
            'view' => $view,
            'packable' => $packable,
            'step' => $step,
            'searching' => $searching,
            'presets' => $presets,
            'preset' => $preset,
            'sort' => $searching ? null : $sort,
            'facets' => $facets,
            // Галочки и отправка — админу, в «Оцененных» и «Публикации».
            'pick' => self::pickable($request->user(), $preset, $searching),
            'cols' => self::columns($preset, $request->user(), $searching),
            'counts' => $counts,
            // Черновик завела почта и файлы с документами ещё догружаются — строка входит живьём (`AutoScan::states`,
            // `arrive_controller`), как заявка на парковке.
            'arrive' => AutoScan::states($offers->getCollection()->filter(fn (Offer $o) => $o->state === OfferState::Draft && ! $o->moderator_id)),
            'ghosts' => $preset === 'legacy' && ! $searching && self::ghostsShown($facets) ? LegacyClaim::ghosts()->get() : collect(),
        ]);
    }

    /** Вендор Каркаде: на проде он «Carcade», локально «Каркаде» — как его ищет миграция закупок. */
    private static function carcadeId(): ?int
    {
        return Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->value('id');
    }

    /** Подставить в запрос «все, кроме Каркаде» (`!id` — исключение, Facets); Каркаде на вкладке нет — false. */
    private function hideCarcade(Request $request, string $preset): bool
    {
        // «Есть подтверждения» — всё, что подтвердили менеджеры (11.10.2026, владелец: подтверждения Армена на Каркаде
        // админ не видел): умолчание «Кроме Каркаде» на этой вкладке не встаёт.
        if ($preset === 'bids') {
            return false;
        }
        $carcade = self::carcadeId();
        if (! $carcade || ! self::scopeFor($preset, Offer::visibleTo($request->user())->whereNot(fn ($o) => $o->emptyDraft()), $request->user()->canManageCrm())->where('vendor_id', $carcade)->exists()) {
            return false;
        }
        $request->query->set('vendor', '!'.$carcade);

        return true;
    }

    /**
     * Условие вкладки — одно место для списка, чисел у вкладок и таб-бара. admin — видит ли человек подтверждения: без них
     * «Опубликованные» — всё открытое.
     */
    public static function scopeFor(string $preset, Builder $q, bool $admin = true): Builder
    {
        $bids = fn ($b) => $b->where('state', BidState::Active);

        // Черновики из таблицы Кати — только в своей вкладке, рабочим не мешают (10.10.2026; 12.10.2026 начальник снова:
        // «не отображать»).
        if (in_array($preset, ['nofloor', 'unpriced', 'priced', 'slots'], true)) {
            LegacyClaim::offList($q);
        }
        // Отправленные на аукцион (13.10.2026) — только во вкладке «На аукцион», рабочим и таблице Кати не мешают.
        if (in_array($preset, ['nofloor', 'unpriced', 'priced', 'slots', 'legacy'], true)) {
            $q->whereNull('offers.auction_at');
        }

        return match ($preset) {
            'legacy' => LegacyClaim::onList($q->where('offers.state', OfferState::Draft)),
            'auction' => $q->where('offers.state', OfferState::Draft)->whereNotNull('offers.auction_at'),
            'nofloor' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))
                ->where(fn ($p) => $p->whereNull('floor_price')->orWhere('floor_price', 0)),
            'unpriced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))->where('floor_price', '>', 0),
            'priced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where('asking_price', '>', 0),
            'slots' => $q->scheduled(),
            // Идёт приём (срок не прошёл или его нет); у админа — ещё без подтверждений, они в «Есть подтверждения». Модератору
            // — всё открытое: срока приёма он не видит, «Приём закрыт» у него нет.
            'published' => $q->where('offers.state', OfferState::Open)
                ->when($admin, fn ($o) => $o->where(fn ($c) => $c->whereNull('offers.bids_close_at')->orWhere('offers.bids_close_at', '>', now()))
                    ->whereDoesntHave('bids', $bids)),
            'closed' => $q->where('offers.state', OfferState::Open)->where('offers.bids_close_at', '<=', now())
                ->when($admin, fn ($o) => $o->whereDoesntHave('bids', $bids)),
            'bids' => $q->where('offers.state', OfferState::Open)->whereHas('bids', $bids),
            default => $q->whereIn('offers.state', [OfferState::Archived, OfferState::Cancelled]),
        };
    }

    /**
     * Опубликованные — группами по тому, что делать: 0 «Выбрать» (приём закрыт, подтверждения есть), 1 «Идёт приём»
     * (скоро закроются — выше), 2 «Приём закрыт» без подтверждений. С 07.10.2026 подтверждения — своя вкладка: в «Есть
     * подтверждения» группы 0 и 1; «Опубликованные» — только 1, группа 2 — вкладка «Приём закрыт» (17.10.2026).
     */
    public const PICK_GROUPS = ['Выбрать', 'Идёт приём', 'Приём закрыт'];

    private const PICK_GROUP = "case when bids_close_at is not null and bids_close_at <= now() and exists (select 1 from bids where bids.offer_id = offers.id and bids.state = 'active') then 0 when bids_close_at is null or bids_close_at > now() then 1 else 2 end";

    /**
     * Порядок вкладки: группы первыми («Публикация» — по слоту, «Опубликованные» у админа — `PICK_GROUP`), внутри —
     * выбранное поле, последним — заведение. Порядок прохода черновиков не меняется от правки поля: по умолчанию по
     * заведению, новые сверху.
     */
    private static function order(Builder $q, string $preset, Sort $sort, bool $admin): void
    {
        $dir = $sort->dir();
        $pick = in_array($preset, self::OPEN, true) && $admin;
        match (true) {
            $preset === 'slots' => $q->orderBy('slot_at'),
            $pick => $q->orderByRaw(self::PICK_GROUP),
            // Менеджер оценил — решение за админом: такие строки первыми (11.10.2026), дальше выбранный порядок.
            in_array($preset, ['unpriced', 'priced'], true) && $sort->isDefault() => $q->orderByRaw("exists (select 1 from offer_estimates e where e.offer_id = offers.id and e.state = 'given' and (e.base_price is null or e.amount <> e.base_price)) desc"),
            default => null,
        };
        // Закрытие приёма у групп опубликованного — как было: в «Выбрать» и «Идёт приём» ближние выше, в «Без
        // подтверждений» — недавно закрытые.
        if ($pick && $sort->value() === 'closing') {
            $q->orderByRaw('case when ('.self::PICK_GROUP.') = 2 then null else bids_close_at end asc nulls last')->orderByDesc('bids_close_at');
        }
        $bids = "(select count(*) from bids where bids.offer_id = offers.id and bids.state = '".BidState::Active->value."')";
        match ($sort->key) {
            'tab' => $q->orderByRaw("offers.tab_at {$dir} nulls last"),
            'published' => $q->orderByRaw("published_at {$dir} nulls last"),
            'closing' => $q->orderByRaw("bids_close_at {$dir} nulls last"),
            'number' => $q->orderBy('offers.number', $dir),
            'vendor' => $q->orderByRaw("(select name from vendors where vendors.id = offers.vendor_id) {$dir} nulls last"),
            'floor' => $q->orderByRaw("offers.floor_price {$dir} nulls last"),
            'price' => $q->orderByRaw("offers.asking_price {$dir} nulls last"),
            'bids' => $q->orderByRaw("{$bids} {$dir}"),
            default => $q->orderBy('offers.id', $dir),
        };
        $q->orderByDesc('offers.id');
    }

    /**
     * Галочки первым столбцом — админу в «Оцененных», «Публикации» и «Архиве», не в поиске. Одна дверь для списка и свежей
     * строки из карточки (`admin/offers/detail` → `row`): там своё правило без «Архива» давало строку без галочки, и
     * открытая строка съезжала относительно соседей (11.10.2026).
     */
    public static function pickable(User $user, ?string $preset, bool $searching): bool
    {
        return $user->canManageCrm() && ! $searching && in_array($preset, ['priced', 'slots', 'archive'], true);
    }

    /** Группа опубликованного (PICK_GROUPS) — то же правило, что у порядка. */
    public static function pickGroup(Offer $offer): int
    {
        if (! $offer->bids_close_at || $offer->bids_close_at->isFuture()) {
            return 1;
        }

        return $offer->active_bids_count ? 0 : 2;
    }

    /**
     * Столбцы таблицы по вкладке — только то, что на этом шаге что-то значит (владелец 04.10.2026: «в „Без цены“ не может
     * быть подтверждений»). Ключи: vendor — вендор и № убытка, no — № (у черновика — кто завёл; без него аватар — у даты),
     * state — приём или состояние, bids — подтверждения, floor — закупочная, price, created / published — когда заведено
     * / вышло. Порядок столбцов задаёт шаблон: закупочная перед ценой продажи.
     * null — все (поиск по разделу, галерея).
     *
     * @return list<string>|null
     */
    public static function columns(?string $preset, User $user, bool $searching = false): ?array
    {
        if ($searching || ! array_key_exists((string) $preset, self::PRESETS) && $preset !== null) {
            return null;
        }
        $admin = $user->canManageCrm();

        $cols = match ($preset ?? 'unpriced') {
            // Закупочную заполняют — цены продажи здесь не бывает.
            'nofloor' => ['vendor', 'city', 'floor', 'created'],
            // Оценка: закупочная — ориентир, «Оценить» на месте цены; у черновика номера нет, кто завёл — аватаром у даты.
            // Город — сразу за номером убытка (владелец 04.10.2026: «не хватает столбца с городом»). Оценочной в таблице нет
            // (владелец 05.10.2026: «скрой») — она в карточке строки, в «Деньгах».
            'unpriced', 'priced', 'legacy', 'auction' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Когда выйдет — заголовок группы, слова в строке не нужно.
            'slots' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Модератору подтверждения не видны, а «в продаже» здесь и так у всех.
            // В «Опубликованных» у админа подтверждений нет по определению — столбца тоже.
            'published', 'closed' => ['vendor', 'city', ...($admin ? ['state'] : []), 'floor', 'price', 'published'],
            'bids' => ['vendor', 'city', 'state', 'bids', 'floor', 'price', 'published'],
            default => ['vendor', 'city', 'state', 'floor', 'price', 'created'],
        };

        // Шаги работы: дата — когда пришло во вкладку (`tab_at`), в её порядке (15.10.2026).
        return in_array($preset ?? 'unpriced', self::BY_TAB, true) ? array_map(fn ($c) => $c === 'created' ? 'tab' : $c, $cols) : $cols;
    }

    /**
     * Числа у вкладок с чипами (их у «Без цены» нет — и число без них); «Рекомендуем» — только у опубликованных.
     *
     * @return array<string, int>
     */
    /** carcadeDefault — «Кроме Каркаде» стоит умолчанием, а не выбором: число «Есть подтверждения» считается без него. */
    public static function counts(User $user, ?Facets $facets = null, bool $carcadeDefault = false): array
    {
        $base = fn () => Offer::inCrm($user)->whereNot(fn ($o) => $o->emptyDraft());
        $counts = [];
        foreach (array_diff(array_keys(self::presets($user)), ['archive']) as $key) {
            $q = self::scopeFor($key, $base(), $user->canManageCrm());
            // Без чипов (поток после «Оценить») «Без цены» считается как вид по умолчанию — без Каркаде.
            if (! $facets && in_array($key, self::TODO, true) && ($carcade = self::carcadeId())) {
                $q->where(fn ($v) => $v->whereNull('offers.vendor_id')->orWhere('offers.vendor_id', '!=', $carcade));
            }
            if ($facets) {
                $facets->applyTo($q, match (true) {
                    $key === 'bids' && $carcadeDefault => 'vendor',
                    in_array($key, self::OPEN, true) => null,
                    default => 'recommended',
                });
            }
            // У «Из таблицы Кати» в числе и незаведённые номера — призрачные строки. Черновик из письма встаёт туда из
            // призрака, и число не меняется, — пилюле отдельно число черновиков: выросло — она вспыхивает (flip.js).
            $counts[$key] = $q->count() + ($key === 'legacy' && self::ghostsShown($facets) ? LegacyClaim::ghosts()->count() : 0);
            if ($key === 'legacy') {
                $counts['legacy_drafts'] = $q->count();
            }
            // Лаймовый бейдж у «Без закупочной», «Без продажной цены» и «Оцененных» — сколько ответов менеджеров ждут решения: оценки и свои цены
            // вместо цены админа («Согласен» решения не ждёт), 11.10.2026.
            if (in_array($key, ['nofloor', 'unpriced', 'priced'], true) && $user->canManageCrm()) {
                $counts[$key.'_given'] = (clone $q)->whereHas('estimates', fn ($e) => $e->needsDecision())->count();
            }
        }

        return $counts;
    }

    /**
     * Незаведённые номера «Из таблицы Кати» — пока не выбрали вендора, город или тип: у призрачной строки их нет.
     * Исключение («Кроме Каркаде») их не прячет.
     */
    private static function ghostsShown(?Facets $facets): bool
    {
        return ! $facets || ! array_any($facets->keys(), fn ($k) => $facets->excluded($k) === null && $facets->selected($k) !== []);
    }

    /** Чипы списка предложений — те же у галереи. */
    public static function facets(): array
    {
        return [Common::vendor('offers.vendor_id')->none('Без вендора'), Common::city('offers.settlement_id'), Common::category(Car::sql('offers', 'category'))];
    }

    public function store(Request $request, CreateOffer $create)
    {
        $offer = $create($request->user(), $this->carried($request->user()));

        return redirect("/offers/{$offer->number}");
    }

    /**
     * Вендор не спрашивается второй раз: новый черновик человека получает вендора его прошлого заведённого руками
     * (перенос из закупки и «Завести» из писем не в счёт — иначе после Carcade ручное встало бы на его маршрут) и НДС
     * цен этого вендора. Подряд заводят пачку от одной страховой.
     */
    private function carried(User $user): array
    {
        $vendorId = Offer::where('moderator_id', $user->id)->whereNotNull('vendor_id')->whereDoesntHave('purchaseCar')
            ->whereNotIn('id', Candidate::whereNotNull('offer_id')->select('offer_id'))->latest('id')->value('vendor_id');

        return $vendorId ? ['vendor_id' => $vendorId, 'prices_include_vat' => Vendor::offersVat($vendorId)] : [];
    }

    public function edit(Request $request, Offer $offer)
    {
        // Модератору — поля, фото, документы и история: подтверждений, интереса, сделки, маршрута, чатов и круга показа
        // он не видит, и грузить их незачем.
        $admin = $request->user()->canManageCrm();
        $offer->load(['settlement', 'media', 'events.user', ...($admin ? ['bids.user', 'interests.user.manager', 'interests.placer', 'vendor.workflows', 'parkVehicle.yard', 'parkVehicle.requests',
            'positions.stage.block', 'positions.stage.exits.to.block.stages', 'positions.stage.workflow', 'deal.buyer', 'dealAudience', 'lastDeal.buyer', 'lastDeal.bid'] : [])]);
        // Давно закрытое предложение лежит в холодном слое без конверсий — досчитать, раз открыли.
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer);
        }

        $threads = Thread::where('offer_id', $offer->id)->get();
        $fromMail = $offer->state === OfferState::Draft && session()->has("mail-draft.{$offer->id}");
        // Пустой «+ Новый»: первым — поле для текста про машину; уйдут, ничего не внеся, — черновика нет. Открыли снова
        // (в том числе обновили страницу) — отметка, чтобы удаление по прошлому уходу его не тронуло.
        $empty = ! $fromMail && $offer->state === OfferState::Draft && Offer::emptyDraft()->whereKey($offer->id)->exists();
        if ($empty) {
            Offer::whereKey($offer->id)->update(['updated_at' => now()]);
        }

        return view('admin.offers.edit', OfferFiles::letters($offer, $request->user()) + [
            'offer' => $offer,
            'admin' => $admin,
            'threads' => $threads,
            // ?window= — открыть окно писем сразу (ссылка «Вся переписка» из шторки документов).
            'window' => str_starts_with((string) request()->query('window'), "/offers/{$offer->number}/letters") ? request()->query('window') : null,
            'docs' => OfferFiles::docs($offer, $threads),
            // Все чаты по машине одной выборкой: из неё же карточка чата сделки (`x-deal.chat-card`), без второго запроса.
            'chats' => $admin ? Chat::withLast()->where('offer_id', $offer->id)->orderByDesc('last_message_at')->get() : collect(),
            'import' => ImportThreadFiles::progress($offer->id),
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            'managers' => $admin ? User::withRole(Role::Manager)->orderBy('name')->get() : collect(),
            'showingSummary' => $admin ? Showing::summary($offer) : collect(),
            // Черновик только что заведён из писем и ещё ни разу не сохранён: внизу «Отменить» и «Не заявка».
            'fromMail' => $fromMail,
            // Только что заведён «Завести» из таблицы Кати: внизу «Отменить» — пока не сохранили.
            'fromLegacy' => ! $fromMail && $offer->state === OfferState::Draft && session()->has("legacy-draft.{$offer->id}"),
            'empty' => $empty,
            // Машина в гараже ведётся здесь же, дорожкой «Гараж» (подготовка) и хвостом «Продажи»: путь, деньги, расходы и
            // все действия сотрудника.
            'garageView' => $admin ? self::garageView($offer, $request->user()) : null,
            // Сделка — третьей дорожкой (страница сделки CRM с 06.10.2026 — этот редактор): идущая, иначе состоявшаяся.
            'deal' => $admin ? $offer->lastDeal : null,
        ]);
    }

    /** Карточка «Гараж» в редакторе и в «Работе → Гараж»: что рисовать и какие действия доступны. */
    public static function garageView(Offer $offer, User $user): ?array
    {
        $car = GarageCar::with(['manager', 'costs.author', 'invoice', 'payoutInvoice', 'deal'])->where('offer_id', $offer->id)->first();
        if (! $car) {
            return null;
        }
        $car->setRelation('offer', $offer);
        $car->costs->each->setRelation('car', $car);

        return GarageView::for($car, $user);
    }

    /**
     * Шторка документов редактора: письмо вендора, документы предложения, файлы писем, которых среди них нет
     * (пока идёт импорт, документов ещё нет), фото.
     *
     * @param  Collection<int, Thread>  $threads
     * @return list<array<string, mixed>>
     */
    /**
     * Другие предложения с тем же VIN или номером убытка (twins_controller под полями): второе на ту же машину видно до
     * сохранения. Чужое модератору (опубликованное, из закупки) — строкой без ссылки: знать, что оно есть, надо.
     */
    public function twins(Request $request)
    {
        $vin = Vin::full((string) $request->query('vin'));
        $claim = Code::key((string) $request->query('claim_ref'));
        $except = (int) $request->query('except') ?: null;
        // Кадр Мигторга назвал номер, а он уже у другого предложения (`ImportMigtorgLot::byPhoto`) — та же строка.
        $twin = $except ? Cache::get("offer:twin:{$except}") : null;
        $offers = ! $vin && ! $claim && ! $twin ? collect() : Offer::with([])
            ->where(fn ($w) => $w->when($vin || $claim, fn ($w) => $w->whereCar(fn ($c) => $c->when($vin, fn ($c) => $c->orWhere('vin', $vin))->when($claim, fn ($c) => $c->orWhere('claim_ref_key', $claim))))
                ->when($twin, fn ($w) => $w->orWhereKey($twin)))
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))->latest('id')->limit(3)->get();
        $self = $except ? Offer::find($except) : null;

        return view('admin.offers.twins', ['offers' => $offers, 'user' => $request->user(), 'self' => $self?->state === OfferState::Draft ? $self : null]);
    }

    /**
     * «Это она» у двойника: черновик — та же машина, что найденное предложение. Его кадры, документы, письма и поля
     * уходят туда (`MergeOffers`), черновика нет, редактор открывается на найденном.
     */
    public function mergeInto(Request $request, Offer $offer, Offer $target, MergeOffers $merge)
    {
        abort_unless($offer->state === OfferState::Draft && $target->isEditableBy($request->user()) && ! $offer->bids()->exists(), 404);
        session()->forget("mail-draft.{$offer->id}");
        Cache::forget("offer:twin:{$offer->id}");
        $moved = $merge($target, $offer, $request->user())['photos'];

        return redirect("/offers/{$target->number}")->with('toast', $moved ? "Это №{$target->number}, фото перенесены" : "Это №{$target->number}");
    }

    /** Карточка строки рядом со списком (Detail): чужое модератору — как не найдено. */
    public function detailOf(Request $request, ?Offer $offer, bool $gallery = false)
    {
        return $offer && $offer->isEditableBy($request->user()) ? $this->detail($request, $offer, $gallery) : null;
    }

    /** Карточка строки: фото, метки, цена, действия, подтверждения, интерес; gallery — строка списка галереи. */
    public function detail(Request $request, Offer $offer, ?bool $gallery = null)
    {
        $admin = $request->user()->canManageCrm();
        $offer->load(['settlement', 'media', ...($admin ? ['bids.user', 'interests.user.manager', 'interests.placer', 'deal', 'purchaseCar.offers.user', 'purchaseCar.purchase', 'parkVehicle:id,car_id,state,yard_id,accepted_at,created_at', 'parkVehicle.yard:id,name', 'vendor.workflows', 'positions.stage', 'openEstimates.user', ...User::withAvatar('openEstimates.user.media'), 'declinedEstimates.user', ...User::withAvatar('declinedEstimates.user.media')] : [])])
            ->loadCount(['activeBids', 'interests', 'activeBids as garage_bids_count' => fn ($b) => $b->where('kind', BidKind::Garage)])->loadMax('activeBids as top_bid', 'amount');

        return view('admin.offers.detail', OfferFiles::letters($offer, $request->user()) + [
            'offer' => $offer,
            'admin' => $admin,
            // Поля редактора в карточке — те же справочники, что у страницы; модератору денег, кроме закупочной, и круга нет.
            'tags' => $admin ? Tag::orderBy('sort')->get() : collect(),
            'chats' => $admin ? Chat::where('offer_id', $offer->id)->get(['id', 'unread_for_staff']) : collect(),
            'list' => $gallery ?? $request->boolean('gallery'),
            // Кому отдать на оценку — у черновика без цены; на корректировку — у оценённого, пока не в слоте.
            'managers' => $admin && $offer->state === OfferState::Draft && ! $offer->isScheduled() ? User::withRole(Role::Manager)->with(User::withAvatar())->orderBy('name')->get() : collect(),
        ]);
    }

    /**
     * «Сохранить» — и в список предложений; у черновика «Опубликовать» — та же форма с `then=open`: сначала поля, потом
     * в продажу; «+ Новый» (`then=next`) — сразу следующий черновик с тем же вендором: пачку заводят подряд.
     */
    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update, ScheduleOffer $schedule, CreateOffer $create)
    {
        $unpriced = $offer->state === OfferState::Draft && ! $offer->asking_price;
        $hadFloor = (bool) $offer->floor_price;
        $update($offer, $request->payload(), $request->user());
        session()->forget(["mail-draft.{$offer->id}", "legacy-draft.{$offer->id}"]);
        // Нажали знак Мигторга в поле номера убытка: несохранённые правки формы (вендор, цены) легли вместе с номером.
        if ($request->input('then') === 'migtorg') {
            return back()->with('toast', ImportMigtorgLot::take($offer->refresh(), $request->user()));
        }
        if ($request->input('then') === 'next') {
            $next = $create($request->user(), $this->carried($request->user()));

            return redirect("/offers/{$next->number}")->with('toast', 'Сохранено');
        }
        // «Сохранить» у черновика — и закрыть редактор: следующий шаг черновика в списке.
        if ($request->input('then') === 'close') {
            return redirect('/')->with('toast', 'Сохранено');
        }
        if ($request->input('then') === 'open' && $offer->state === OfferState::Draft && $request->user()->canManageCrm()) {
            $offer = $schedule($offer->refresh(), $this->when($request, Slots::NEAREST), $request->user(), until: Slots::urgentUntil($request->input('urgent_until')));

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer));
        }

        // Сохранили из карточки, и строка больше не подходит под вкладку (заполнили закупочную — уехала в «Без продажной
        // цены», поставили цену — в «Оцененные», стёрли — назад): строка уходит, на вкладках дела карточка — к следующей.
        // Тихое сохранение перед другой кнопкой (save_bar, ajax) этого не делает.
        $saved = match (true) {
            $unpriced && (bool) $offer->asking_price => 'Оценено: '.Money::rub($offer->asking_price),
            ! $hadFloor && (bool) $offer->floor_price => 'Закупочная: '.Money::rub($offer->floor_price),
            default => 'Сохранено',
        };
        if (! $request->ajax() && ($gone = self::gone($request, $offer->refresh()))) {
            return back()->with('toast', $saved)->with($gone);
        }

        // «Оценить» / «Сохранить» закупочной сверху карточки — та же форма с `then=advance`: пустое поле — просто дальше.
        if ($request->input('then') === 'advance') {
            return back()->with('detail-advance', true)->with('toast', $request->payload() ? $saved : null);
        }

        // «Сохранить изменения» — остаёмся там же (04.10.2026: кнопка выезжает после правки, экран не меняется); из
        // карточки строки DetailBack вернёт в список с той же карточкой.
        return back()->with('toast', 'Сохранено');
    }

    /**
     * Ушли из редактора черновика, ничего не внеся (`drop_empty_controller`, sendBeacon): черновика вскоре нет
     * (`DropEmptyDraft`). Внесли хоть что-то, загрузили фото, пришли письма — сервер не тронет его, что бы ни прислал
     * браузер.
     */
    public function dropEmpty(Offer $offer)
    {
        if ($offer->state === OfferState::Draft) {
            DropEmptyDraft::dispatch($offer->id, now());
        }

        return response()->noContent();
    }

    /** Черновик из парковки завели в продажу зря: отвязать ТС и удалить («Снять с продажи» в шапке редактора). */
    public function unlist(Offer $offer, UnlistParkOffer $unlist)
    {
        $unlist($offer, request()->user());

        return redirect('/')->with('toast', 'Снято с продажи');
    }

    /** «Принять» на плашке «Предложено из заявок на приём»: черновик остаётся в своей вкладке, плашка уходит. */
    public function acceptProposal(Request $request, Offer $offer, ResolveProposal $resolve)
    {
        $resolve->accept($offer, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Принято');
    }

    /**
     * «Отклонить» на той же плашке: черновик удаляется, ТС остаётся на парковке со всеми файлами (`ResolveProposal`). Из
     * окошка ответ — список с той же карточкой: её больше нет, и пустой фрейм уносит строку, тикает пилюлями и ведёт окно
     * к следующей (`Detail::response`, `x-ui.detail-streams`).
     */
    public function declineProposal(Request $request, Offer $offer, ResolveProposal $resolve)
    {
        $resolve->decline($offer, $request->user());

        return redirect('/?preset=unpriced')->with('toast', 'ТС осталась на парковке')
            ->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()));
    }

    /** Продлить приём на ходу: от текущего срока, если он ещё не прошёл, иначе от сейчас. */
    public function extend(Request $request, Offer $offer, UpdateOffer $update)
    {
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:15,60']])['minutes'];
        $from = $offer->bids_close_at?->isFuture() ? $offer->bids_close_at : now();
        $offer = $update($offer, ['bids_close_at' => $from->copy()->addMinutes($minutes)], $request->user());

        // Из «Приём закрыт» строка уходит сразу (в «Опубликованные»), числа вкладок — следом, без обновления страницы.
        return back()->with('toast', 'Приём до '.$offer->bids_close_at->translatedFormat('j M, H:i'))->with(self::gone($request, $offer->refresh()));
    }

    /**
     * «Оценить» во вкладке «Без цены»: только цена продажи — машина уходит в «Оцененные», карточка переходит к следующей
     * без цены. Публикации здесь нет: в продажу отправляют пачкой из «Оцененных». Пустое поле — просто дальше.
     */
    public function rate(Request $request, Offer $offer, UpdateOffer $update)
    {
        $raw = (int) Money::rubles($request->validate(['asking_price' => ['nullable', 'string', 'max:20']])['asking_price'] ?? null);
        if (! $raw || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, ['asking_price' => $raw], $request->user());

        return back()->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()))
            ->with('toast', 'Оценено: '.Money::rub($raw));
    }

    /** «!» Мигторга: «Взять» его значение или «Отклонить» (`MigtorgDiff`); ответ — новый список разниц. */
    public function migtorg(Request $request, Offer $offer, string $field, string $act)
    {
        $done = $act === 'take' ? MigtorgDiff::take($offer, $field, $request->user()) : MigtorgDiff::reject($offer, $field, $request->user());

        return response()->json(['ok' => $done, 'fields' => (object) MigtorgDiff::of($offer->refresh())]);
    }

    /**
     * «Закупочная» сверху карточки во вкладке «Без закупочной цены» (05.10.2026): модератор вписал закупочную (у Альфы —
     * оценочную, закупочная посчитана на лету) — машина уходит в «Без продажной цены», карточка — к следующей. Пустое
     * поле — просто дальше.
     */
    public function floor(Request $request, Offer $offer, UpdateOffer $update)
    {
        $data = $request->validate(['floor_price' => ['nullable', 'string', 'max:20'], 'value' => ['nullable', 'string', 'max:20']]);
        $money = fn (?string $v) => Money::rubles($v) ?: null;
        $floor = $money($data['floor_price'] ?? null);
        $value = $money($data['value'] ?? null);
        if (! $floor || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, array_filter(['floor_price' => $floor, 'value' => $value]), $request->user());
        $gone = self::gone($request, $offer->refresh());

        return back()->with('detail-advance', true)->with($gone)->with('toast', 'Закупочная: '.Money::rub($floor));
    }

    /**
     * «Отправить в продажу» пачкой из «Оцененных» и действия «Публикации»: сейчас, в ближайший или следующий слот (у
     * стоящего в слоте — перенос). Не готовое (нет фото) остаётся на месте, остальные уходят. «Сейчас» пачкой —
     * менеджеру одно «Опубликовано N» тиком часов, а не по уведомлению на каждое.
     */
    public function scheduleMany(Request $request, ScheduleOffer $schedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer'], 'when' => ['nullable', 'string']]);
        $when = $this->when($request, Slots::NEAREST);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        $done = 0;
        $failed = [];
        foreach ($offers as $offer) {
            try {
                $schedule($offer, $when, $request->user(), batch: $offers->count() > 1, until: Slots::urgentUntil($request->input('urgent_until')));
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $offer->titleWithYear().', нет '.str_replace(['Для публикации не хватает: ', 'фотографии'], ['', 'фото'], (string) collect($e->errors())->flatten()->first());
            }
        }
        $at = Slots::at($when);
        $toast = $done ? ($at ? 'Выйдут '.Slots::phrase($at).': '.$done : 'Опубликовано: '.$done) : null;
        $back = back(fallback: '/?preset=priced');

        return $failed
            ? $back->with('toast-danger', ($toast ? $toast.'. ' : '').'Не вышли '.count($failed).': '.implode('; ', array_slice($failed, 0, 3)))
            : $back->with('toast', $toast);
    }

    /** «Убрать из слота» пачкой: машины возвращаются в «Оцененные». */
    public function unscheduleMany(Request $request, UnscheduleOffer $unschedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        $offers->each(fn ($o) => $unschedule($o, $request->user()));

        return back(fallback: '/?preset=slots')->with('toast', 'Убрано из слота: '.$offers->count());
    }

    /** «Удалить навсегда» из архива — одно предложение (карточка, редактор) со всем связанным (`PurgeOffer`). */
    public function purge(Offer $offer, PurgeOffer $purge)
    {
        $title = $offer->titleWithYear();
        $purge($offer);

        return redirect('/?preset=archive')->with('toast', 'Удалено навсегда: '.$title);
    }

    /** «Удалить навсегда» пачкой из вкладки «Архив»: не из архива (успели вернуть) пропускается. */
    public function purgeMany(Request $request, PurgeOffer $purge)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        $done = 0;
        foreach (Offer::whereIn('number', $data['offers'])->whereIn('state', [OfferState::Archived, OfferState::Cancelled])->get() as $offer) {
            $purge($offer);
            $done++;
        }

        return redirect('/?preset=archive')->with('toast', 'Удалено навсегда: '.$done);
    }

    /** Состояние из меню; «Опубликовать» у черновика и галереи — с выбором слота (`when`), без него — сейчас. */
    public function state(Request $request, Offer $offer, ChangeOfferState $change, ScheduleOffer $schedule)
    {
        $next = OfferState::from($request->validate(['state' => ['required', 'string']])['state']);
        // В сделку руками нельзя: сделку заводит «Принять» у подтверждения.
        if ($next === OfferState::Sold) {
            return back()->withErrors(['state' => 'Примите подтверждение менеджера, сделка заведётся сама']);
        }
        // «В галерею» — окно показа черновика, не переход; висит — «Убрать из галереи».
        if ($next === OfferState::Gallery) {
            $gallery = app(ShowInGallery::class);
            $offer->isGallery() ? $gallery->hide($offer, $request->user()) : $gallery($offer, $request->user());

            return redirect("/offers/{$offer->number}")->with('toast', $offer->isGallery() ? 'В галерее до '.$offer->gallery_until->translatedFormat('j M, H:i') : 'Убрано из галереи')->with(self::gone($request, $offer));
        }
        if ($next === OfferState::Open && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            $offer = $schedule($offer, $this->when($request, Slots::NOW), $request->user(), until: Slots::urgentUntil($request->input('urgent_until')));

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer))->with(self::gone($request, $offer));
        }
        $change($offer, $next, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $next->label())->with(self::gone($request, $offer));
    }

    /**
     * «На аукцион» из «Действий» (13.10.2026): `mode=now` — снять с публикации и сразу во вкладку, `after` — по истечении
     * срока, если подтверждений не будет (`SendToAuction`); DELETE — отменить план или убрать с аукциона.
     */
    public function auction(Request $request, Offer $offer, SendToAuction $send)
    {
        $mode = $request->validate(['mode' => ['required', 'in:now,after']])['mode'];
        $offer = $mode === 'now' ? $send->now($offer, $request->user()) : $send->plan($offer, $request->user());
        $toast = $mode === 'now' ? 'На аукционе' : 'Уйдёт на аукцион '.$offer->bids_close_at->translatedFormat('j M, H:i').', если не будет подтверждений';

        return redirect("/offers/{$offer->number}")->with('toast', $toast)->with(self::gone($request, $offer));
    }

    public function auctionCancel(Request $request, Offer $offer, SendToAuction $send)
    {
        $planned = (bool) $offer->auction_planned_at;
        $offer = $send->cancel($offer, $request->user());
        $was = $planned ? 'Отправка на аукцион отменена' : 'В черновиках: '.$offer->crmTab();

        return redirect("/offers/{$offer->number}")->with('toast', $was)->with(self::gone($request, $offer));
    }

    /**
     * «Вернуть в продажу» из сделки или гаража с новыми ценами (шторка `x-offer.resale-sheet`): закупочная, заявленная
     * (пусто — от закупочной) и цена продажи; сделка отменяется, строка гаража уходит (`ReturnToSale`).
     */
    public function resale(Request $request, Offer $offer, ReturnToSale $return)
    {
        $data = $request->validate(['floor_price' => ['nullable', 'string', 'max:20'], 'publish_price' => ['nullable', 'string', 'max:20'],
            'asking_price' => ['required', 'string', 'max:20'], 'bids_close_at' => ['nullable', 'date', 'after:now'], 'audience_rules' => ['nullable', 'json']],
            ['asking_price.required' => 'Укажите цену продажи', 'bids_close_at.after' => 'Срок приёма — позже, чем сейчас']);
        $money = fn (?string $v) => Money::rubles($v) ?: null;
        $prices = array_map($money, array_intersect_key($data, array_flip(['floor_price', 'publish_price', 'asking_price'])) + ['floor_price' => null, 'publish_price' => null]);
        $audience = $request->has('audience_rules') ? (json_decode((string) $request->input('audience_rules'), true) ?: []) : null;
        $offer = $return($offer, $request->user(), $prices, filled($data['bids_close_at'] ?? null) ? Carbon::parse($data['bids_close_at']) : null, $audience);

        return redirect("/offers/{$offer->number}")->with('toast', 'В продаже № '.$offer->number)->with(self::gone($request, $offer));
    }

    /**
     * Сменили состояние из карточки рядом со списком (в архив, опубликовали, вернули в черновики): строка ушла из
     * открытой вкладки — убрать её сразу и поправить числа пилюль (`detail-gone`, как после «Оценить»), а не ждать
     * обновления страницы (владелец 05.10.2026). Вкладку и галерею берём из адреса списка; в поиске строка остаётся.
     */
    public static function gone(Request $request, Offer $offer): array
    {
        $ref = parse_url((string) $request->header('Referer'));
        parse_str($ref['query'] ?? '', $q);
        if (! isset($q['peek']) || filled($q['q'] ?? null)) {
            return [];
        }
        $preset = array_key_exists((string) ($q['preset'] ?? ''), self::presets($request->user())) ? (string) $q['preset'] : self::home($request->user());
        $still = match ($ref['path'] ?? '/') {
            '/' => self::scopeFor($preset, Offer::whereKey($offer->id), $request->user()->canManageCrm())->exists(),
            '/gallery' => GalleryController::listed($q['preset'] ?? null, $offer->fresh()),
            default => true,
        };

        // На вкладках дела карточка идёт к следующей строке, на остальных строка просто уходит.
        $counts = ($ref['path'] ?? '/') === '/gallery' ? GalleryController::counts() : self::counts($request->user());

        return $still ? [] : ['detail-gone' => true, 'detail-counts' => $counts]
            + (($ref['path'] ?? '/') === '/' && in_array($preset, self::ADVANCE, true) ? ['detail-advance' => true] : []);
    }

    /** «Убрать из слота»: остаётся черновиком или галереей. */
    public function unschedule(Request $request, Offer $offer, UnscheduleOffer $unschedule)
    {
        $unschedule($offer, $request->user());

        return back(fallback: "/offers/{$offer->number}")->with('toast', 'Убрано из слота');
    }

    private function when(Request $request, string $default): string
    {
        return Slots::when($request->input('when'), $request->input('day'), $default);
    }

    /** Тост публикации: номер — уже публичный, у поставленного в слот — когда выйдет. */
    private function published(Offer $offer): string
    {
        return match (true) {
            $offer->isScheduled() => 'Выйдет '.Slots::phrase($offer->slot_at),
            $offer->isUrgent() => 'Срочно в продаже № '.$offer->number.', приём до '.$offer->bids_close_at->translatedFormat('j M, H:i'),
            default => 'В продаже № '.$offer->number,
        };
    }
}
