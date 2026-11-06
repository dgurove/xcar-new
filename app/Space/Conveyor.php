<?php

namespace App\Space;

use App\Http\Admin\OfferController;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Offers\Binding;
use App\Offers\CatalogQuery;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Конвейер на главной CRM (05.11.2026, владелец: «каждый столбец — ЭТАП: объединение соседних подэтапов, что делает один и
 * тот же человек»; задач карточками нет — они дублировали списки, и было непонятно, где смотреть). Этап — столбец: кто
 * делает и его подэтапы-глаголы; на подэтапе — сколько задач и сколько горит, нажатие ведёт в его список. Сделка — по
 * людям: Катя, поставщики, менеджеры, вывоз (ход в ней прыгает между ними — порядок примерный).
 *
 * Числа — те же двери, что у вкладок предложений (`OfferController::scopeFor`), «Ваш ход» сделок (`Position::todo`,
 * `waitsFor`) и вывоза (`Transport`), чтобы схема не расходилась со списками. Горит — только настоящий срок: обязывающий
 * Совкомбанка, просроченная оценка, приём кончается без подтверждений, просроченный шаг сделки, менеджер не ответил про
 * закупочную, вывоз опаздывает или стоит больше суток. Каркаде на схеме нет (владелец: «забей»). Кто за что — `Owners`.
 *
 * Снимок — числа и id, без моделей: общий на всех сотрудников, устаревает вместе со счётчиками таб-бара
 * (`Nav::forgetStaffCounts` — любое событие сотрудникам), пересчитывается не чаще раза в 10 с; люди и логотипы
 * подставляются при выводе (`view`).
 */
final class Conveyor
{
    /** Ключ меняется вместе с видом снимка: прежний снимок другого вида после выкладки ронял бы главную. */
    public const KEY = 'space.conveyor.v3';

    private const DIRTY = 'space.conveyor.dirty';

    /** Секунд между пересчётами и сколько снимок живёт без событий (сроки идут и без них). */
    private const CALM = 10;

    private const TTL = 60;

    /** Отдан снимок, который уже устарел, но пересчитывать его рано, — главной спросить ещё раз через `CALM`. */
    public static bool $stale = false;

    /**
     * Этапы слева направо: название и подэтапы (05.11.2026, владелец прошёлся по каждому: «мне непонятно, а другим тем более»).
     * Андрей дважды: между его этапами — менеджеры. «Ждём поставщика» — тихий: сделать там нечего, только ждать. Слота
     * («Публикация») нет: «Выставить в продажу» в 16:00 — таймер того же действия, задач там нет (владелец 05.11.2026).
     */
    public const STAGES = [
        'intake' => ['Заведение', ['letters', 'floor']],
        'sale' => ['Цена и продажа', ['price', 'review', 'ready', 'unsold']],
        'bidding' => ['Оценка и подтверждения', ['estimate', 'gallery', 'confirm']],
        'choice' => ['Подтверждения и заявка', ['bids', 'claims', 'a-write', 'a-reply', 'answer', 'reprice']],
        'deal' => ['Заявка', ['write', 'reply', 'invoice', 'paid', 'settle']],
        'supplier' => ['Ждём поставщика', ['s-agree', 's-requisites', 's-paid', 's-docs']],
        'manager' => ['Оплата и получение', ['m-consent', 'm-buyer', 'm-car', 'm-fee', 'm-pickup', 'm-scans']],
        'arrange' => ['Оформить вывоз', ['assign', 'logist', 'cdek']],
        'drive' => ['Исполнить вывоз', ['drive']],
    ];

    /** Этапы, где работают многие и без лиц: значок группы вместо аватаров (владелец: «просто менеджеры написал»). */
    private const GROUPS = ['bidding', 'manager'];

    /** Этап, где только ждём, — тише остальных. */
    private const QUIET = ['supplier'];

