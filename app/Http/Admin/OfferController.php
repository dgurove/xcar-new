<?php

namespace App\Http\Admin;

use App\Cars\Car;
use App\Cars\Vin\Vin;
use App\Live\Rows;
use App\Mail\Candidate;
use App\Mail\Extraction\Code;
use App\Mail\Scan\AutoScan;
use App\Media\Actions\WarmPhotos;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\DropValuation;
use App\Offers\Actions\MergeOffers;
use App\Offers\Actions\NoBidsAlarm;
use App\Offers\Actions\PurgeOffer;
use App\Offers\Actions\ResolveProposal;
use App\Offers\Actions\ReturnToSale;
use App\Offers\Actions\ScheduleOffer;
use App\Offers\Actions\SellAtAuction;
use App\Offers\Actions\SendToAuction;
use App\Offers\Actions\ShowInGallery;
use App\Offers\Actions\UndoAuctionSale;
use App\Offers\Actions\UnlistParkOffer;
use App\Offers\Actions\UnscheduleOffer;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Binding;
use App\Offers\CarView;
use App\Offers\CarWindow;
use App\Offers\CatalogQuery;
use App\Offers\DealDates;
use App\Garage\CarState;
use App\Offers\DealTabs;
use App\Offers\OfferFilters;
use App\Offers\OfferTabs;
use App\Offers\TabOwner;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Exclusive;
use App\Offers\Export\OffersXlsx;
use App\Offers\Jobs\DropEmptyDraft;
use App\Offers\Jobs\ImportMigtorgLot;
use App\Offers\LegacyClaim;
use App\Offers\Margin;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\PriceGuard;
use App\Offers\Pricing;
use App\Offers\Reprice;
use App\Offers\SaleCity;
use App\Offers\Slots;
use App\Support\Ago;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Portion;
use App\Support\Sort;
use App\Support\StaffCache;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'all' => 'Все',
        // Закупка и галерея — тоже этапы (16.11.2026, владелец): из закупки машины попадают в черновики, в галерею черновики —
        // параллельно, оставаясь черновиками. Строки рисуют `PurchaseController::tab` и `GalleryController::tab`. Галерея —
        // перед черновиками (17.11.2026, владелец); раскладку «Все» порядок не трогает — галереи в `OfferTabs` нет.
        'purchases' => 'Закупки',
        // Каркаде — своим этапом в закупке, без ответственного (24.11.2026, владелец: «пока что убери из всех этапов предложений,
        // пусть просто в закупках будут отдельным этапом»): их черновики и предложения до сделки — только здесь (`carcadeApart`).
        self::CARCADE_TAB => 'Предложения из закупок',
        ...GalleryController::PRESETS,
        // Сводные вкладки разделов (17.11.2026, владелец: подзаголовки колеса «кликабельными — как „Все“, но по всем своим») —
        // первыми в своём разделе, вместо подзаголовка (`SUMMARIES`).
        'all_drafts' => 'Все черновики',
        'nofloor' => 'Без закупочной цены', 'unpriced' => 'Без продажной цены', 'estimating' => 'На оценке у менеджеров', 'priced' => 'Оцененные',
        'all_offers' => 'Все предложения',
        'slots' => 'Публикация', 'published' => 'Опубликованные', 'refused' => 'Менеджер отказался', 'bids' => 'Есть подтверждения',
        'all_deals' => 'Все сделки',
        ...self::DEAL_TABS,
        // Гараж — все гаражные машины до расчёта, параллельно их вкладкам сделки, как галерея (16.11.2026, начальник: «всего 5
        // машин»; владелец: своим разделом). Строки рисует `GarageController::tab`.
        'garage' => 'Гараж',
        // «Вывоз» (24.11.2026, владелец: «в третьем столбце над архивом подзаголовок „Вывоз“, назначить исполнителя и т. д.») —
        // поперёк этапов, как «Оплата за подбор»: строки рисует `PickupController::tab`.
        // «Показать контакты» (25.11.2026) — первой: менеджер забирает сам, а кому звонить, не знает (`ContactDue`).
        'pickup_contacts' => 'Показать контакты', 'pickup_assign' => 'Назначить исполнителя', 'pickup_docs' => 'Документы на вывоз', 'pickup_letters' => 'Сообщить поставщику', 'pickup_moving' => 'Везём',
        // «Менеджер забирает ТС» — вкладка сделки по шагу получения, но здесь, в «Вывозе» (27.11.2026, владелец; `DealTabs::PICKUP`).
        ...DealTabs::PICKUP,
        // «Все архивы», а не «Архивы» (18.11.2026, владелец: «„Архивы“ и „Архив“ рядом — странно»), как прочие сводные.
        'all_archive' => 'Все архивы',
        // «Без подтверждений» — к архивам (24.11.2026, владелец): решить, снова в продажу или в архив.
        'done' => 'Без подтверждений',
        'archive' => 'Архив', 'legacy' => 'Из таблицы Кати', 'auction' => 'На аукцион',
    ];

    /** Вкладка Каркаде в закупке (`PRESETS`). */
    public const CARCADE_TAB = 'from_purchases';

    /** До сделки и идущая сделка: откуда Каркаде убран в свою вкладку (24.11.2026). Гараж, выданные и архив — как у всех. */
    private const BEFORE_DEAL = [OfferState::Draft, OfferState::Gallery, OfferState::Open, OfferState::Sold];

    /** Прятать ли Каркаде из вкладок (`carcadeApart`); выключено — на время раскладки его подгрупп (`OfferTabs::carcade`). */
    private static bool $apart = true;

    /** Условия вкладок без выделения Каркаде — его подгруппы во вкладке «Предложения из закупок». */
    public static function withoutApart(callable $fn): mixed
    {
        self::$apart = false;
        try {
            return $fn();
        } finally {
            self::$apart = true;
        }
    }

    /**
     * Сводные вкладки разделов (17.11.2026): «Все» по вкладкам одного раздела — те же группы по вкладкам (`OfferTabs`), чипы
     * «Состояние» и «Менеджер», столбцы. Сводная → вкладки раздела; сделки — этапы `DealTabs` и «Завершённые».
     */
    public const SUMMARIES = [
        'all_drafts' => ['nofloor', 'unpriced', 'estimating', 'priced'],
        'all_offers' => ['slots', 'published', 'refused', 'bids'],
        'all_deals' => null,
        'all_archive' => ['done', 'archive', 'legacy', 'auction'],
    ];

    /**
     * Шторки действий окна машины («Вернуть в продажу», «Отдать», «Срочно», «Опубликовать для других»…) — отдельным ответом
     * по первому требованию (17.11.2026): в каждом окне их до десяти, это 120–140 КБ из 195–320 КБ ответа и два десятка
     * запросов, а открывают одну на много окон. Окно несёт пустое место `data-lazy-sheets`, `emit_controller` перед событием
     * «имя:open» дозагружает их сюда. Страница машины рисует шторки сразу, как и окно после ошибки формы.
     */
    public function windowSheets(Request $request, Offer $offer)
    {
        $deal = $request->query('deal') ? \App\Offers\Deal::find((int) $request->query('deal')) : null;

        return view('admin.car.sheets', CarView::for($offer, $request->user(), deal: $deal?->offer_id === $offer->id ? $deal : null)->vars());
    }

    /**
     * «Оплата сделки» страницей (22.11.2026, начальник: «где редактор оплаты?»): строка «Оплат» XCAR Pay открывает её шторкой
     * (`DealSheet::money`) — тот же кусок, что окно строки «Оплат» CRM (`admin/car/money-body`), «Открыть сделку» — `/offers/N`
     * там же. Сделки нет — страница машины.
     */
    public function money(Request $request, Offer $offer)
    {
        // Строка «Оплат» — сделка человека (`?deal=`): машину после отмены могли отдать другому.
        $deal = $request->query('deal') ? \App\Offers\Deal::find((int) $request->query('deal')) : null;
        $vars = CarView::for($offer, $request->user(), deal: $deal?->offer_id === $offer->id ? $deal : null)->vars();
        if (! ($vars['deal'] ?? $vars['garageCar']?->deal)) {
            return redirect("/offers/{$offer->number}");
        }

        return view('admin.car.money-page', $vars);
    }

    /** Вкладки, по которым раскладывается сводная («Все» или раздела); не сводная — null. @return list<string>|null */
    public static function summary(?string $preset): ?array
    {
        return match (true) {
            $preset === 'all' => array_values(array_diff(OfferTabs::keys(), self::ALL_HIDDEN, ['archive'])),
            $preset === 'all_deals' => [...array_keys(self::DEAL_TABS), ...array_keys(DealTabs::PICKUP)],
            array_key_exists((string) $preset, self::SUMMARIES) => self::SUMMARIES[$preset],
            default => null,
        };
    }

    /**
     * Сделка — состояние предложения (16.11.2026, начальник; владелец выбрал вкладки «по этапам маршрута», «Ваш ход» — разложить
     * по этапам): вкладки сделки здесь же, а не в «Работе → Сделки». Этапы по порядку маршрута — `DealTabs`, «Завершённые» —
     * выданные и гаражные с закрытым расчётом. Строки рисует `DealController::tab`. «Гараж» — свой раздел после сделки (`PRESETS`).
     */
    public const DEAL_TABS = [...DealTabs::TABS, 'finished' => 'Завершённые'];

    /**
     * Разделы колеса (16.11.2026, владелец выбрал вариант А: «между вариантами подзаголовки, которые не выбираются, а
     * пролистываются»): первая вкладка раздела → подпись (`x-ui.tab-title :sections`). «Все» — над разделами. В «Сделке» —
     * линия над «Завершёнными»; вкладки ожидания — в общем ряду по ходу сделки, без подзаголовка (18.11.2026, `DealTabs::TABS`).
     */
    public const SECTIONS = ['purchases' => 'Закупка', 'gallery' => 'Галерея', 'all_drafts' => 'Черновики', 'all_offers' => 'Предложения', 'all_deals' => 'Сделки', 'finished' => 'Завершённые', 'garage' => 'Гараж', 'pickup_contacts' => 'Вывоз', 'all_archive' => 'Архивы'];

    /**
     * Столбцы колеса на ПК (17.11.2026, владелец: «не скролл, а просто клик», «три столбца: предложения — черновики, закупка,
     * галерея, предложение; сделки — сделки и гараж; архивы»; заголовков у столбцов нет — имя только для чтения с экрана):
     * первая вкладка столбца → имя (`x-ui.tab-title :columns`). На телефоне колесо одно.
     */
    public const WHEEL_COLUMNS = ['all' => 'Предложения', 'all_deals' => 'Сделки', 'pickup_contacts' => 'Вывоз и архивы'];

    /** Вкладки, машин которых во «Все» нет (17.11.2026, владелец): работа модератора и законченное. */
    public const ALL_HIDDEN = ['nofloor', 'finished'];

    /** Вкладки, чьи строки рисует свой контроллер: сделки, гараж, галерея, закупки. */
    public static function ownTab(?string $preset): bool
    {
        return self::dealTab($preset) || in_array($preset, ['purchases', 'gallery', 'interest', 'garage'], true) || isset(PickupController::TABS[(string) $preset]);
    }

    /** Зелёные числа колеса: ключ вкладки → ключ числа в `counts` (оценки менеджеров, наши дела по этапам сделки). */
    public static function badges(): array
    {
        return ['refused' => 'refused', 'estimating' => 'estimating_given', 'priced' => 'priced_given', 'all_deals' => 'all_deals_our', 'paying_fee' => 'paying_fee_our',
            ...array_combine(PickupController::OURS, array_map(fn ($k) => $k.'_our', PickupController::OURS)), ...collect(DealTabs::ALL)->except(DealTabs::WAITING)->mapWithKeys(fn ($t, $k) => [$k => $k.'_our'])->all()];
    }

    /** Вкладка ли сделки: строки — сделки (`DealController::tab`). */
    public static function dealTab(?string $preset): bool
    {
        return isset(self::DEAL_TABS[(string) $preset]) || isset(DealTabs::PICKUP[(string) $preset]);
    }

    /**
     * Опубликованное: «Опубликованные» и «Есть подтверждения» — одни правила сортировки и чипов. Вкладки «Приём закрыт»
     * (17.10–29.10.2026) нет: опубликовано — значит идёт приём, кончился без подтверждений — черновиком в «Без подтверждений»
     * (`ReturnClosedToRated`).
     */
    private const OPEN = ['published', 'refused', 'bids'];

    /** Вкладки, где порядок — когда предложение в них попало (`offers.tab_at`) и столбец даты — оно же. */
    public const BY_TAB = ['nofloor', 'unpriced', 'estimating', 'priced', 'done', 'archive', 'auction'];

    /**
     * Вкладки человека: подтверждения, «На оценке у менеджеров», «Без подтверждений», «Все», «Из таблицы Кати» и «На аукцион» видит
     * только админ (срок приёма модератору не виден, оценки, сделки и гараж ему не открываются).
     */
    public static function presets(User $user): array
    {
        if ($user->canManageCrm()) {
            return self::PRESETS;
        }
        // Закупки и галерея — админу (их разделы в CRM у модератора закрыты и прежде).
        $hidden = ['estimating' => true, 'refused' => true, 'bids' => true, 'done' => true, 'all' => true, 'legacy' => true, 'auction' => true, ...array_fill_keys(array_keys(self::SUMMARIES), true),
            'purchases' => true, self::CARCADE_TAB => true, 'gallery' => true, 'interest' => true];

        // Модератору (`canEditDeals`) — сделки по этапам, «Завершённые» и гараж (17.11.2026, владелец: полный доступ к сделкам и гаражу).
        return array_diff_key(self::PRESETS, $hidden + ($user->canEditDeals() ? [] : ['garage' => true] + self::DEAL_TABS + DealTabs::PICKUP + PickupController::TABS));
    }

    /**
     * «Только мои» в колесе (18.11.2026; 24.11.2026, владелец: «модераторам всегда включён без возможности выключить, Андрею и
     * Кате по умолчанию включён, но можно выключить, при перезаходе опять включается; все этапы по умолчанию видит только главный
     * администратор»): включён ли, можно ли выключить (`locked` — модератору нельзя), свои вкладки (`TabOwner`) и ответственные
     * каждой (аватарки). Вкладки ожидания (`DealTabs::WAITING`) ответственного не имеют, и с «Только мои» их нет (владелец):
     * там нашего дела нет. null — не сотрудник CRM.
     *
     * @return array{on: bool, locked: bool, keys: list<string>, owners: array<string, \Illuminate\Support\Collection>, people: ?\Illuminate\Support\Collection, as: ?int}|null
     */
    public static function tabMine(?User $user): ?array
    {
        if (! $user?->isStaff()) {
            return null;
        }
        // Главный администратор смотрит «Только мои» глазами сотрудника (24.11.2026, владелец): `?mine_as=` — выбор рядом с тумблером.
        $people = $user->isOwner() ? TabOwner::staff()->reject(fn (User $u) => $u->isOwner())->values() : null;
        $as = $people?->firstWhere('id', (int) request()->query('mine_as'));
        $whose = $as ?? $user;
        $keys = TabOwner::presetsOf($whose);
        $locked = ! $user->canManageCrm();

        // Включён у всех, кроме главного администратора; выключить на этот заход — колесо (`tab_wheel_controller`, sessionStorage).
        return ['on' => $locked || $as !== null || ! $user->isOwner(), 'locked' => $locked, 'keys' => $keys, 'owners' => TabOwner::owners(),
            'people' => $people, 'as' => $as?->id];
    }

    /** Разделы колеса человека — только у разделов, где есть его вкладки. */
    public static function sections(User $user): array
    {
        return self::starts(self::SECTIONS, $user);
    }

    /** Столбцы колеса человека на ПК — только те, где есть его вкладки. */
    public static function wheelColumns(User $user): array
    {
        return self::starts(self::WHEEL_COLUMNS, $user);
    }

    /** Подписи частей колеса (разделов, столбцов) — у первой видимой человеку вкладки части. */
    private static function starts(array $parts, User $user): array
    {
        $presets = self::presets($user);
        $out = [];
        $part = null;
        foreach (array_keys(self::PRESETS) as $key) {
            $part = $parts[$key] ?? $part;
            if (isset($presets[$key]) && $part && ! in_array($part, $out, true)) {
                $out[$key] = $part;
            }
        }

        return $out;
    }

    /** Прежние пилюли — в новые вкладки: ссылки из Telegram, закладки, `LegacyAdmin`. */
    private const LEGACY = [
        'draft' => 'unpriced', 'purchase' => 'unpriced', 'closed' => 'done', 'slot' => 'slots', 'open' => 'published', 'recommended' => 'published',
        // «Оплата за подбор» — с 24.11.2026 часть «Менеджер должен нам», с 26.11.2026 — «Ждём оплату подбора».
        'selection' => 'paying_fee',
        // «Ждём договор», «Подписание договора» и «Ждём данные покупателя» — с 27.11.2026 подгруппы «Ждём менеджера».
        'signed' => 'manager', 'docs' => 'manager', 'buyer_data' => 'manager',
    ];

    /** Вкладки, где идёт работа по шагам: всегда таблица, без сортировки — порядок задаёт сам шаг. */
    private const STEPS = ['nofloor', 'unpriced', 'estimating', 'priced', 'slots', 'published', 'refused', 'bids'];

    /** Вкладки, где карточка после дела идёт к следующей строке. */
    private const TODO = ['nofloor', 'unpriced', 'estimating'];

    /** Вкладки дела: строка ушла — карточка сама к следующей (оценили, заполнили закупочную, приняли подтверждение). */
    private const ADVANCE = [...self::TODO, 'refused', 'bids', 'done'];

    /**
     * Вкладка по умолчанию — дело человека: модератору закупочная, админу цена продажи. Админ, который ещё и модератор
     * (Катя, 27.10.2026, владелец: «продажную цену она обычно не назначает»), — закупочная.
     */
    public static function home(User $user): string
    {
        return $user->canManageCrm() && ! $user->isModerator() ? 'unpriced' : 'nofloor';
    }

    /** Есть ли у человека дело по строке на его шаге: закупочная — у всех, цена продажи и подтверждения — у админа. */
    public static function todo(Offer $offer, User $user): bool
    {
        // Админу дело и у открытого с подтверждениями («Есть подтверждения»): принять — и к следующему; у черновика после
        // приёма без подтверждений — решить.
        return ($offer->state === OfferState::Draft && ! $offer->slot_at && ! $offer->asking_price && (! $offer->floor_price || $user->canManageCrm()))
            || ($offer->state === OfferState::Open && $user->canManageCrm() && (int) $offer->active_bids_count > 0)
            // «Без подтверждений» (06.11.2026): вернуть или в архив — и к следующей.
            || ($user->canManageCrm() && ReturnToSale::unsold($offer));
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
        'binding' => ['Срок обязывающего', 'asc'],
        'sold' => ['Продано', 'desc'],
        'paid' => ['Оплачено', 'desc'],
    ];

    /**
     * Сортировка по сроку обязывающего — на этот раз, не запоминается (19.10.2026: однажды нажали — и список навсегда стоял
     * по сроку): запомненная снимается, вкладка встаёт по своему умолчанию. Одна дверь у предложений и «Сделок».
     */
    public static function forgetBindingSort(Request $request, mixed $asked): void
    {
        $sort = (string) $request->query('sort');
        if (ltrim($sort, '-') === 'binding' && $asked !== $sort) {
            $request->query->remove('sort');
        }
    }

    /** Сортировка вкладки: поля её столбцов, номер всегда, закрытие приёма — у опубликованных. */
    private static function sortFor(Request $request, string $preset, ?array $cols, bool $admin): Sort
    {
        $options = array_filter(self::SORTS, fn ($_, $key) => match ($key) {
            'number' => true,
            // Срок обязывающего — без столбца (19.10.2026, владелец: «засрали интерфейс»): только в окошке сортировки.
            'binding' => $cols !== null,
            'closing' => in_array($preset, self::OPEN, true),
            'tab' => in_array($preset, self::BY_TAB, true) || self::summary($preset) !== null,
            // Во вкладках шагов время, порядок и аватар — про приход во вкладку (18.10.2026, начальник: сортировал по
            // заведению, а в столбце время прихода — «непонятно, что значит»). Запомненная «Дата заведения» тут — умолчание.
            'created' => ! in_array($preset, self::BY_TAB, true),
            default => $cols === null || in_array($key, $cols, true),
        }, ARRAY_FILTER_USE_BOTH);
        // Шаги работы — по тому, когда предложение сюда пришло, новые сверху (15.10.2026, владелец: оценил закупочную — и ищи
        // машину внизу «Без продажной»); опубликованные у админа — по закрытию приёма; остальное — по заведению.
        $default = match (true) {
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

            return redirect('/offers?'.http_build_query($query), 301);
        }
        // Вкладки сделки (16.11.2026: сделка — состояние предложения) — строки сделок с их группами (`DealController::tab`).
        // Закупки и галерея (16.11.2026) — тоже своими контроллерами.
        $own = (string) $request->query('preset');
        if (self::ownTab($own) && isset(self::presets($request->user())[$own])) {
            return match ($own) {
                'garage' => app(GarageController::class)->tab($request),
                'pickup_contacts', 'pickup_assign', 'pickup_docs', 'pickup_letters', 'pickup_moving' => app(PickupController::class)->tab($request, $own),
                'purchases' => app(PurchaseController::class)->tab($request),
                'gallery', 'interest' => app(GalleryController::class)->tab($request),
                default => app(DealController::class)->tab($request, $own),
            };
        }
        // ?peek=номер — карточка строки рядом (first — первая строка без цены, адрес получает её номер ниже).
        $detail = Detail::of($request, fn (string $key) => CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $presets = self::presets($request->user());
        // У каждой вкладки своя ссылка у всех; без вкладки в адресе — на вкладку человека по умолчанию (модератору
        // «Без закупочной цены», админу «Без продажной цены»). Поиск лупой вкладки не знает — его не уводим.
        if (! array_key_exists((string) $request->query('preset'), $presets) && blank($request->query('q'))) {
            return redirect($request->fullUrlWithQuery(['preset' => self::home($request->user())]));
        }
        ['q' => $q, 'facets' => $facets, 'preset' => $preset, 'admin' => $admin, 'step' => $step, 'sort' => $sort, 'searching' => $searching,
            'carcadeOn' => $carcadeOn] = $this->listQuery($request);
        // Числа вкладок — с тем же выбором, что и список (и «кроме Каркаде» по умолчанию — у каждой вкладки по её правилу).
        $counts = self::counts($request->user(), $facets, $carcadeOn);

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
        Rows::offers($q);
        // Таблица — порциями по сотне (`Portion`, 31.10.2026); целиком — где порция соврёт: галки «Выбрать все» и «Из таблицы
        // Кати» (призраки — под всеми черновиками). Числа групп — по всему списку, до порции: paginate сужает сам запрос.
        $whole = self::pickable($request->user(), $preset, $searching) || $preset === 'legacy';
        $groupCounts = ListView::isTable($view) && ! $whole && ! $searching ? self::groupCounts($q, $preset, $admin) : [];
        $offers = match (true) {
            ListView::isTable($view) && ! $whole => Portion::of($request, $q, $count),
            ListView::isTable($view) => $q->paginate(max($count, 1), total: $count)->withQueryString(),
            default => ListView::paginate($request, $q, $count, whole: $packed),
        };
        // Кадры нужны плиткам и строкам; в таблице их нет — кроме «Оцененных»: там по ним видно, готово ли к продаже.
        if (! ListView::isTable($view) || ($preset === 'priced' && ! $searching)) {
            $offers->loadMissing('media');
        }
        // Кто завёл — аватар у даты (столбец «Создано» на ПК у черновика, краткая таблица телефона — у всех строк, 09.10.2026).
        $offers->getCollection()->load(['moderator.media', 'tabBy.media', ...($preset === 'estimating' && ! $searching ? ['estimateBy.media'] : [])]);
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
            // Столбцы таблицы — одно описание на шапку, строки и группы.
            'columns' => self::table($preset, $request->user(), $searching, $offers->getCollection()),
            'groupCounts' => $groupCounts,
            'counts' => $counts,
            // Черновик завела почта и файлы с документами ещё догружаются — строка входит живьём (`AutoScan::states`,
            // `arrive_controller`), как заявка на парковке.
            'arrive' => AutoScan::states($offers->getCollection()->filter(fn (Offer $o) => $o->state === OfferState::Draft && ! $o->moderator_id)),
            'ghosts' => $preset === 'legacy' && ! $searching && self::ghostsShown($facets) ? LegacyClaim::ghosts()->get() : collect(),
            // Эксклюзив за оценку не решён — выбор в шторке пачки «Выставить в продажу» по машине (24.11.2026, владелец: В3 видом А1).
            // По всему списку вкладки, а не по порции: галку могут поставить и на странице плиток дальше.
            'exclusivePack' => $preset === 'priced' && self::pickable($request->user(), $preset, $searching)
                ? Exclusive::candidatesMany((clone $q)->reorder()->pluck('offers.id')) : collect(),
        ]);
    }

    /**
     * Список вкладки — одно место для экрана и окна «Выгрузка» (`export`): вкладка, чипы с памятью, «кроме Каркаде» по
     * умолчанию, поиск лупой мимо вкладки и чипов, порядок.
     *
     * @return array{q: Builder, facets: Facets, preset: string, admin: bool, step: bool, sort: Sort, searching: bool, carcadeOn: bool}
     */
    private function listQuery(Request $request): array
    {
        $admin = $request->user()->canManageCrm();
        $presets = self::presets($request->user());
        $preset = array_key_exists((string) $request->query('preset'), $presets) ? (string) $request->query('preset') : self::home($request->user());
        $step = in_array($preset, self::STEPS, true);
        // «Без цены» — без сортировки и вида, чипы есть (владелец 04.10.2026: «не хватает фильтров»), по умолчанию — без
        // Каркаде. «Рекомендуем» — переключатель у опубликованных.
        $tabFacets = [...match ($preset) {
            // «Новые» (опубликовано за сутки) и «Горящие» (приём закрывается за 12 ч) — как пилюли каталога, голубой и рыжей
            // точкой и своим цветом включённого (12.10.2026, владелец); подтверждения и сроки видит только админ.
            'published', 'refused', 'bids' => [
                ...self::facets(),
                ...($admin ? [
                    Facet::toggle('urgent', 'Срочные', fn ($o) => $o->urgent())->dot('urgent')->icon('zap'),
                    Facet::toggle('fresh', 'Новые', fn ($o) => $o->where('offers.state', OfferState::Open)->where('offers.published_at', '>=', now()->subHours(Offer::NEW_HOURS)))->dot('fresh')->icon('sparkles'),
                    Facet::toggle('ending', 'Горящие', fn ($o) => CatalogQuery::ending($o))->dot('hot')->icon('clock'),
                    // Ни одного подтверждения, приём кончается за 3 часа (22.10.2026, владелец: «ни одно предложение не должно
                    // остаться без подтверждений»).
                    Facet::toggle('nobids', 'Без подтверждений', fn ($o) => $o->where('offers.state', OfferState::Open)->where('offers.bids_close_at', '>', now())
                        ->where('offers.bids_close_at', '<=', now()->addHours(NoBidsAlarm::HOURS))
                        ->whereDoesntHave('bids', fn ($b) => $b->whereIn('state', [BidState::Active, BidState::Accepted])))->dot('hot')->icon('ban'),
                    Facet::toggle('loss', 'В минус', fn ($o) => Margin::scope($o))->dot('loss')->icon('trending-down'),
                ] : []),
                Facet::toggle('recommended', 'Рекомендуем', fn ($o) => $o->where('recommended', true))->icon('thumbs-up'),
            ],
            // Продажная у самой закупочной или ниже (`Offers\Margin`, 14.10.2026) — чип, пока такие есть.
            'priced', 'slots' => [
                ...self::facets(),
                ...($admin ? [Facet::toggle('loss', 'В минус', fn ($o) => Margin::scope($o))->dot('loss')->icon('trending-down')] : []),
            ],
            // «Все» (16.11.2026, владелец: «фильтр по этому состоянию, по менеджеру очень не хватает», «фильтры неудобные»): первыми —
            // вкладка (та же раскладка, что группы, `OfferTabs`) и менеджер, кто держит машину (сделка по текущим условиям, иначе
            // гараж); дальше вендор, город, тип. «Заведено» здесь нет — даты заведения во «Все» нет и столбцом.
            'all', 'all_drafts', 'all_offers', 'all_deals', 'all_archive' => [
                Facet::column('tab', 'Состояние', ['состояние', 'состояния', 'состояний'], OfferTabs::sql())->natural()->icon('layers')
                    ->labels(fn (array $keys) => collect([...OfferTabs::keys(), OfferTabs::OTHER])->filter(fn ($k) => in_array($k, $keys, true))
                        ->mapWithKeys(fn ($k) => [$k => new Option($k, OfferTabs::label($k))])->all()),
                Common::manager(self::HOLDER_SQL)->none('Без менеджера'),
                ...array_slice(self::facets(), 0, 3),
            ],
            default => self::facets(),
        }, ...($admin ? [
            // «Без продажной» и «На оценке у менеджеров» — вместо «Горит срок» менеджеры, кому отдали на оценку (24.11.2026,
            // начальник): чипом с галками в ряду шапки, несколько — машины любого из них.
            ...(in_array($preset, ['unpriced', 'estimating'], true) ? [self::estimatorFacet()] : [
                // Срок обязывающего горит, покупателя нет (19.10.2026, владелец: «прям ПАНИКА!!!») — красной точкой с числом; на
                // «Есть подтверждения» — подтверждение есть, а принять не успели (`undecided`).
                Facet::toggle('binding', 'Горит срок', fn ($o) => Binding::scope($o, $preset === 'bids' ? 'undecided' : 'panic'))->dot('danger')->icon('fire'),
            ]),
            // Переоценка ждёт решения (28.10.2026) — оранжевой точкой, пока такие есть.
            Facet::toggle('reprice', 'Переоценка', fn ($o) => $o->whereHas('reprices', fn ($r) => $r->where('state', Reprice::PENDING)))->dot('hot')->icon('refresh'),
        ] : [])];
        // Шапка вкладки (17.11.2026, владелец): под кнопками — только переключатели вкладки («Горит срок», «Новые»…); выпадающих
        // быстрых чипов нет («не нужны, будем выбирать в основном меню фильтра») — всё остальное и параметры машины, денег и
        // сроков (`OfferFilters`) — в окне «Фильтр».
        foreach ($tabFacets as $f) {
            if ($f->toggle) {
                $f->quick();
            }
        }
        $facets = Facets::for($request, 'crm-offers', ...$tabFacets, ...OfferFilters::for('offers.id', $admin, self::summary($preset) !== null ? OfferTabs::sinceSql() : null))
            ->always()->saving('offers');
        $askedSort = $request->query('sort');
        ListPrefs::sync($request, 'crm-offers', keep: array_values(array_diff($facets->keys(), self::ONCE)));
        self::forgetBindingSort($request, $askedSort);
        $cols = self::columns($preset, $request->user());
        $sort = self::sortFor($request, $preset, $cols, $admin);
        // Поиск лупой идёт по всему разделу — мимо вкладки и чипов.
        $searching = $facets->searching();
        // Вендор ни разу не выбирали — все, кроме Каркаде (их десятки из закупки, оценивают отдельно), на любой вкладке:
        // фильтр при переключении вкладок не меняется (владелец 04.10.2026). Сняли чип — ListPrefs помнит пустое, и
        // умолчание больше не встаёт. Умолчание — значением `Facets::implied`, а не в адресе: в ссылки не едет, «Фильтр N» его
        // не считает, окно фильтра видит его выбранным, как бы ни было нарисовано (фреймом или страницей).
        $given = $request->query('vendor');
        if (! $searching && ($given === null || (is_string($given) && str_starts_with($given, '!'))) && ($carcade = $this->carcadeDefault($request, $preset))) {
            $facets->implied('vendor', $carcade);
        }
        // Умолчание в силе — вендор не выбран (или выбран ровно «Кроме Каркаде»): числа колеса вычитают Каркаде у каждой
        // вкладки, что его прячет (`hidesCarcade`), даже если на этой вкладке Каркаде нет.
        $carcadeOn = ! $searching && self::carcadeOn($given);

        // Пустой «+ Новый» в списке не стоит: его либо заполнят, либо он удалится, как только из него уйдут.
        $q = Offer::query()->inCrm($request->user())->notEmptyDraft()->with(['settlement', 'saleSettlement', 'vendor', 'parkVehicle:id,car_id,state,accepted_at,created_at', 'deal', 'garageCar'])
            ->withCount(['activeBids', 'interests', 'activeBids as garage_bids_count' => fn ($b) => $b->where('kind', BidKind::Garage)])->withMax('activeBids as top_bid', 'amount');
        // Строка с галочкой спрашивает «нет фото» (`ChangeOfferState::missing`) — кадры одним запросом на список, а не на
        // строку (10.11.2026: «Без подтверждений» — 216 запросов кадров, секунда на сервере).
        if (self::pickable($request->user(), $preset, $searching)) {
            $q->with('media');
        }
        // «На оценке у менеджеров» — своё «во вкладке»: с первой открытой просьбы и кто попросил (`tab_at` просьба не трогает,
        // 22.11.2026: строка в «Без продажной цены» прыгала наверх).
        if ($preset === 'estimating' && ! $searching) {
            $q->addSelect([DB::raw(self::ESTIMATE_SINCE.' as estimate_since'), DB::raw(self::ESTIMATE_BY.' as estimate_by')]);
        }
        // Горит ли срок обязывающего — одним подзапросом на список, а не запросами на строку (`Binding::of`).
        Binding::select($q);
        // Горит ли срок обязывающего — для первых групп вкладки (`HOT`): 2 — сгорел, 1 — горит, 0 — нет.
        $q->selectRaw(Binding::levelSql().' as binding_soon');
        // «Все» — с какого момента машина в своей вкладке (17.11.2026, `OfferTabs::sinceSql`): «6д 5ч» справа в строке и столбцом.
        if (self::summary($preset) !== null && ! $searching) {
            $q->selectRaw(OfferTabs::sinceSql().' as tab_since');
        }
        // Ждущая переоценка закупочной (28.10.2026) — меткой у цены и чипом.
        if ($admin) {
            $q->withExists(['reprices as reprice_pending' => fn ($r) => $r->where('state', Reprice::PENDING)]);
        }
        // «Все» и поиск — где машина после продажи: этап сделки, менеджер, срок оплаты, гараж (`DealLine::forOffer`).
        if ($admin && (self::summary($preset) !== null || $searching)) {
            $q->with(['deal.buyer', 'deal.invoices.claims', 'lastDeal.buyer', 'garageCar.manager', 'positions.stage.block', 'positions.stage.exits.to',
                ...User::withAvatar('deal.buyer.media'), ...User::withAvatar('lastDeal.buyer.media'), ...User::withAvatar('garageCar.manager.media')]);
        }

        if ($searching) {
            $q->searchCrm(trim((string) $request->query('q')))->orderByDesc('updated_at');
        } else {
            self::scopeFor($preset, $q, $admin);
            self::order($q, $preset, $sort, $admin);
        }
        $facets->apply($q);

        return compact('q', 'facets', 'preset', 'admin', 'step', 'sort', 'searching', 'carcadeOn');
    }

    /** Окно «Выгрузка» над списком: то, что на экране (вкладка, чипы, лупа), — файлом или в буфер (21.10.2026). */
    public function export(Request $request)
    {
        ['q' => $q, 'preset' => $preset, 'searching' => $searching] = $this->listQuery($request);

        return OffersXlsx::respond($request, $request->user(), 'crm', $q,
            'Предложения '.($searching ? 'поиск' : mb_strtolower(self::PRESETS[$preset])));
    }

    /** Вендор Каркаде: на проде он «Carcade», локально «Каркаде» — как его ищет миграция закупок. */
    public static function carcadeId(): ?int
    {
        // Вендор не меняется — раз в пять минут на воркер, а не `ilike` по вендорам на каждый запрос списка. В консоли и
        // тестах (один процесс на много баз) — запросом.
        static $memo = null;
        if (app()->runningInConsole()) {
            return Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->value('id');
        }
        if ($memo === null || microtime(true) - $memo[1] > 300) {
            $memo = [Vendor::whereRaw("name ilike '%каркаде%' or name ilike '%carcade%'")->value('id'), microtime(true)];
        }

        return $memo[0];
    }

    /**
     * Прячет ли вкладка Каркаде по умолчанию — одно правило для списка (`carcadeDefault`) и числа колеса (`count`; 18.11.2026,
     * владелец: «Спросить менеджера» 105 при 2 строках — числа колеса считались по другому правилу). Не прячут: «Есть
     * подтверждения» — всё, что подтвердили менеджеры (11.10.2026, владелец: подтверждения Армена на Каркаде админ не видел);
     * вкладки сделки, «Гараж», галерея, закупки — у них свои контроллеры без умолчания; «Все сделки» — сумма вкладок сделки.
     */
    private static function hidesCarcade(string $preset): bool
    {
        return ! self::ownTab($preset) && ! in_array($preset, ['refused', 'bids', 'all_deals', self::CARCADE_TAB], true);
    }

    /** Умолчание «Кроме Каркаде» в силе: вендор не выбран или выбран ровно им (`?vendor=!id`). */
    private static function carcadeOn(mixed $vendor): bool
    {
        return $vendor === null || (is_string($vendor) && ($id = self::carcadeId()) && $vendor === '!'.$id);
    }

    /** Умолчание вкладки «все, кроме Каркаде» (`!id` — исключение, Facets); Каркаде на вкладке нет — null. */
    private function carcadeDefault(Request $request, string $preset): ?string
    {
        if (! self::hidesCarcade($preset)) {
            return null;
        }
        $carcade = self::carcadeId();
        if (! $carcade || ! self::scopeFor($preset, Offer::visibleTo($request->user())->notEmptyDraft(), $request->user()->canManageCrm())->where('vendor_id', $carcade)->exists()) {
            return null;
        }

        return '!'.$carcade;
    }

    /**
     * Условие вкладки — одно место для списка, чисел у вкладок и таб-бара. admin — видит ли человек подтверждения: без них
     * «Опубликованные» — всё открытое.
     */
    public static function scopeFor(string $preset, Builder $q, bool $admin = true): Builder
    {
        $bids = fn ($b) => $b->where('state', BidState::Active);

        if ($preset === self::CARCADE_TAB) {
            return self::carcadeApart($q, true);
        }
        self::carcadeApart($q, false);

        // Черновики из таблицы Кати — только в своей вкладке, рабочим не мешают (10.10.2026; 12.10.2026 начальник снова:
        // «не отображать»).
        if (in_array($preset, ['nofloor', 'unpriced', 'estimating', 'priced', 'slots', 'done'], true)) {
            LegacyClaim::offList($q);
        }
        // Отправленные на аукцион (13.10.2026) — только во вкладке «На аукцион», рабочим и таблице Кати не мешают.
        if (in_array($preset, ['nofloor', 'unpriced', 'estimating', 'priced', 'slots', 'done', 'legacy'], true)) {
            $q->whereNull('offers.auction_at');
        }
        // Отдали менеджерам на оценку (01.11.2026, начальник) — у админа своей вкладкой, из «Без закупочной» уходит; в «Без продажной
        // цены» остаётся (18.11.2026, начальник: «и в той, и в той вкладке одновременно»). Модератору — вкладки нет.
        if ($admin && $preset === 'nofloor') {
            $q->whereDoesntHave('estimates', fn ($e) => $e->open());
        }

        // Сделка (16.11.2026): этап — раскладка `DealTabs`. Ключи — из `DealTabs::TABS`, не списком: «Спросить менеджера» (`ask`)
        // в список не попал и считался условием «Архива» — 105 в колесе при 2 строках (18.11.2026).
        // «Менеджер должен нам» (24.11.2026) — и машины с открытым счётом подбора на любом этапе (бывшая «Оплата за подбор»).
        // С 26.11.2026 — двумя вкладками: «Ждём оплату ТС» (шаг оплаты, долг за машину) и «Ждём оплату подбора» (подбор на шаге оплаты,
        // в том числе внутри счёта за ТС, и открытый счёт подбора на любом этапе).
        if ($preset === 'paying') {
            return $q->whereIntegerInRaw('offers.id', DealTabs::paying('car', ! self::$apart));
        }
        if ($preset === 'paying_fee') {
            return $q->whereIntegerInRaw('offers.id', array_values(array_unique([...DealTabs::paying('fee', ! self::$apart), ...SelectionController::ids()])));
        }
        if (isset(DealTabs::ALL[$preset])) {
            return $q->whereIn('offers.id', DealTabs::ids($preset, ! self::$apart));
        }

        return match ($preset) {
            'legacy' => LegacyClaim::onList($q->where('offers.state', OfferState::Draft)),
            'auction' => $q->where('offers.state', OfferState::Draft)->whereNotNull('offers.auction_at'),
            // Без закупочной — и с ценой продажи (15.11.2026, владелец: оценочная с парковки, а машина на Мигторге, — «пусть
            // останется цена продажи, но пока нет оценочной и закупочной, они черновики без закупочной»; `ParkValue`).
            // И приём закрывался без подтверждений — сначала закупочная («Без подтверждений» — только с ней).
            // Заявка с парковки (`park_intake_at`) без цены продажи — админу не здесь, а в «Без продажной цены» (26.11.2026, владелец:
            // «в „Без закупочной“ их 500, их никто не заметит, это потерянные деньги»; `Offer::parkIntake`).
            'nofloor' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('floor_price')->orWhere('floor_price', 0))
                ->when($admin, fn ($w) => $w->where(fn ($x) => $x->whereNull('offers.park_intake_at')->orWhere('asking_price', '>', 0))),
            // Админу — и черновики на оценке у менеджеров с любой закупочной: они и здесь, и в «На оценке у менеджеров».
            'unpriced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))
                ->where(fn ($f) => $f->where('floor_price', '>', 0)->when($admin, fn ($w) => $w->orWhereHas('estimates', fn ($e) => $e->open())->orWhereNotNull('offers.park_intake_at'))),
            // Без цены продажи, а менеджер думает или уже оценил (просьба `asked` | `given`); корректировка цены — в «Оцененных».
            'estimating' => self::estimating($q),
            // Приём кончился без подтверждений — в «Без подтверждений» (29.10.2026, начальник), модератору — здесь же: той вкладки у него нет.
            'priced' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where('asking_price', '>', 0)->where('floor_price', '>', 0)
                ->when($admin, fn ($o) => $o->whereNull('offers.intake_closed_at')),
            'slots' => $q->scheduled(),
            // Идёт приём (срок не прошёл или его нет); у админа — ещё без подтверждений, они в «Есть подтверждения». Модератору
            // — всё открытое: срока приёма он не видит, «Приём закрыт» у него нет.
            'published' => $q->where('offers.state', OfferState::Open)
                ->when($admin, fn ($o) => $o->where(fn ($c) => $c->whereNull('offers.bids_close_at')->orWhere('offers.bids_close_at', '>', now()))
                    ->whereDoesntHave('bids', $bids)),
            // «Без подтверждений» (06.11.2026, владелец; до того «Завершённые» с группой «В работе» — сделки и гараж, им там не
            // место): приём кончился без подтверждений — решить, вернуть в продажу или в архив. Список держат пустым.
            'done' => $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->whereNotNull('offers.intake_closed_at')->where('floor_price', '>', 0),
            // «Менеджер отказался» (19.11.2026, начальник): отказался, а есть другие подтверждения — отдать другому или «Вернуть в
            // продажу…» с авто-снятием (`ChangeOfferState` `refused`). Вернули с авто-снятием — уже не здесь, а в «Есть подтверждения».
            'refused' => $q->where('offers.state', OfferState::Open)->whereNotNull('offers.refused_at')->whereNull('offers.lapse_since')->whereHas('bids', $bids),
            'bids' => $q->where('offers.state', OfferState::Open)->whereHas('bids', $bids)
                ->where(fn ($c) => $c->whereNull('offers.refused_at')->orWhereNotNull('offers.lapse_since')),
            // «Все» (21.10.2026, владелец: «все, в том числе завершённые и перешедшие в сделку, короче все кроме архивных»). Без
            // «Без закупочной цены» и «Завершённых» (17.11.2026, владелец: «во „Все“ не надо отображать без закупочной цены,
            // завершённые и всё, что в архиве») — по той же раскладке, что группы (`OfferTabs`).
            'all' => $q->whereNotIn('offers.state', [OfferState::Archived, OfferState::Cancelled])
                ->when(OfferTabs::ids(self::ALL_HIDDEN), fn ($w, $ids) => $w->whereIntegerNotInRaw('offers.id', $ids)),
            // Сводная раздела — машины, что раскладка кладёт в его вкладки (группы те же, числа сходятся с колесом).
            'all_drafts', 'all_offers', 'all_deals', 'all_archive' => $q->whereIntegerInRaw('offers.id', OfferTabs::ids(self::summary($preset))),
            // «Гараж» — все гаражные машины до расчёта, в любом этапе сделки (16.11.2026); расчёт закрыт — в «Завершённых».
            'garage' => $q->whereHas('garageCar', fn ($c) => $c->where('state', '!=', CarState::Settled)),
            'pickup_contacts', 'pickup_assign', 'pickup_docs', 'pickup_letters', 'pickup_moving' => $q->whereIntegerInRaw('offers.car_id', PickupController::carIds($preset)),
            // Галерея — окно показа черновика (`GalleryController::scopeFor`); закупки — не предложения: строк-предложений нет.
            'gallery', 'interest' => GalleryController::scopeFor($preset, $q),
            'purchases' => $q->whereRaw('false'),
            'finished' => $q->where(fn ($w) => $w->where('offers.state', OfferState::Delivered)
                ->orWhere(fn ($g) => $g->where('offers.state', OfferState::Garage)->whereHas('garageCar', fn ($c) => $c->where('state', CarState::Settled)))),
            default => $q->whereIn('offers.state', [OfferState::Archived, OfferState::Cancelled]),
        };
    }

    /**
     * Каркаде до сделки (черновик, галерея, продажа) и в идущей сделке — только во вкладке «Предложения из закупок» (24.11.2026, владелец). in —
     * условие этой вкладки, иначе — всех остальных: их машины до сделки не Каркаде. Вендора нет — вкладка пустая.
     */
    public static function carcadeApart(Builder $q, bool $in): Builder
    {
        $id = self::carcadeId();
        if (! $in && ! self::$apart) {
            return $q;
        }
        $states = array_map(fn (OfferState $s) => $s->value, self::BEFORE_DEAL);
        if ($in) {
            return $id ? $q->where('offers.vendor_id', $id)->whereIn('offers.state', $states) : $q->whereRaw('false');
        }

        return $id ? $q->where(fn ($w) => $w->whereNull('offers.vendor_id')->orWhere('offers.vendor_id', '!=', $id)->orWhereNotIn('offers.state', $states)) : $q;
    }

    /** «На оценке у менеджеров»: черновик без цены продажи (закупочная — любая) с открытой просьбой оценить. */
    /** «На оценке у»: менеджеры с открытой просьбой оценить по машинам списка (`Estimate::open`), числом машин. */
    private static function estimatorFacet(): Facet
    {
        return Facet::custom('estimator', 'На оценке у', ['менеджер', 'менеджера', 'менеджеров'],
            fn (Builder $q, array $ids) => $q->whereHas('estimates', fn ($e) => $e->open()->whereIn('offer_estimates.user_id', array_map('intval', $ids))),
            fn (Builder $q) => \App\Offers\Estimate::query()->open()
                ->whereIn('offer_estimates.offer_id', Facet::bare((clone $q)->toBase())->select('offers.id'))
                ->selectRaw('offer_estimates.user_id, count(distinct offer_estimates.offer_id) as n')->groupBy('offer_estimates.user_id')
                ->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
        )->labels(fn (array $ids) => \App\Users\User::whereIn('id', array_map('intval', $ids))->with(\App\Users\User::withAvatar())->get()
            ->mapWithKeys(fn ($u) => [(string) $u->id => new \App\Support\Facets\Option((string) $u->id, $u->shortName(), user: $u)])->all())
            ->icon('user')->dropdown();
    }

    public static function estimating(Builder $q): Builder
    {
        return $q->where('offers.state', OfferState::Draft)->whereNull('slot_at')->where(fn ($p) => $p->whereNull('asking_price')->orWhere('asking_price', 0))
            ->whereHas('estimates', fn ($e) => $e->open());
    }

    /**
     * Опубликованные — группами по тому, что делать: 0 «Выбрать» (приём закрыт, подтверждения есть), 1 «Идёт приём»
     * (скоро закроются — выше), 2 «Приём закрыт» без подтверждений. С 07.10.2026 подтверждения — своя вкладка: в «Есть
     * подтверждения» группы 0 и 1; «Опубликованные» — только 1, группа 2 — вкладка «Приём закрыт» (17.10.2026).
     */
    public const PICK_GROUPS = ['Выбрать', 'Идёт приём', 'Приём закрыт'];

    /**
     * Срок обязывающего сгорел и горит (`Binding::levelSql`; 12.11.2026, начальник: горит — меньше 5 дней, сгорел — день и
     * меньше, отдельной подгруппой) — первыми группами во вкладках, где такие строки бывают (06.11.2026, владелец:
     * «всегда вверх поднимать, чтобы их в первую очередь обработали»), над группами самой вкладки и при любой сортировке.
     * «Публикации» нет: там группы — время выхода. Ключи групп — `burnt` и `hot`, у остальных строк — прежний; у вкладки без своих
     * групп — `rest` («Остальные»). Строке — колонка `binding_soon` (`listQuery`).
     */
    // «Все» — группами по вкладкам (16.11.2026, владелец: «группировку не по тому, горит ли срок, а как по вкладкам»).
    public const HOT = ['nofloor', 'unpriced', 'estimating', 'priced', 'published', 'refused', 'bids', 'done'];

    public const HOT_GROUP = 'Горит срок обязывающего';

    public const BURNT_GROUP = 'Срок обязывающего сгорел';

    /**
     * «Без продажной цены» админа — машины на нашей парковке первыми своей группой (22.10.2026, начальник: у них приоритет),
     * остальные — «Не у нас». На парковке — ТС той же машины ждёт приёма или стоит (`Offer::onPark`).
     */
    public const PARK_GROUPS = ['На парковке', 'Не у нас'];

    private const PARK_GROUP = "case when exists (select 1 from park_vehicles v where v.car_id = offers.car_id and v.state in ('expected', 'stored')) then 0 else 1 end";

    /**
     * «На оценке у менеджеров» — группами (02.11.2026, владелец): «Оценённые» — хотя бы один менеджер оценил, решение за
     * админом; «Не оценённые» — ещё ждём.
     */
    public const ESTIMATE_GROUPS = ['Оценённые', 'Не оценённые'];

    public const ESTIMATE_GROUP = "case when exists (select 1 from offer_estimates e where e.offer_id = offers.id and e.state = 'given') then 0 else 1 end";

    /**
     * Когда черновик ушёл на оценку и кто попросил — первая из открытых просьб (22.11.2026, владелец: в «Без продажной цены»
     * строка после «отдать на оценку» прыгала наверх — `tab_at` просьба больше не ставит, у «На оценке» время своё).
     */
    public const ESTIMATE_SINCE = "(select min(e.created_at) from offer_estimates e where e.offer_id = offers.id and e.state in ('asked', 'given'))";

    public const ESTIMATE_BY = "(select e.asked_by from offer_estimates e where e.offer_id = offers.id and e.state in ('asked', 'given') order by e.created_at, e.id limit 1)";

    public const PICK_GROUP = "case when bids_close_at is not null and bids_close_at <= now() and exists (select 1 from bids where bids.offer_id = offers.id and bids.state = 'active') then 0 when bids_close_at is null or bids_close_at > now() then 1 else 2 end";

    /**
     * Порядок вкладки: группы первыми («Публикация» — по слоту, «Опубликованные» у админа — `PICK_GROUP`), внутри —
     * выбранное поле, последним — заведение. Порядок прохода черновиков не меняется от правки поля: по умолчанию по
     * заведению, новые сверху.
     */
    private static function order(Builder $q, string $preset, Sort $sort, bool $admin): void
    {
        $dir = $sort->dir();
        $pick = in_array($preset, self::OPEN, true) && $admin;
        if ($preset === 'unpriced' && $admin) {
            $q->orderByRaw(self::PARK_GROUP);
        }
        if (in_array($preset, self::HOT, true)) {
            $q->orderByRaw(Binding::levelSql().' desc');
        }
        if (self::summary($preset) !== null) {
            $q->orderByRaw(OfferTabs::orderSql());
        }
        if ($preset === self::CARCADE_TAB) {
            $q->orderByRaw(OfferTabs::orderSql(carcade: true));
        }
        if ($preset === 'estimating') {
            $q->orderByRaw(self::ESTIMATE_GROUP);
        }
        match (true) {
            $preset === 'slots' => $q->orderBy('slot_at'),
            $pick => $q->orderByRaw(self::PICK_GROUP),
            // Менеджер оценил — решение за админом: такие строки первыми (11.10.2026), дальше выбранный порядок.
            $preset === 'priced' && $sort->isDefault() => $q->orderByRaw("exists (select 1 from offer_estimates e where e.offer_id = offers.id and e.state = 'given' and (e.base_price is null or e.amount <> e.base_price)) desc"),
            default => null,
        };
        // Закрытие приёма у групп опубликованного — как было: в «Выбрать» и «Идёт приём» ближние выше, в «Без
        // подтверждений» — недавно закрытые.
        if ($pick && $sort->value() === 'closing') {
            $q->orderByRaw('case when ('.self::PICK_GROUP.') = 2 then null else bids_close_at end asc nulls last')->orderByDesc('bids_close_at');
        }
        $bids = "(select count(*) from bids where bids.offer_id = offers.id and bids.state = '".BidState::Active->value."')";
        match ($sort->key) {
            'tab' => $q->orderByRaw(match (true) {
                self::summary($preset) !== null => OfferTabs::sinceSql(),
                $preset === 'estimating' => 'coalesce('.self::ESTIMATE_SINCE.', offers.tab_at)',
                default => 'offers.tab_at',
            }." {$dir} nulls last"),
            'published' => $q->orderByRaw("published_at {$dir} nulls last"),
            'closing' => $q->orderByRaw("bids_close_at {$dir} nulls last"),
            'number' => $q->orderBy('offers.number', $dir),
            'vendor' => $q->orderByRaw("(select name from vendors where vendors.id = offers.vendor_id) {$dir} nulls last"),
            'floor' => $q->orderByRaw("offers.floor_price {$dir} nulls last"),
            'price' => $q->orderByRaw("offers.asking_price {$dir} nulls last"),
            'bids' => $q->orderByRaw("{$bids} {$dir}"),
            'binding' => $q->orderByRaw("offers.binding_until {$dir} nulls last"),
            'sold' => $q->orderByRaw(DealDates::sortSql(DealDates::SOLD)." {$dir} nulls last"),
            'paid' => $q->orderByRaw(DealDates::sortSql(DealDates::PAID)." {$dir} nulls last"),
            default => $q->orderBy('offers.id', $dir),
        };
        $q->orderByDesc('offers.id');
    }

    /**
     * Галочки первым столбцом — админу в «Оцененных», «Публикации», «Архиве» и «Без подтверждений», не в поиске. Одна дверь для списка и свежей
     * строки из карточки (`admin/offers/detail` → `row`): там своё правило без «Архива» давало строку без галочки, и
     * открытая строка съезжала относительно соседей (11.10.2026).
     */
    public static function pickable(User $user, ?string $preset, bool $searching): bool
    {
        // «Без продажной цены» — отдать на оценку пачкой (22.11.2026, начальник: «как в Оцененных»).
        return $user->canManageCrm() && ! $searching && in_array($preset, ['priced', 'slots', 'archive', 'done', 'unpriced'], true);
    }

    /**
     * Числа групп по всему списку, а не по порции (`Portion`): у заголовка группы на первой сотне — все её строки. Ключи —
     * как у групп шаблона (`admin/offers/index`): `done0`, `park1`, у опубликованных — номер группы.
     *
     * @return array<string|int, int>
     */
    private static function groupCounts(Builder $q, string $preset, bool $admin): array
    {
        [$expr, $prefix] = match (true) {
            $preset === 'unpriced' && $admin => [self::PARK_GROUP, 'park'],
            $preset === 'estimating' => [self::ESTIMATE_GROUP, 'est'],
            $preset === 'bids' && $admin => [self::PICK_GROUP, ''],
            self::summary($preset) !== null => [OfferTabs::sql(), 'tab:'],
            $preset === self::CARCADE_TAB => [OfferTabs::sql(carcade: true), 'tab:'],
            default => [null, null],
        };
        // Горящий обязывающий — своей группой поверх групп вкладки (`HOT`); без своих групп остальные — `rest`.
        if (in_array($preset, self::HOT, true)) {
            $level = Binding::levelSql();
            $expr = "case when {$level} = 2 then 'burnt' when {$level} = 1 then 'hot' else ".($expr === null ? "'rest'" : "'{$prefix}' || ({$expr})::text").' end';
            $prefix = '';
        }
        if ($expr === null) {
            return [];
        }

        // Без порядка и столбцов списка (`select([])` снимает и привязки подзапросов `withCount`).
        return (clone $q)->toBase()->reorder()->select([])->selectRaw("({$expr}) as g, count(*) as n")->groupBy('g')->pluck('n', 'g')
            ->mapWithKeys(fn ($n, $g) => [$prefix.$g => (int) $n])->all();
    }

    /** Группа «На оценке у менеджеров» (ESTIMATE_GROUPS) — то же правило, что у порядка; оценённые — одним запросом на запрос. */
    public static function estimateGroup(Offer $offer): int
    {
        return isset(once(fn () => Estimate::where('state', EstimateState::Given)->distinct()->pluck('offer_id')->flip()->all())[$offer->id]) ? 0 : 1;
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
     * Таблица предложений — столбцы вкладки одним описанием (`Support\Table`): шапка, строки (`x-offer.table-row`, призраки
     * «Из таблицы Кати» `x-offer.legacy-row`) и заголовки групп читают его, а не пересчитывают сами. В поиске — все столбцы
     * (список смешанный: `context('mixed')`). Ширина номера убытка и города — по самому длинному в выборке.
     */
    public static function table(?string $preset, User $user, bool $searching, ?Collection $rows = null): Table
    {
        $cols = self::columns($preset, $user, $searching);
        $all = $cols === null;
        $has = fn (string $k) => $all || in_array($k, $cols, true);
        $rows ??= collect();
        $refCh = (int) $rows->max(fn ($o) => mb_strlen((string) $o->claim_ref));
        $cityCh = (int) $rows->max(fn ($o) => mb_strlen((string) $o->settlement?->name.($o->sellsElsewhere() ? ' → '.$o->saleSettlement?->name : '')));
        // Дата справа: в «Опубликованных» — когда вышло, во вкладках шага — когда пришло во вкладку, иначе — когда заведено.
        $when = match (true) {
            ! $all && $has('published') => ['published', 'Вышло', 6.5],
            ! $all && $has('tab') => ['tab', 'Во вкладке', 7.5],
            $has('created') => ['created', 'Создано', 7.5],
            default => null,
        };
        // Без цены продажи («Без закупочной цены») закупочная встаёт на её место: видна всегда, с карточкой рядом тоже.
        $floorSide = ! $all && ! $has('price');

        return new Table([
            self::pickable($user, $preset, $searching) ? new Column('pick', pick: true, rem: 2.5) : null,
            new Column('title', 'Марка, модель', grow: true),
            $has('vendor') ? new Column('vendor', '№ убытка', from: 'sm', sort: 'vendor', role: ColRole::Code, rem: max(8.5, $refCh * .55 + 3)) : null,
            // Номера предложения столбцом нет (14.11.2026, владелец: «бесполезный, лучше номер убытка с логотипом страховой» —
            // он в «№ убытка» с логотипом вендора); сортировка «Номер» осталась в окошке, номер — в окне и поиске.
            // Во «Все» город — с 1024: место отдано этапу сделки и менеджеру в «Состоянии» (27.10.2026; с 29.10.2026 таблица
            // плотнее, и с 1536 — слишком поздно).
            $has('city') ? new Column('city', 'Город', from: self::summary($preset) !== null ? 'lg' : 'sm', aside: true, clip: true, role: ColRole::Place, rem: max(5, $cityCh * .5 + 2.5)) : null,
            // Во «Все» состояние — главное (14.11.2026, владелец: с окном справа оно уходило по ширине — «ничего не понятно»).
            // «Менеджер» — третьим, после номера убытка, по левому краю, аватар слева (14.11.2026, владелец).
            // Ширина постоянная (`fixed`): по первой сотне строк её не посчитать — наверху «Все» сгоревшие сроки без сделок, и
            // столбец сжимался до заголовка, имена ниже резались до «Ива…» (14.11.2026, владелец).
            ! $all && $has('manager') ? new Column('manager', 'Менеджер', from: 'sm', cell: 'whitespace-nowrap', role: ColRole::State, rem: 12.5, main: true, fixed: true) : null,
            $has('state') ? new Column('state', ! $all && $has('bids') ? 'Приём' : 'Состояние', from: 'sm', sort: 'closing', cell: 'whitespace-normal', role: ColRole::State, rem: 10, main: self::summary($preset) !== null) : null,
            // «Во вкладке» — длительностью «6д 5ч», рядом с «Состоянием» (17.11.2026, «Все»): про него и говорит.
            ! $all && $has('since') ? new Column('since', 'Во вкладке', from: 'sm', sort: 'tab', cell: 'nums whitespace-nowrap', role: ColRole::State, rem: 6.5, main: true, fixed: true) : null,
            // Менеджер сделки или гаража — во «Все», и при открытом окне (14.11.2026, владелец).
            // Подтверждения принимает только админ — модератору столбца нет.
            $has('bids') && $user->canManageCrm() ? new Column('bids', 'Подтверждения', from: 'sm', num: true, sort: 'bids', cell: 'nums', rem: 7) : null,
            ! $all && $has('value') ? new Column('value', 'Оценочная', from: 'sm', num: true, aside: true, cell: 'nums', rem: 5.5) : null,
            ! $has('floor') ? null : ($floorSide
                ? new Column('floor', 'Закупочная', num: true, sort: 'floor', cell: 'nums', rem: 5.5)
                : new Column('floor', 'Закупочная', from: 'sm', num: true, aside: true, sort: 'floor', cell: 'nums', rem: 5.5)),
            $has('price') ? new Column('price', 'Цена', num: true, sort: 'price', cell: 'nums', rem: 6) : null,
            // «Продано» / «Оплачено» (`DealDates`) — нажатием правятся (`SetDealDate`), с окном справа остаются. Ширины срока и
            // дат постоянные (`fixed`): первая сотня строк «Все» — черновики без сделок, по ней их не посчитать.
            ! $all && $has('binding') ? new Column('binding', 'Срок', from: 'sm', sort: 'binding', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 6, main: true, fixed: true) : null,
            ! $all && $has('sold') ? new Column('sold', 'Продано', from: 'sm', sort: 'sold', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 6.5, main: true, fixed: true) : null,
            ! $all && $has('paid') ? new Column('paid', 'Оплачено', from: 'sm', sort: 'paid', cell: 'nums whitespace-nowrap', role: ColRole::When, rem: 6.5, main: true, fixed: true) : null,
            // Дата — и при открытом окне (13.11.2026, начальник: «Во вкладке» пропадало); не влезает — уходит последней (`Table::DROP`).
            $when ? new Column('when', $when[1], from: 'sm', sort: $when[0], cell: 'nums whitespace-nowrap', role: ColRole::When, rem: $when[2]) : null,
        ], ['mixed' => $all, 'when' => $when[0] ?? null, 'floorSide' => $floorSide,
            // «Состояние» словом вкладки — во «Все» и в поиске (16.11.2026, `OfferTabs`).
            'tabs' => $all || self::summary($preset) !== null]);
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
            'unpriced', 'estimating', 'priced', 'legacy', 'auction' => ['vendor', 'city', 'floor', 'price', 'created'],
            // «Предложения из закупок» (24.11.2026): этап — подгруппой.
            self::CARCADE_TAB => ['vendor', 'city', 'floor', 'price', 'created'],
            // «Без подтверждений» — только черновики после приёма: состояние у всех одно, дата — когда кончился приём.
            'done' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Когда выйдет — заголовок группы, слова в строке не нужно.
            'slots' => ['vendor', 'city', 'floor', 'price', 'created'],
            // Модератору подтверждения не видны, а «в продаже» здесь и так у всех.
            // В «Опубликованных» у админа подтверждений нет по определению — столбца тоже.
            'published' => ['vendor', 'city', ...($admin ? ['state'] : []), 'floor', 'price', 'published'],
            'bids' => ['vendor', 'city', 'state', 'bids', 'floor', 'price', 'published'],
            'refused' => ['vendor', 'city', 'state', 'bids', 'floor', 'price', 'published'],
            // «Все»: ещё когда нам заплатили и когда мы поставщику (14.11.2026, владелец: «ваще непонятно, что с ними», `DealDates`).
            // Срок обязывающего — своим столбцом, а не значком у названия (14.11.2026, владелец: «у названия — неудобно и непонятно»).
            // Даты заведения столбцом нет (14.11.2026, владелец): сортировать по ней можно (`sortFor`), видеть — не нужно.
            // Города и закупочной нет: столбцы больше не уходят по ширине (таблица листается вбок), и они выталкивали срок и
            // даты за край — город есть в окне, закупочная в «Ценах».
            // Сколько во вкладке — за «Состоянием» (17.11.2026, владелец: «сколько дней и часов она уже в этой вкладке»).
            'all', 'all_deals' => ['vendor', 'state', 'since', 'manager', 'price', 'binding', 'sold', 'paid'],
            // Сводные черновиков и предложений — без сделки: менеджера и дат продажи и оплаты у них нет, закупочная — есть.
            'all_drafts', 'all_offers' => ['vendor', 'state', 'since', 'floor', 'price', 'binding'],
            'all_archive' => ['vendor', 'state', 'since', 'floor', 'price'],
            // «Архив»: где предложение сейчас — словом вкладки (`Offer::crmTab`: «Сделки», «Гараж»).
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
    /**
     * carcadeOn — умолчание «Кроме Каркаде» в силе (`carcadeOn`): Каркаде вычитается у вкладок, что его прячут (`hidesCarcade`).
     * null — по памяти чипов «Предложений» (страницы вкладок со своим контроллером и ответы на POST чипов не несут).
     */
    public static function counts(User $user, ?Facets $facets = null, ?bool $carcadeOn = null): array
    {
        $carcadeDefault = $carcadeOn ?? self::carcadeOn(($user->list_prefs ?? [])['crm-offers']['vendor'] ?? null);
        // После своего действия (POST: «Оценить», «Взять») — всегда заново: число пилюли должно вспыхнуть сразу.
        if (! request()->isMethodSafe()) {
            return self::count($user, $facets, $carcadeDefault);
        }
        // Числа всех вкладок — три десятка COUNT по предложениям; между правками они не меняются, а колесо скрыто до
        // нажатия. Одно значение на всех, кто смотрит с тем же выбором фильтров, до ближайшего события про предложения
        // (`StaffCache::TABS`) или полминуты; пересчёт один на всех.
        $who = $user->canManageCrm() ? 'crm' : 'u'.$user->id;
        $key = 'offers.counts:'.md5(json_encode([$who, array_keys(self::presets($user)), $facets?->state(), $facets?->searching(), $carcadeDefault]));

        return StaffCache::remember($key, 30, fn () => self::count($user, $facets, $carcadeDefault));
    }

    /** @return array<string, int> */
    private static function count(User $user, ?Facets $facets, bool $carcadeDefault): array
    {
        $base = fn () => Offer::inCrm($user)->notEmptyDraft();
        // Все числа — одним запросом (17.11.2026): три десятка COUNT уходили в базу по одному, на каждый — свой круг.
        // Условия те же, что у списков вкладок (`scopeFor` и чипы), — подзапросами одного SELECT.
        $subs = [];
        $count = fn ($q) => $q->toBase()->cloneWithout(['orders', 'columns', 'limit', 'offset'])->cloneWithoutBindings(['order', 'select'])->selectRaw('count(*)');
        // Горящий срок обязывающего — один раз на все вкладки (`Binding::panicSql` тяжёлый: подзапросами по подтверждениям и сделкам).
        $hot = Offer::query()->whereRaw(\App\Offers\Responsibility::hotSql())->pluck('offers.id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff(array_keys(self::presets($user)), ['archive', 'all_archive']) as $key) {
            // «Закупки» — открытые закупки, как прежнее число таб-бара.
            if ($key === 'purchases') {
                $subs[$key] = Purchase::where('state', PurchaseState::Open)->toBase()->selectRaw('count(*)');

                continue;
            }
            $q = self::scopeFor($key, $base(), $user->canManageCrm());
            if ($facets) {
                // «Вкладка» и «Менеджер» — чипы только «Всех»: другие вкладки сами по вкладке, иначе их числа обнулялись бы.
                // Вендор умолчанием — не чип: Каркаде вычитается ниже по правилу вкладки.
                $facets->applyTo($q, [...(in_array($key, self::OPEN, true) ? [] : ['recommended']), ...(self::summary($key) !== null ? [] : ['tab', 'manager']),
                    ...($carcadeDefault ? ['vendor'] : [])]);
            }
            // «Кроме Каркаде» — как у списка вкладки (`hidesCarcade`): число = строки, что покажет вкладка.
            if ($carcadeDefault && ! $facets?->searching() && self::hidesCarcade($key) && ($carcade = self::carcadeId())) {
                $q->where(fn ($v) => $v->whereNull('offers.vendor_id')->orWhere('offers.vendor_id', '!=', $carcade));
            }
            $subs[$key] = $count($q);
            // У «Из таблицы Кати» в числе и незаведённые номера — призрачные строки. Черновик из письма встаёт туда из
            // призрака, и число не меняется, — пилюле отдельно число черновиков: выросло — она вспыхивает (flip.js).
            if ($key === 'legacy' && self::ghostsShown($facets)) {
                $subs['legacy_ghosts'] = LegacyClaim::ghosts()->toBase()->cloneWithout(['orders', 'columns'])->cloneWithoutBindings(['order', 'select'])->selectRaw('count(*)');
            }
            // Лаймовый бейдж у «На оценке у менеджеров» и «Оцененных» — сколько ответов менеджеров ждут решения: оценки и свои
            // цены вместо цены админа («Согласен» решения не ждёт), 11.10.2026.
            if (in_array($key, ['estimating', 'priced'], true) && $user->canManageCrm()) {
                $subs[$key.'_given'] = $count((clone $q)->whereHas('estimates', fn ($e) => $e->needsDecision()));
            }
        }
        $select = DB::query();
        foreach ($subs as $alias => $sub) {
            $select->selectSub($sub, $alias);
        }
        $row = $subs ? (array) $select->first() : [];
        $counts = array_map('intval', $row);
        if (array_key_exists('legacy', $counts)) {
            $counts['legacy_drafts'] = $counts['legacy'];
            $counts['legacy'] += $counts['legacy_ghosts'] ?? 0;
        }
        unset($counts['legacy_ghosts']);

        // Сколько ждёт нас по вкладкам сделки — зелёным у названия (16.11.2026).
        foreach (DealTabs::ours() as $tab => $n) {
            $counts[$tab.'_our'] = $n;
        }
        // «Менеджер должен нам» (24.11.2026, вместе с «Оплатой за подбор») — красным, сколько ссылок подбора не выставлено на шаге
        // оплаты (тем же кругом машин, что число вкладки); остальное в ней — ожидание.
        if (isset($counts['paying_fee'])) {
            $counts['paying_fee_our'] = self::carcadeApart($base(), false)->whereIntegerInRaw('offers.id', SelectionController::unsentIds())->count();
        }
        // «Вывоз» — там, где ход наш, красным всё (`PickupController::OURS`).
        foreach (PickupController::OURS as $tab) {
            if (isset($counts[$tab])) {
                $counts[$tab.'_our'] = $counts[$tab];
            }
        }
        // Письма поставщику о вывозе — красным только дата (27.11.2026): акт вывоза ждёт тихо.
        if (isset($counts['pickup_letters'])) {
            $counts['pickup_letters_our'] = min($counts['pickup_letters'], count(\App\Logistics\PickupLetters::dueTransportIds('date')));
        }
        // Горящее вкладок предложений — отдельным лёгким запросом по горящим машинам (их единицы), а не вторым проходом условий
        // каждой вкладки в общем SELECT: 26.11.2026 так запрос чисел модератора шёл по 25 с и уронил прод.
        foreach ($hot ? self::hotOf($hot, $user) : [] as $alias => $n) {
            if (isset($counts[$alias])) {
                $counts[$alias.'_hot'] = $n;
            }
        }
        // Горящее вкладок сделки и вывоза (`Responsibility`, 26.11.2026): строки вкладки ∩ горящий обязывающий, у сделки — и
        // просроченный шаг, у вывоза — просроченный вывоз.
        foreach (self::urgentOf($hot) as $alias => $n) {
            if (isset($counts[strtok($alias, ':')])) {
                $counts[str_replace(':', '_', $alias)] = $n;
            }
        }
        // У «Все сделки» — все наши дела по этапам.
        if (isset($counts['all_deals'])) {
            $counts['all_deals_our'] = array_sum(DealTabs::ours());
        }

        return $counts;
    }

    /**
     * Горящее вкладок сделки и вывоза по их спискам машин: `{вкладка}:hot` и `{вкладка}:urgent`. Без чипов — красное «только
     * горящего» ответственного и так про всю вкладку.
     *
     * @param  list<int>  $hot  машины с горящим обязывающим
     * @return array<string, int>
     */
    /** Горящие машины по вкладкам предложений (не сделки и не вывоз): только среди горящих, без чипов. @param list<int> $hot @return array<string, int> */
    private static function hotOf(array $hot, User $user): array
    {
        $out = [];
        foreach (array_keys(self::presets($user)) as $key) {
            if (array_key_exists($key, self::SUMMARIES) || in_array($key, ['all', 'purchases', 'archive'], true) || isset(DealTabs::ALL[$key]) || isset(PickupController::TABS[$key])) {
                continue;
            }
            $out[$key] = self::scopeFor($key, Offer::query()->whereIntegerInRaw('offers.id', $hot), $user->canManageCrm())->count();
        }

        return $out;
    }

    private static function urgentOf(array $hot): array
    {
        $hotSet = array_flip($hot);
        $late = array_flip(DealTabs::overdueIds());
        $out = [];
        foreach (array_keys(DealTabs::ALL) as $tab) {
            $ids = match ($tab) {
                'paying' => DealTabs::paying('car'),
                'paying_fee' => array_values(array_unique([...DealTabs::paying('fee'), ...SelectionController::ids()])),
                default => DealTabs::ids($tab),
            };
            // Оплата поставщику — не горящее (27.11.2026, владелец): Катя заходит сама, когда есть деньги.
            $ids = $tab === 'payout' ? [] : $ids;
            $out[$tab.':hot'] = count(array_filter($ids, fn ($id) => isset($hotSet[$id])));
            $out[$tab.':urgent'] = count(array_filter($ids, fn ($id) => isset($hotSet[$id]) || isset($late[$id])));
        }
        $cars = [];
        foreach (array_keys(PickupController::TABS) as $tab) {
            $cars[$tab] = PickupController::carIds($tab);
        }
        // Машина вывоза → её живые предложения (горящий обязывающий — у предложения).
        $offers = Offer::whereIntegerInRaw('car_id', array_values(array_unique(array_merge(...array_values($cars) ?: [[]]))))
            ->whereNotIn('state', [OfferState::Archived, OfferState::Cancelled])->get(['id', 'car_id'])->groupBy('car_id')->map(fn ($g) => $g->pluck('id')->all());
        foreach ($cars as $tab => $ids) {
            $lateCars = array_flip(PickupController::lateCarIds($tab));
            $burning = fn ($car) => array_any($offers[$car] ?? [], fn ($id) => isset($hotSet[$id]));
            $out[$tab.':hot'] = count(array_filter($ids, $burning));
            $out[$tab.':urgent'] = count(array_filter($ids, fn ($car) => isset($lateCars[$car]) || $burning($car)));
        }

        return $out;
    }

    /**
     * Незаведённые номера «Из таблицы Кати» — пока не выбрали вендора, город или тип: у призрачной строки их нет.
     * Исключение («Кроме Каркаде») их не прячет.
     */
    private static function ghostsShown(?Facets $facets): bool
    {
        return ! $facets || ! array_any($facets->keys(), fn ($k) => $facets->excluded($k) === null && $facets->selected($k) !== []);
    }

    /**
     * Чипы списка предложений — те же у галереи. «Заведено» — промежуток дат (21.10.2026): не запоминается (`ONCE`) —
     * забытый прятал бы строки.
     */
    public static function facets(): array
    {
        return [Common::vendor('offers.vendor_id')->none('Без вендора'), Common::city('coalesce(offers.sale_settlement_id, offers.settlement_id)'), Common::category(Car::sql('offers', 'category')),
            Facet::dates('created', 'Заведено', 'offers.created_at')->icon('calendar-plus')];
    }

    /** Кто держит машину — менеджер текущих условий сделки (`offers.deal_id`), иначе гаража: чип «Менеджер» во «Все». */
    private const HOLDER_SQL = 'coalesce((select d.buyer_id from deals d where d.id = offers.deal_id), (select g.manager_id from garage_cars g where g.offer_id = offers.id))';

    /** Чипы на этот раз: в `ListPrefs` не идут. */
    public const ONCE = ['created', 'until'];

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
        // Заведённый руками — «Создан» без источника: из писем, закупки, с парковки, из таблицы Кати и с Мигторга
        // (`OpenDraft`, «Заведён …») не в счёт — после оценки Т-Страхования «+ Новый» не должен встать на её маршрут.
        $vendorId = Offer::where('moderator_id', $user->id)->whereNotNull('vendor_id')->whereDoesntHave('purchaseCar')
            ->whereNotIn('id', Candidate::whereNotNull('offer_id')->select('offer_id'))
            ->whereDoesntHave('events', fn ($q) => $q->where('type', OfferEventType::Created)->whereNotNull('payload')->whereRaw("payload::text not in ('[]', '{}', 'null')"))
            ->latest('id')->value('vendor_id');

        return $vendorId ? ['vendor_id' => $vendorId, 'prices_include_vat' => Vendor::offersVat($vendorId), 'supplier_vat' => Vendor::supplierVat($vendorId)] : [];
    }

    public function edit(Request $request, Offer $offer)
    {
        // Давно закрытое предложение лежит в холодном слое без конверсий — досчитать, раз открыли.
        if (in_array($offer->state, [OfferState::Archived, OfferState::Cancelled, OfferState::Delivered], true)) {
            app(WarmPhotos::class)($offer);
        }
        $fromMail = $offer->state === OfferState::Draft && session()->has("mail-draft.{$offer->id}");
        // Пустой «+ Новый»: первым — поле для текста про машину; уйдут, ничего не внеся, — черновика нет. Открыли снова
        // (в том числе обновили страницу) — отметка, чтобы удаление по прошлому уходу его не тронуло.
        $empty = ! $fromMail && $offer->state === OfferState::Draft && Offer::emptyDraft()->whereKey($offer->id)->exists();
        if ($empty) {
            Offer::whereKey($offer->id)->update(['updated_at' => now()]);
        }

        // Данные и решения — те же, что у окна машины (`CarView`, 11.11.2026): дорожки, плашки, меню, письма, чаты.
        return view('admin.offers.edit', CarView::for($offer, $request->user(), page: true)->vars() + [
            // ?window= — открыть окно писем сразу (ссылка «Вся переписка» из шторки документов).
            'window' => str_starts_with((string) request()->query('window'), "/offers/{$offer->number}/letters") ? request()->query('window') : null,
            // Черновик только что заведён из писем и ещё ни разу не сохранён: внизу «Отменить» и «Не заявка».
            'fromMail' => $fromMail,
            // Только что заведён «Завести» из таблицы Кати: внизу «Отменить» — пока не сохранили.
            'fromLegacy' => ! $fromMail && $offer->state === OfferState::Draft && session()->has("legacy-draft.{$offer->id}"),
            'empty' => $empty,
        ]);
    }

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

    /**
     * «Сохранить» — и в список предложений; у черновика «Выставить в продажу» — та же форма с `then=open`: сначала поля, потом
     * в продажу; «+ Новый» (`then=next`) — сразу следующий черновик с тем же вендором: пачку заводят подряд.
     */
    public function update(OfferRequest $request, Offer $offer, UpdateOffer $update, ScheduleOffer $schedule, CreateOffer $create)
    {
        $unpriced = $offer->state === OfferState::Draft && ! $offer->asking_price;
        $hadFloor = (bool) $offer->floor_price;
        $payload = $request->payload();
        // Цена продажи под вопросом — «Точно?» (`PriceGuard`); модератор цену продажи не ставит — его не спрашиваем.
        if ($request->user()->canManageCrm()) {
            PriceGuard::assert($offer, $payload, $request->boolean('price_ok'));
        }
        $update($offer, $payload, $request->user());
        // Эксклюзив за оценку решили в шторке «Показ» (узел «За оценку», `x-offer.exclusive-pick mode=show`): только когда блок
        // тронули или нажали «Сохранить» шторки (`apply`), иначе любое сохранение окна решало бы за человека.
        if ($request->user()->canManageCrm() && $request->input('exclusive.'.$offer->id.'.apply') === '1') {
            Exclusive::decideFrom($request, $offer->refresh(), required: false);
        }
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
            return redirect('/offers')->with('toast', 'Сохранено');
        }
        if ($request->input('then') === 'open' && $offer->state === OfferState::Draft && $request->user()->canManageCrm()) {
            PriceGuard::assertPublish([$offer->refresh()], $request->boolean('price_ok'));
            $offer = DB::transaction(function () use ($request, $offer, $schedule) {
                Exclusive::decideFrom($request, $offer);

                return $schedule($offer->refresh(), $this->when($request, Slots::NEAREST), $request->user(), until: Slots::urgentUntil($request->input('urgent_until')));
            });

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

        return redirect('/offers')->with('toast', 'Снято с продажи');
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

        return redirect('/offers?preset=unpriced')->with('toast', 'ТС осталась на парковке')
            ->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()));
    }

    /** Продлить приём на ходу: от текущего срока, если он ещё не прошёл, иначе от сейчас. */
    /** «Менеджер отказался» → «Вернуть в продажу…» на часы (`ResaleRefused`). */
    public function refusedResale(Request $request, Offer $offer, \App\Offers\Actions\ResaleRefused $resale)
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'in:'.implode(',', \App\Offers\Actions\ResaleRefused::HOURS)]]);
        $offer = $resale($offer, (int) $data['hours'], $request->user());

        return back()->with('toast', 'В продаже до '.Ago::day($offer->bids_close_at, true))->with(self::gone($request, $offer->refresh()));
    }

    /** «Не снимать автоматически» — авто-снятие после отказа менеджера гаснет. */
    public function keepOnSale(Request $request, Offer $offer, \App\Offers\Actions\ResaleRefused $resale)
    {
        $resale->keep($offer, $request->user());

        return back()->with('toast', 'Не снимем автоматически');
    }

    public function extend(Request $request, Offer $offer, UpdateOffer $update)
    {
        // «+15 мин», «+1 ч» или «Возобновить» (`renew`, 19.10.2026: кнопкой в окошке «Приём закрыт») — обычный срок приёма
        // от сейчас, не позже срока обязывающего (`Binding::clampClose`).
        $data = $request->validate(['minutes' => ['required_without:renew', 'integer', 'in:15,60'], 'renew' => ['nullable', 'boolean']]);
        $from = $offer->bids_close_at?->isFuture() ? $offer->bids_close_at : now();
        $until = $request->boolean('renew') ? Binding::clampClose($offer, Slots::closeFor(now())) : $from->copy()->addMinutes((int) $data['minutes']);
        $offer = $update($offer, ['bids_close_at' => $until], $request->user());

        // Из «Приём закрыт» строка уходит сразу (в «Опубликованные»), числа вкладок — следом, без обновления страницы.
        return back()->with('toast', 'Приём до '.Ago::day($offer->bids_close_at, true))->with(self::gone($request, $offer->refresh()));
    }

    /**
     * Горит без подтверждений (22.10.2026, владелец): «Снизить цену» — новая цена продажи на ходу, приём идёт дальше;
     * «Уведомить менеджеров» — «Ни одного подтверждения» всем, кому машина открыта (`NoBidsAlarm::notify`).
     */
    public function lowerPrice(Request $request, Offer $offer, UpdateOffer $update)
    {
        abort_unless($offer->state === OfferState::Open, 422);
        $raw = (int) Money::rubles($request->validate(['asking_price' => ['required', 'string', 'max:20']], ['asking_price.required' => 'Впишите цену'])['asking_price']);
        if (! $raw || $raw >= (int) $offer->asking_price) {
            return back()->withErrors(['asking_price' => 'Ниже нынешней '.Money::rub($offer->asking_price)]);
        }
        PriceGuard::assert($offer, ['asking_price' => $raw], $request->boolean('price_ok'));
        $update($offer, ['asking_price' => $raw], $request->user(), ['why' => 'Горит без подтверждений']);

        return back()->with('toast', 'Цена продажи '.Money::rub($raw));
    }

    public function notifyNoBids(Request $request, Offer $offer, NoBidsAlarm $alarm)
    {
        abort_unless($offer->state === OfferState::Open && $offer->bidsOpen(), 422);
        $n = $alarm->notify($offer);

        return back()->with('toast', $n ? 'Уведомили: '.$n.' '.Plural::of($n, ['менеджер', 'менеджера', 'менеджеров']) : 'Уведомлять некого');
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
        PriceGuard::assert($offer, ['asking_price' => $raw], $request->boolean('price_ok'));
        $update($offer, ['asking_price' => $raw], $request->user());

        return back()->with('detail-advance', true)->with('detail-gone', true)->with('detail-counts', self::counts($request->user()))
            ->with('toast', 'Оценено: '.Money::rub($raw));
    }

    /**
     * «Марки нет» в «Ценах» окна (12.11.2026, начальник: прицепы Альфы без марки не уходили в продажу): марки у ТС нет вовсе —
     * признак машины `no_brand`, публикация его принимает (`ChangeOfferState::missing`); вписали марку — снимается сам.
     */
    public function noBrand(Request $request, Offer $offer, UpdateOffer $update)
    {
        abort_unless($offer->isEditableBy($request->user()), 404);
        if (! $offer->brand_id && ! $offer->no_brand) {
            $update($offer, ['no_brand' => true], $request->user(), ['why' => 'Марки нет']);
        }

        return back()->with('toast', 'Без марки');
    }

    /**
     * «Оценочная неактуальна» — пункт «⋯» (15.11.2026, владелец по 0760/046/13173/26: «ждать цены от Альфы»): оценочная и
     * закупочная от неё снимаются, черновик встаёт в «Без закупочной» (`DropValuation`).
     */
    public function dropValue(Request $request, Offer $offer, DropValuation $drop)
    {
        abort_unless($offer->isEditableBy($request->user()), 404);
        $drop($offer, $request->user(), 'Оценочная неактуальна — ждём новую от поставщика');

        return back()->with('toast', 'Оценочная снята — в «Без закупочной»')->with(self::gone($request, $offer->refresh()));
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
        // Вписали только оценочную (Альфа), а закупочную браузер не досчитал — она по правилу (`Pricing`).
        $floor ??= $value && Pricing::valued($offer) ? Pricing::floorFrom($value, Pricing::rulesFor($offer)) : null;
        if (! $floor || $offer->state !== OfferState::Draft) {
            return back()->with('detail-advance', true);
        }
        $update($offer, array_filter(['floor_price' => $floor, 'value' => $value]), $request->user());
        $gone = self::gone($request, $offer->refresh());

        return back()->with('detail-advance', true)->with($gone)->with('toast', 'Закупочная: '.Money::rub($floor));
    }

    /**
     * «Выставить в продажу» пачкой из «Оцененных» и действия «Публикации»: сейчас, в ближайший или следующий слот (у
     * стоящего в слоте — перенос). Не готовое (нет фото) остаётся на месте, остальные уходят. «Сейчас» пачкой —
     * менеджеру одно «Опубликовано N» тиком часов, а не по уведомлению на каждое.
     */
    public function scheduleMany(Request $request, ScheduleOffer $schedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer'], 'when' => ['nullable', 'string']]);
        $when = $this->when($request, Slots::NEAREST);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        PriceGuard::assertPublish($offers, $request->boolean('price_ok'));
        $done = 0;
        $failed = [];
        foreach ($offers as $offer) {
            try {
                // Эксклюзив за оценку — из блока машины в шторке пачки (`x-offer.exclusive-pick pack`), одной транзакцией с выходом.
                DB::transaction(function () use ($request, $offer, $schedule, $when, $offers) {
                    Exclusive::decideFrom($request, $offer);
                    $schedule($offer, $when, $request->user(), batch: $offers->count() > 1, until: Slots::urgentUntil($request->input('urgent_until')));
                });
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $offer->titleWithYear().', '.(isset($e->errors()['exclusive']) ? 'не выбрано, кому эксклюзив за оценку: откройте «Выставить в продажу» заново'
                    : 'нет '.str_replace(['Для публикации не хватает: ', 'фотографии'], ['', 'фото'], (string) collect($e->errors())->flatten()->first()));
            }
        }
        $at = Slots::at($when);
        $toast = $done ? ($at ? 'Выйдут '.Slots::phrase($at).': '.$done : 'Опубликовано: '.$done) : null;
        $back = back(fallback: '/offers?preset=priced');

        return $failed
            ? $back->with('toast-danger', ($toast ? $toast.'. ' : '').'Не вышли '.count($failed).': '.implode('; ', array_slice($failed, 0, 3)))
            : $back->with('toast', $toast);
    }

    /**
     * «Точно?» до отправки (`price_guard_controller`): `offer` с ценами из формы — правка цен (`whole` — цены целиком: снова
     * в продажу, переоценка открытого), `offers[]` — публикация. Ответ — что под вопросом, сервер всё равно проверит сам.
     */
    public function priceCheck(Request $request)
    {
        $data = $request->validate(['offer' => ['nullable', 'integer'], 'offers' => ['nullable', 'array', 'max:500'], 'offers.*' => ['integer'],
            'asking_price' => ['nullable', 'string', 'max:20'], 'floor_price' => ['nullable', 'string', 'max:20'], 'whole' => ['boolean']]);
        if (filled($data['offers'] ?? null)) {
            $risky = PriceGuard::risky(Offer::whereIn('number', $data['offers'])->get());

            return response()->json(['warnings' => $risky->map(fn ($r) => ['name' => $r['offer']->titleWithYear(), 'title' => $r['title'], 'text' => $r['text']])->all()]);
        }
        $offer = Offer::where('number', $data['offer'] ?? 0)->firstOrFail();
        $prices = collect(['asking_price', 'floor_price'])->filter(fn ($k) => $request->has($k))->mapWithKeys(fn ($k) => [$k => Money::rubles($data[$k] ?? null) ?: null])->all();
        $check = $request->boolean('whole')
            ? Margin::check($prices['asking_price'] ?? $offer->asking_price, $prices['floor_price'] ?? $offer->floor_price)
            : PriceGuard::after($offer, $prices);

        return response()->json(['warnings' => $check ? [['name' => $offer->titleWithYear(), 'title' => $check['title'], 'text' => $check['text']]] : []]);
    }

    /** «Убрать из слота» пачкой: машины возвращаются в «Оцененные». */
    public function unscheduleMany(Request $request, UnscheduleOffer $unschedule)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        $offers = Offer::whereIn('number', $data['offers'])->get();
        $offers->each(fn ($o) => $unschedule($o, $request->user()));

        return back(fallback: '/offers?preset=slots')->with('toast', 'Убрано из слота: '.$offers->count());
    }

    /** «Удалить навсегда» из архива — одно предложение (карточка, редактор) со всем связанным (`PurgeOffer`). */
    public function purge(Offer $offer, PurgeOffer $purge)
    {
        $title = $offer->titleWithYear();
        $purge($offer);

        return redirect('/offers?preset=archive')->with('toast', 'Удалено навсегда: '.$title);
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

        return redirect('/offers?preset=archive')->with('toast', 'Удалено навсегда: '.$done);
    }

    /**
     * «Без подтверждений» пачкой (06.11.2026, владелец: «максимально быстрым и удобным»): галочки вкладки → «Вернуть в
     * продажу (N)» с одним сроком (цены и кому — у каждой свои, как в прошлый раз) или «В архив (N)». Каждая машина — своей
     * транзакцией: не вышла одна (нет фото, приём уже вернули) — остальные идут, тост называет, что не вышло.
     */
    public function relistMany(Request $request, ReturnToSale $return)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer'], 'term' => ['required', 'in:3,7']]);
        [$done, $failed] = [0, []];
        foreach ($this->unsoldPicked($request, $data['offers']) as $offer) {
            try {
                $return($offer, $request->user(), [], self::termClose($offer, $data['term']));
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $offer->number.': '.mb_strtolower(collect($e->errors())->flatten()->first() ?? 'не вышло');
            }
        }

        return redirect('/offers?preset=done')->with('toast', self::manyToast('В продаже', $done, $failed));
    }

    public function archiveMany(Request $request, ChangeOfferState $change)
    {
        $data = $request->validate(['offers' => ['required', 'array', 'max:500'], 'offers.*' => ['integer']]);
        [$done, $failed] = [0, []];
        foreach ($this->unsoldPicked($request, $data['offers']) as $offer) {
            try {
                $change($offer, OfferState::Archived, $request->user());
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $offer->number.': '.mb_strtolower(collect($e->errors())->flatten()->first() ?? 'не вышло');
            }
        }

        return redirect('/offers?preset=done')->with('toast', self::manyToast('В архиве', $done, $failed));
    }

    /** Отмеченные из «Без подтверждений», что ещё там: успели вернуть или убрать — пропускаются молча. */
    private function unsoldPicked(Request $request, array $numbers)
    {
        return self::scopeFor('done', Offer::inCrm($request->user())->whereIn('offers.number', $numbers))->get();
    }

    /** «В продаже: 12», «В архиве: 3, не вышло 2 — 2610…: нет фото». */
    private static function manyToast(string $what, int $done, array $failed): string
    {
        return $what.': '.$done.($failed ? ', не вышло '.count($failed).' — '.implode('; ', array_slice($failed, 0, 3)).(count($failed) > 3 ? '…' : '') : '');
    }

    /** Состояние из меню; «Выставить в продажу» у черновика и галереи — с выбором слота (`when`), без него — сейчас. */
    public function state(Request $request, Offer $offer, ChangeOfferState $change, ScheduleOffer $schedule)
    {
        $next = OfferState::from($request->validate(['state' => ['required', 'string']])['state']);
        // Модератору — только «Отменить сделку» в черновики (24.11.2026): остальные переходы — цена и показ, решает админ.
        abort_unless($request->user()->canManageCrm() || ($offer->state === OfferState::Sold && $next === OfferState::Draft), 403);
        // В сделку руками нельзя: сделку заводит «Принять» у подтверждения.
        if ($next === OfferState::Sold) {
            return back()->withErrors(['state' => 'Примите подтверждение менеджера, сделка заведётся сама']);
        }
        // «В галерею» — окно показа черновика, не переход; висит — «Убрать из галереи».
        if ($next === OfferState::Gallery) {
            $gallery = app(ShowInGallery::class);
            $offer->isGallery() ? $gallery->hide($offer, $request->user()) : $gallery($offer, $request->user());

            return redirect("/offers/{$offer->number}")->with('toast', $offer->isGallery() ? 'В галерее до '.Ago::day($offer->gallery_until, true) : 'Убрано из галереи')->with(self::gone($request, $offer));
        }
        if ($next === OfferState::Open && in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            PriceGuard::assertPublish([$offer], $request->boolean('price_ok'));
            $offer = DB::transaction(function () use ($request, $offer, $schedule) {
                $offer = $this->saleCity($request, $offer);
                // «Выставить всем…» (`all=1`, 09.11.2026): эксклюзив за оценку снимается осознанно, после предупреждения; без него
                // выход ставит оценщика сразу, остальных — после (`Exclusive`).
                if ($request->boolean('all')) {
                    Exclusive::waive($offer, $request->user());
                }
                Exclusive::decideFrom($request, $offer);

                return $schedule($offer, $this->when($request, Slots::NOW), $request->user(), until: Slots::urgentUntil($request->input('urgent_until')));
            });

            return redirect("/offers/{$offer->number}")->with('toast', $this->published($offer))->with(self::gone($request, $offer));
        }
        $change($offer, $next, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $next->label())->with(self::gone($request, $offer));
    }

    /**
     * Город продажи из шторки (`x-offer.sale-city`: «Выставить в продажу», «Вернуть в продажу»): не пришёл или тот же — не
     * трогаем, другой — через `UpdateOffer`, было → стало в «Истории». Вызывать в транзакции действия: не вышло выставить —
     * и город прежний.
     */
    private function saleCity(Request $request, Offer $offer, ?string $why = null): Offer
    {
        if ($change = SaleCity::input($request, $offer)) {
            return app(UpdateOffer::class)($offer, $change, $request->user(), $why ? ['why' => $why] : []);
        }

        return $offer;
    }

    /**
     * «Открыть всем сейчас…» (09.11.2026): эксклюзив за оценку у машины в продаже снимается после предупреждения, «Показ» —
     * как по умолчанию у вендора.
     */
    public function exclusiveOff(Request $request, Offer $offer, UpdateOffer $update)
    {
        abort_unless($offer->state === OfferState::Open, 404);
        DB::transaction(function () use ($request, $offer, $update) {
            $waived = Exclusive::waive($offer, $request->user());
            $waived->isNotEmpty() && $update($offer->refresh(), ['audience_rules' => null, 'first_look_until' => null, 'first_look_user_id' => null], $request->user(), ['why' => 'Эксклюзив за оценку снят']);
        });

        return redirect("/offers/{$offer->number}")->with('toast', 'Открыто всем')->with(self::gone($request, $offer));
    }

    /**
     * «На аукцион» из «Действий» (13.10.2026): `mode=now` — снять с публикации и сразу во вкладку, `after` — по истечении
     * срока, если подтверждений не будет (`SendToAuction`); DELETE — отменить план или убрать с аукциона.
     */
    public function auction(Request $request, Offer $offer, SendToAuction $send)
    {
        $mode = $request->validate(['mode' => ['required', 'in:now,after']])['mode'];
        $offer = $mode === 'now' ? $send->now($offer, $request->user()) : $send->plan($offer, $request->user());
        $toast = $mode === 'now' ? 'На аукционе' : 'Уйдёт на аукцион '.Ago::day($offer->bids_close_at, true).', если не будет подтверждений';

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
     * «Продана на аукционе» (20.10.2026, Совкомбанк): шторка `x-offer.auction-sale-sheet` — покупатель, цена, когда, «Счёт
     * выставлен вне системы»; сделка без менеджера закрывается сразу (`SellAtAuction`).
     */
    public function auctionSold(Request $request, Offer $offer, SellAtAuction $sell)
    {
        $sell($offer, $request->user(), $request->validate(SellAtAuction::rules(), SellAtAuction::messages()));

        return redirect("/offers/{$offer->number}")->with('toast', 'Продана на аукционе')->with(self::gone($request, $offer));
    }

    public function auctionUnsold(Request $request, Offer $offer, UndoAuctionSale $undo)
    {
        $offer = $undo($offer, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', 'Продажа на аукционе отменена')->with(self::gone($request, $offer));
    }

    /**
     * «Вернуть в продажу» из сделки или гаража с новыми ценами (шторка `x-offer.resale-sheet`): закупочная, заявленная
     * (пусто — от закупочной) и цена продажи; сделка отменяется, строка гаража уходит (`ReturnToSale`). Машина у менеджера —
     * «Стоит у …» (`stays`, 21.10.2026): остаётся у него, город и адрес осмотра — его.
     */
    public function resale(Request $request, Offer $offer, ReturnToSale $return, UpdateOffer $update)
    {
        $data = $request->validate(['floor_price' => ['nullable', 'string', 'max:20'], 'publish_price' => ['nullable', 'string', 'max:20'],
            'asking_price' => ['required', 'string', 'max:20'], 'bids_close_at' => ['nullable', 'date', 'after:now'], 'audience_rules' => ['nullable', 'json'],
            'binding_until' => ['nullable', 'date'], 'stays' => ['nullable', 'boolean'], 'settlement_id' => ['nullable', 'integer', 'exists:settlements,id'], 'inspection_address' => ['nullable', 'string', 'max:500'],
            'min_bid_price' => ['nullable', 'string', 'max:20'], 'min_bid_share' => ['nullable', 'string', 'max:10']],
            ['asking_price.required' => 'Укажите цену продажи', 'bids_close_at.after' => 'Срок приёма — позже, чем сейчас']);
        $money = fn (?string $v) => Money::rubles($v) ?: null;
        $prices = array_map($money, array_intersect_key($data, array_flip(['floor_price', 'publish_price', 'asking_price', 'min_bid_price'])) + ['floor_price' => null, 'publish_price' => null]);
        // Доля до продажной (16.11.2026): «0,6» → 0.6, пусто — по умолчанию; поле не пришло — не трогаем.
        if ($request->has('min_bid_share')) {
            $share = blank($data['min_bid_share'] ?? null) ? null : (float) str_replace(',', '.', (string) $data['min_bid_share']);
            if ($share !== null && ($share < 0 || $share > 1)) {
                throw ValidationException::withMessages(['min_bid_share' => 'Доля — от 0 до 1, например 0,6']);
            }
            $prices['min_bid_share'] = $share;
        }
        // Заявленная стоит в поле числом: не трогали расчёт от прежней закупочной — пусто, пойдёт за новой.
        if ($offer->publish_price === null && $prices['publish_price'] === Offer::declaredFrom($offer->floor_price)) {
            $prices['publish_price'] = null;
        }
        PriceGuard::assertPrices($prices['asking_price'], $prices['floor_price'] ?? $offer->floor_price, $request->boolean('price_ok'));
        $audience = $request->has('audience_rules') ? (json_decode((string) $request->input('audience_rules'), true) ?: []) : null;
        // Срок обязывающего — то же поле, что в «Показе»; до возврата: приём сверяется уже с ним.
        $offer = DB::transaction(function () use ($request, $offer, $update, $return, $prices, $data, $audience) {
            if ($binding = self::bindingChange($request, $offer)) {
                $update($offer, $binding, $request->user(), ['why' => 'Вернуть в продажу']);
            }
            $offer = $this->saleCity($request, $offer, 'Вернуть в продажу');

            return $return($offer, $request->user(), $prices, filled($data['bids_close_at'] ?? null) ? Carbon::parse($data['bids_close_at']) : null, $audience,
                stays: $request->boolean('stays'), where: array_intersect_key($data, array_flip(['settlement_id', 'inspection_address'])));
        });

        return redirect("/offers/{$offer->number}")->with('toast', 'В продаже № '.$offer->number)->with(self::gone($request, $offer));
    }

    /**
     * Срок обязывающего из шторки «Вернуть в продажу» (`x-offer.binding-field`): поле пришло и вендор по комиссии — что поменялось; пусто — снять.
     *
     * @return array{binding_until?: ?Carbon}
     */
    private static function bindingChange(Request $request, Offer $offer): array
    {
        if (! $request->has('binding_until') || ! Binding::applies($offer)) {
            return [];
        }
        $at = filled($request->input('binding_until')) ? Carbon::parse($request->input('binding_until')) : null;

        return $at?->format('Y-m-d H:i') === $offer->binding_until?->format('Y-m-d H:i') ? [] : ['binding_until' => $at];
    }

    /** Срок приёма «вернуть пачкой» (3 дня или неделя) — в час закрытия приёма, не позже обязывающего. */
    public static function termClose(Offer $offer, string $term): Carbon
    {
        return Binding::clampClose($offer, Slots::closeFor(now(), (int) $term));
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
        // Галерея с 16.11.2026 — вкладки колеса (`gallery`, `interest`): её условие — в `scopeFor`, как у остальных.
        $still = ($ref['path'] ?? '/offers') !== '/offers'
            || self::scopeFor($preset, Offer::whereKey($offer->id), $request->user()->canManageCrm())->exists();

        // На вкладках дела карточка идёт к следующей строке, на остальных строка просто уходит.
        $counts = self::counts($request->user());

        return $still ? [] : ['detail-gone' => true, 'detail-counts' => $counts]
            + (($ref['path'] ?? '/offers') === '/offers' && in_array($preset, self::ADVANCE, true) ? ['detail-advance' => true] : []);
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
            $offer->isUrgent() => 'Срочно в продаже № '.$offer->number.', приём до '.Ago::day($offer->bids_close_at, true),
            default => 'В продаже № '.$offer->number,
        };
    }
}