    /**
     * Подэтап: подпись, список, где его задачи (`logistics:` — хост логистики; «Отправить отклики» — вендор, считается), и
     * подпункты со своими числами — подэтап их сумма.
     */
    public const STEPS = [
        'letters' => ['Завести из писем', '/offers/from-mail'],
        'floor' => ['Вписать закупочную', '/offers?preset=nofloor'],
        // Админ во вкладке «Без продажной цены» либо вписывает цену сам, либо отдаёт менеджерам — один подэтап.
        'price' => ['Оценить / отдать на оценку', '/offers?preset=unpriced'],
        'review' => ['Разобрать оценки', '/offers?preset=estimating'],
        'ready' => ['Выставить в продажу', '/offers?preset=priced'],
        'unsold' => ['Вернуть или в архив', '/offers?preset=done'],
        'estimate' => ['На оценке', '/offers?preset=estimating'],
        'gallery' => ['Выразить интерес', '/gallery'],
        'confirm' => ['Подтвердить предложения', '/offers?preset=published'],
        'bids' => ['Принять подтверждения', '/offers?preset=bids', ['bids-closed' => 'Приём закрыт', 'bids-open' => 'Приём ещё идёт']],
        'claims' => ['Отправить отклики', null],
        'a-write' => ['Отправить заявку', '/work/deals?preset=us'],
        'a-reply' => ['Прочитать ответ поставщика', '/work/deals?preset=us'],
        'answer' => ['Ответить про переоценку', '/work/deals?preset=us'],
        'reprice' => ['Рассмотреть переоценку', '/work/deals?preset=us'],
        'write' => ['Отправить заявку', '/work/deals?preset=us'],
        'reply' => ['Прочитать ответ поставщика', '/work/deals?preset=us'],
        // Счёт встаёт сам; не встал по нашей причине — доля по гаражу, вознаграждение, реквизиты Р.О.С. (`Deal::invoiceGap`).
        'invoice' => ['Доделать счёт', '/work/deals?preset=us'],
        'paid' => ['Проверить оплату', '/work/deals?preset=us'],
        'settle' => ['Оплатить поставщику', '/work/deals?preset=us'],
        's-agree' => ['Согласие на покупку', '/work/deals?preset=supplier'],
        's-requisites' => ['Реквизиты для счёта', '/work/deals?preset=supplier'],
        's-paid' => ['Подтверждение оплаты', '/work/deals?preset=supplier'],
        's-docs' => ['Документы', '/work/deals?preset=supplier'],
        'm-consent' => ['Дать согласие', '/work/deals?preset=manager'],
        // Без покупателя (и его паспорта) не встанут ни договор, ни счёт.
        'm-buyer' => ['Заполнить покупателя', '/work/deals?preset=manager'],
        'm-car' => ['Оплатить ТС', '/work/deals?preset=manager'],
        'm-fee' => ['Оплатить подбор', '/work/deals?preset=manager'],
        'm-pickup' => ['Забрать ТС', '/work/deals?preset=manager'],
        'm-scans' => ['Приложить сканы документов', '/work/deals?preset=manager'],
        'assign' => ['Назначить вывоз', 'logistics:/transports?preset=new'],
        'logist' => ['Назначить исполнителя', 'logistics:/transports?preset=logist'],
        'cdek' => ['Документы СДЭКом', 'logistics:/transports?preset=work'],
        'drive' => ['Привезти ТС', 'logistics:/transports?preset=work'],
    ];

    /**
     * Блок маршрута сделки → подэтап: [ход поставщика, ход менеджера, наш ход без своего дела (`stage:*`)]. У вендоров пути
     * разные — на схеме общий; чего нет в таблице, на схеме не считается. Оплату менеджера уточняет схема (`managerStep`).
     */
    private const BLOCKS = [
        'Согласование с поставщиком' => ['s-agree', 'm-consent', 'write'],
        'Реквизиты для счёта' => ['s-requisites', 'm-buyer', 'invoice'],
        'Оплата' => ['s-paid', 'm-car', 'paid'],
        'Получение автомобиля' => ['s-docs', 'm-pickup', 'settle'],
        'Договор' => ['s-docs', 'm-scans', 'settle'],
        'Оформление документов' => ['s-docs', 'm-scans', 'settle'],
        'Расчёт с поставщиком' => ['s-docs', 'm-scans', 'settle'],
        'Взяли под себя' => ['s-docs', 'm-scans', 'settle'],
        'Оформляем на нас' => ['s-docs', 'm-scans', 'settle'],
        // Заявки поставщику — только у Каркаде, его на схеме нет.
        'Заявка у поставщика' => ['s-docs', 'm-scans', null],
        'Покупатель от поставщика' => ['s-docs', 'm-scans', null],
    ];

    /** Сколько лиц у этапа с многими людьми: дальше — «+N». */
    private const SHOWN = 4;

    /**
     * Пересчёт — не чаще раза в `CALM` с (04.11.2026: на проде полсекунды-секунда, а событие сотрудникам приходит каждую
     * минуту и чаще). Событие снимок не трогает, а пишет время в маленький ключ `DIRTY` (`forget`); снимок помнит, когда
     * его начали считать: событие позже начала — снимок устарел, даже если пересчёт шёл в это время. Устаревший и старше
     * `CALM` — считается заново, моложе — отдаётся как есть, и `$stale` просит главную спросить ещё раз позже.
     */
    public static function snapshot(): array
    {
        $hit = Cache::get(self::KEY);
        $dirty = $hit && (int) Cache::get(self::DIRTY, 0) >= $hit['at'];
        self::$stale = $dirty && time() - $hit['at'] < self::CALM;
        if ($hit && (! $dirty || self::$stale)) {
            return $hit['snap'];
        }
        $at = time();
        $snap = self::count();
        Cache::put(self::KEY, ['snap' => $snap, 'at' => $at], self::TTL);

        return $snap;
    }

    public static function forget(): void
    {
        Cache::put(self::DIRTY, time(), self::TTL);
    }

    /**
     * Этапы для вывода: у этапа люди (модели подставлены; у постоянных ответственных без числа, у общих — по убыванию
     * задач, лишние — числом `more`), подэтапы с числом, горящими и ссылкой, всего и горящих.
     */
    public static function view(): array
    {
        $snap = self::snapshot();
        $fixed = [
            'intake' => [Owners::of('floor'), Owners::of('drafts')],
            'sale' => [Owners::of('price')],
            'choice' => [Owners::of('bids')],
            'deal' => [Owners::of('deals')],
        ];
        $faces = collect($snap['people'])->flatten(1);
        $users = User::with(User::withAvatar())
            ->whereKey($faces->where('type', 'user')->pluck('id')->merge(collect($fixed)->flatten())->unique())->get()->keyBy('id');
        $vendors = Vendor::whereKey($faces->where('type', 'vendor')->pluck('id')->push($snap['claims_vendor'])->filter()->unique())->get()->keyBy('id');

        $stages = collect(self::STAGES)->map(function (array $stage, string $key) use ($snap, $fixed, $users, $vendors) {
            [$title, $steps] = $stage;
            // Ключ лица — тот же, что у фильтра «Ответственный»: поставщики и исполнители там — группой.
            $people = match (true) {
                isset($fixed[$key]) => collect($fixed[$key])->map(fn (int $id) => ['type' => 'user', 'id' => $id, 'key' => 'user:'.$id, 'model' => $users->get($id)]),
                in_array($key, self::GROUPS, true) => collect([['type' => 'icon', 'icon' => 'users', 'label' => 'Менеджеры', 'key' => 'group:managers']]),
                default => collect($snap['people'][$key] ?? [])
                    ->map(fn ($p) => [...$p,
                        'key' => match (true) { $p['type'] === 'vendor' => 'group:vendors', $key === 'drive' => 'group:drivers', default => 'user:'.$p['id'] },
                        'model' => $p['type'] === 'vendor' ? $vendors->get($p['id']) : $users->get($p['id'])])
                    ->sortByDesc('n'),
            };
            $people = $people->filter(fn ($p) => $p['type'] === 'icon' || $p['model'])->values();
            $steps = collect($steps)->map(fn (string $step) => [
                'key' => $step,
                'title' => self::STEPS[$step][0],
                'href' => self::href($step, $snap),
                'n' => $snap['steps'][$step]['n'] ?? 0,
                'hot' => $snap['steps'][$step]['hot'] ?? 0,
                'parts' => collect(self::STEPS[$step][2] ?? [])->map(fn (string $title, string $part) => ['key' => $part, 'title' => $title, 'n' => $snap['steps'][$part]['n'] ?? 0])->values(),
                'vendor' => $step === 'claims' ? $vendors->get($snap['claims_vendor']) : null,
                'by' => self::by($step, $snap),
            ]);

            return [
                'key' => $key,
                'title' => $title,
                'quiet' => in_array($key, self::QUIET, true),
                'people' => $people->take(self::SHOWN),
                'more' => max(0, $people->count() - self::SHOWN),
                'steps' => $steps,
                'total' => $steps->sum('n'),
                'hot' => $steps->sum('hot'),
            ];
        })->all();

        return $stages;
    }

    /**
     * Кто делает подэтап и сколько у каждого — для фильтра «Ответственный»: `[ключ => [число, горящих]]`. У постоянного
     * ответственного и у группы — весь подэтап (и пустой: работа его, просто сейчас её нет), у «Назначить исполнителя» —
     * по логисту перевозок (`pickups`).
     */
    private static function by(string $step, array $snap): array
    {
        if ($key = Owners::keyOf($step)) {
            return [$key => [$snap['steps'][$step]['n'] ?? 0, $snap['steps'][$step]['hot'] ?? 0]];
        }

        return $snap['steps'][$step]['by'] ?? [];
    }

    /**
     * Выбор фильтра «Ответственный»: сначала наши — кто делает подэтапы доски, по имени, — затем группы (`Owners::GROUPS`).
     * `[['key' => 'user:4', 'name' => …, 'user' => User|null, 'icon' => …]]`.
     */
    public static function people(array $board): array
    {
        $keys = collect($board)->flatMap(fn ($stage) => $stage['steps']->flatMap(fn ($step) => array_keys($step['by'])))->unique();
        $users = User::with(User::withAvatar())
            ->whereKey($keys->filter(fn ($k) => str_starts_with($k, 'user:'))->map(fn ($k) => (int) substr($k, 5)))->get()->sortBy('name');
        $icons = ['group:managers' => 'users', 'group:vendors' => 'bank', 'group:drivers' => 'cat-truck'];

        return [
            ...$users->map(fn (User $u) => ['key' => 'user:'.$u->id, 'name' => $u->name, 'user' => $u, 'icon' => null])->values()->all(),
            ...collect(Owners::GROUPS)->filter(fn ($name, $key) => $keys->contains($key))
                ->map(fn ($name, $key) => ['key' => $key, 'name' => $name, 'user' => null, 'icon' => $icons[$key]])->values()->all(),
        ];
    }

    private static function href(string $step, array $snap): ?string
    {
        $href = self::STEPS[$step][1];
        if ($step === 'claims') {
            return ($vendor = $snap['claims_vendor'] ?? null) ? "/settings/vendors/{$vendor}?pill=requests" : null;
        }

        return str_starts_with($href, 'logistics:') ? Surface::Logistics->url(substr($href, 10)) : $href;
    }

    private static function count(): array
    {
        $carcade = OfferController::carcadeId();
        $offers = fn () => self::withoutCarcade(Offer::query()->notEmptyDraft(), $carcade);
        // Вкладки и их горящие — одним запросом: условие вкладки (`scopeFor`) — фильтром счёта, тяжёлое «не пустой
        // черновик» — один раз. Горит в черновиках и продаже — обязывающий Совкомбанка (`Binding::panicSql`).
        $panic = Binding::panicSql();
        $tab = fn (string $preset) => fn ($q) => OfferController::scopeFor($preset, $q);
        $hot = fn (string $preset, ?\Closure $also = null) => fn ($q) => OfferController::scopeFor($preset, $q)
            ->where(fn ($w) => $also ? $also($w->whereRaw($panic)) : $w->whereRaw($panic));
        $unsold = $tab('done');
        $group = fn (string $preset, string $sql, int $n) => fn ($q) => OfferController::scopeFor($preset, $q)->whereRaw("({$sql}) = {$n}");
        $estimated = '('.OfferController::ESTIMATE_GROUP.')';
        $pick = OfferController::PICK_GROUP;
        $tabs = self::tabs($offers(), [
            'floor' => $tab('nofloor'), 'floor:hot' => $hot('nofloor'),
            'price' => $tab('unpriced'), 'price:hot' => $hot('unpriced'),
            // «На оценке у менеджеров» — группами вкладки (`ESTIMATE_GROUP`): хоть один оценил — разбирает Андрей, иначе ждём
            // менеджеров, просроченная просьба горит.
            'review' => $group('estimating', $estimated, 0), 'review:hot' => fn ($q) => $hot('estimating')($q)->whereRaw($estimated.' = 0'),
            'estimate' => $group('estimating', $estimated, 1),
            'estimate:hot' => fn ($q) => $hot('estimating', fn ($w) => $w->orWhereHas('estimates', fn ($e) => $e->waiting()->where('due_at', '<', now())))($q)->whereRaw($estimated.' = 1'),
            'ready' => $tab('priced'), 'ready:hot' => $hot('priced'),
            'unsold' => $unsold, 'unsold:hot' => fn ($q) => $unsold($q)->whereRaw($panic),
            // Приём горит, когда до конца меньше `HOT_HOURS`, а подтверждений нет («Сгорает!» на сайте; без них он и есть
            // в «Опубликованных»).
            'confirm' => $tab('published'), 'confirm:hot' => $hot('published', fn ($w) => $w->orWhere(fn ($e) => CatalogQuery::ending($e))),
            // «Есть подтверждения» — группами вкладки (`PICK_GROUP`): приём закрыт — выбирать сейчас, идёт — ждать.
            'bids-closed' => $group('bids', $pick, 0), 'bids-open' => $group('bids', $pick, 1), 'bids:hot' => $hot('bids'),
        ]);
        $steps = [];
        foreach ($tabs as $key => $n) {
            [$step, $kind] = [...explode(':', $key), 'n'];
            $steps[$step][$kind] = $n;
        }
        $steps['bids']['n'] = $steps['bids-closed']['n'] + $steps['bids-open']['n'];
        $steps['letters'] = ['n' => Boxes::registerCount(Scope::Offers), 'hot' => 0];
        $steps['gallery'] = ['n' => self::withoutCarcade(Offer::inGallery(), $carcade)->count(), 'hot' => 0];
        // «Отправить отклики» — «К запросу» вендоров с галереей; ведёт к тому, у кого их больше.
        $claims = Vendor::where('to_gallery', true)->get()->mapWithKeys(fn (Vendor $v) => [$v->id => ClaimRequest::pendingFor($v)->count()]);
        $steps['claims'] = ['n' => $claims->sum(), 'hot' => 0];

        $people = [];
        self::deals($carcade, $steps, $people);
        self::pickups($carcade, $steps, $people);

        return [
            'steps' => $steps,
            'people' => array_map('array_values', $people),
            'claims_vendor' => $claims->filter()->sortDesc()->keys()->first() ?? $claims->keys()->first(),
        ];
    }

    /**
     * Несколько счётов одним запросом: `count(*) filter (where …)` на каждое условие. Условие строится на чистом
     * `Offer::query()`, его where — фильтр (подзапросы `whereHas` ссылаются на ту же `offers`).
     *
     * @param  array<string, \Closure>  $filters
     * @return array<string, int>
     */
    private static function tabs(Builder $base, array $filters): array
    {
        $selects = [];
        $bindings = [];
        foreach ($filters as $key => $filter) {
            $q = $filter(Offer::query())->toBase();
            $selects[] = 'count(*) filter ('.$q->getGrammar()->compileWheres($q).') as "'.$key.'"';
            array_push($bindings, ...$q->getBindings());
        }
        $row = (array) $base->toBase()->selectRaw(implode(', ', $selects), $bindings)->first();

        return array_map('intval', $row);
    }

    /** Каркаде на схеме нет (владелец 04.11.2026: «забей»): одно условие на предложения и всё, что от них. */
    private static function withoutCarcade($q, ?int $carcade, string $column = 'offers.vendor_id')
    {
        return $q->when($carcade, fn ($q) => $q->where(fn ($v) => $v->whereNull($column)->orWhere($column, '!=', $carcade)));
    }

    /**
     * Идущие сделки по подэтапам. Наш ход — по делу (`Position::todo`, его же «Ваш ход» «Сделок»): переоценка, ответ про неё,
     * письма Альфы и Т-Страхования — Андрею (`Owners`), остальное — Кате; ход поставщика и менеджера — по блоку маршрута, у
     * поставщиков — с числом у каждого вендора. Гаражная у менеджера не считается: машина стоит в гараже, его ход там — только
     * просьбой (`Deal::managerTask`), а «Оплата счёта ПРАЙМ» висит до продажи. Везёт логистика — это «Вывоз».
     */
    private static function deals(?int $carcade, array &$steps, array &$people): void
    {
        $letters = Owners::letterVendors();
        $deals = Deal::where('state', DealState::Active)
            ->whereHas('offer', fn ($o) => self::withoutCarcade($o, $carcade))
            ->with(['offer', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice'])->get();
        // Наш ход одним SQL (`NEEDS_US_SQL`): `todo()` — только у них, он спрашивал бы переоценку по каждой сделке.
        $ours = Position::query()->needsUs()->where('track', 'sale')->whereIn('offer_id', $deals->pluck('offer_id'))->pluck('offer_id')->flip();
        $add = function (string $step, bool $hot, ?array $who = null, ?string $stage = null) use (&$steps, &$people) {
            $steps[$step]['n'] = ($steps[$step]['n'] ?? 0) + 1;
            $steps[$step]['hot'] = ($steps[$step]['hot'] ?? 0) + ($hot ? 1 : 0);
            if ($who && $stage) {
                $key = $who['type'].':'.$who['id'];
                $people[$stage][$key] ??= [...$who, 'n' => 0, 'late' => 0];
                $people[$stage][$key]['n']++;
                $people[$stage][$key]['late'] += $hot ? 1 : 0;
            }
        };
        foreach ($deals as $deal) {
            $position = $deal->offer?->position();
            if (! $position) {
                continue;
            }
            $position->setRelation('deal', $deal);
            $block = self::BLOCKS[$position->stage?->block?->name ?? $position->stage?->name] ?? null;
            $hot = $position->isOverdue() || Binding::panics($deal->offer);
            if (isset($ours[$deal->offer_id]) && ($todo = $position->todo())) {
                // Менеджер не ответил про закупочную или не дал согласия в срок — горит само.
                ($step = self::ours($todo['key'], $block[2] ?? null, in_array($deal->offer->vendor_id, $letters, true)))
                    && $add($step, $hot || in_array($todo['key'], ['price-ask', 'consent'], true));

                continue;
            }
            // Блока нет в таблице (новый в редакторе маршрутов, ещё «Выбор подтверждения») — не угадываем подэтап.
            if (! $block) {
                continue;
            }
            match ($position->waitsFor()) {
                WaitsFor::Supplier => $add($block[0], $hot, $deal->offer->vendor_id ? ['type' => 'vendor', 'id' => $deal->offer->vendor_id] : null, 'supplier'),
                WaitsFor::Manager => $deal->isGarage() ? null : $add(self::managerStep($block[1], $position, $deal), $hot),
                default => null,
            };
        }
    }

    /**
     * Подэтап нашего хода по делу «Ваш ход»; дела без своего ключа (`stage:*`) — по блоку маршрута, нет его — никуда. Письма
     * Альфы и Т-Страхования — у Андрея (`$letters`); «Заполнить» реквизиты и согласие, не данное в срок, — дела менеджера:
     * без них сделка не идёт, а позвонить ему — не отдельная работа.
     */
    private static function ours(string $key, ?string $byBlock, bool $letters): ?string
    {
        return match (true) {
            in_array($key, ['reprice', 'price-ask'], true) => 'reprice',
            $key === 'reprice-answer' => 'answer',
            in_array($key, ['letter', 'bounced'], true) => $letters ? 'a-write' : 'write',
            $key === 'reply' => $letters ? 'a-reply' : 'reply',
            $key === 'requisites' => 'm-buyer',
            $key === 'consent' => 'm-consent',
            str_starts_with($key, 'gap:') => 'invoice',
            str_starts_with($key, 'request:') => null,
            default => $byBlock,
        };
    }

    /**
     * Ход менеджера: договор — сканы с подписями; оплата — за что платит: без покупателя счёта нет (`managerGap`), по счёту
     * ПРАЙМ или Р.О.С. — сама ТС, иначе — подбор XCar по ссылке (`DealScheme::paysCar`).
     */
    private static function managerStep(string $byBlock, Position $position, Deal $deal): string
    {
        return match (true) {
            str_contains(mb_strtolower($position->stage->name), 'договор') => 'm-scans',
            $byBlock !== 'm-car' => $byBlock,
            $deal->managerGap() => 'm-buyer',
            $deal->schemeOf()->paysCar() => 'm-car',
            default => 'm-fee',
        };
    }

    /**
     * Вывоз: оформить — назначить (Катя), исполнителя у логиста (Татьяна), документы СДЭКом (Лиза); исполнить — исполнители. Горит —
     * день вывоза прошёл без «Забрал» (`isLate`) или у логиста и исполнителя стоит больше суток.
     */
    private static function pickups(?int $carcade, array &$steps, array &$people): void
    {
        $open = fn (): Builder => Transport::query()->open()->inLogistics()
            ->when($carcade, fn ($q) => $q->whereNotIn('transports.car_id', Offer::where('vendor_id', $carcade)->select('car_id')));
        // Машина не нужна — только шаг и даты (`OnCar` подгружал бы машину, марку и модель на каждый пересчёт).
        $rows = fn (Builder $q) => $q->withoutEagerLoads()->get(['transports.id', 'transports.car_id', 'state', 'logist_id', 'executor_id', 'pickup_at', 'assigned_at']);
        $late = fn (Transport $t) => $t->isLate()
            || (in_array($t->state, [TransportState::Logist, TransportState::Assigned], true) && $t->assigned_at?->lt(now()->subDay()));
        $tally = function (string $step, string $stage, $list, \Closure $who) use (&$steps, &$people, $late) {
            $steps[$step] = ['n' => $list->count(), 'hot' => $list->filter($late)->count(), 'by' => []];
            foreach ($list as $t) {
                if (! ($id = $who($t))) {
                    continue;
                }
                $by = &$steps[$step]['by']['user:'.$id];
                $by = [($by[0] ?? 0) + 1, ($by[1] ?? 0) + ($late($t) ? 1 : 0)];
                unset($by);
                $people[$stage]['user:'.$id] ??= ['type' => 'user', 'id' => $id, 'n' => 0, 'late' => 0];
                $people[$stage]['user:'.$id]['n']++;
                $people[$stage]['user:'.$id]['late'] += $late($t) ? 1 : 0;
            }
        };

        $tally('assign', 'arrange', $rows($open()->where('state', TransportState::New)), fn () => Owners::of('pickup'));
        $tally('logist', 'arrange', $rows($open()->step('logist')), fn (Transport $t) => $t->logist_id);
        $tally('cdek', 'arrange', $rows($open()->awaitingDocs()), fn () => Owners::of('docs'));
        // Ждёт документов СДЭКом — у Лизы, не у исполнителя: одна перевозка — один подэтап.
        $tally('drive', 'drive', $rows($open()->whereIn('state', [TransportState::Assigned, TransportState::Agreed, TransportState::Moving])
            ->whereNotIn('transports.id', Transport::query()->awaitingDocs()->select('transports.id'))), fn (Transport $t) => $t->executor_id);
    }
}
