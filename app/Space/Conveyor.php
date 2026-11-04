<?php

namespace App\Space;

use App\Http\Admin\OfferController;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Offers\Binding;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Interest;
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
    public const KEY = 'space.conveyor';

    private const DIRTY = 'space.conveyor.dirty';

    /** Секунд между пересчётами и сколько снимок живёт без событий (сроки идут и без них). */
    private const CALM = 10;

    private const TTL = 60;

    /** Отдан снимок, который уже устарел, но пересчитывать его рано, — главной спросить ещё раз через `CALM`. */
    public static bool $stale = false;

    /** Этапы слева направо: название и подэтапы. */
    public const STAGES = [
        'intake' => ['Заведение', ['letters', 'floor']],
        'sale' => ['Цена и продажа', ['price', 'estimate', 'ready', 'slots', 'unsold']],
        'bidding' => ['Приём подтверждений', ['confirm']],
        'choice' => ['Выбор и переоценка', ['bids', 'claims', 'answer', 'reprice']],
        'deal' => ['Сделка', ['write', 'reply', 'invoice', 'paid', 'settle', 'request', 'call', 'assign']],
        'supplier' => ['Ответ поставщика', ['s-agree', 's-requisites', 's-paid', 's-docs']],
        'manager' => ['Оплата и получение', ['m-consent', 'm-requisites', 'm-pay', 'm-pickup', 'm-contract']],
        'pickup' => ['Вывоз', ['logist', 'drive', 'cdek']],
    ];

    /** Подэтап: глагол и список, где его задачи (`logistics:` — хост логистики; «Выразить интерес» — вендор, считается). */
    public const STEPS = [
        'letters' => ['Завести из писем', '/offers/from-mail'],
        'floor' => ['Вписать закупочную', '/offers?preset=nofloor'],
        'price' => ['Назначить цену продажи', '/offers?preset=unpriced'],
        'estimate' => ['Оценить', '/offers?preset=estimating'],
        'ready' => ['Выставить в продажу', '/offers?preset=priced'],
        'slots' => ['Опубликовать', '/offers?preset=slots'],
        'unsold' => ['Вернуть в продажу', '/offers?preset=done'],
        'confirm' => ['Подтвердить ценой', '/offers?preset=published'],
        'bids' => ['Выбрать менеджера', '/offers?preset=bids'],
        'claims' => ['Выразить интерес', null],
        'answer' => ['Ответить поставщику', '/work/deals?preset=us'],
        'reprice' => ['Рассмотреть переоценку', '/work/deals?preset=us'],
        'write' => ['Написать поставщику', '/work/deals?preset=us'],
        'reply' => ['Разобрать ответ', '/work/deals?preset=us'],
        'invoice' => ['Выставить счёт', '/work/deals?preset=us'],
        'paid' => ['Проверить оплату', '/work/deals?preset=us'],
        'settle' => ['Оплатить поставщику', '/work/deals?preset=us'],
        'request' => ['Отправить заявку', '/work/deals?preset=us'],
        'call' => ['Позвонить менеджеру', '/work/deals?preset=us'],
        'assign' => ['Назначить вывоз', 'logistics:/transports?preset=new'],
        's-agree' => ['Согласовать покупку', '/work/deals?preset=supplier'],
        's-requisites' => ['Прислать реквизиты', '/work/deals?preset=supplier'],
        's-paid' => ['Подтвердить оплату', '/work/deals?preset=supplier'],
        's-docs' => ['Оформить документы', '/work/deals?preset=supplier'],
        'm-consent' => ['Дать согласие', '/work/deals?preset=manager'],
        'm-requisites' => ['Дать реквизиты', '/work/deals?preset=manager'],
        'm-pay' => ['Оплатить счёт', '/work/deals?preset=manager'],
        'm-pickup' => ['Забрать ТС', '/work/deals?preset=manager'],
        'm-contract' => ['Подписать договор', '/work/deals?preset=manager'],
        'logist' => ['Найти исполнителя', 'logistics:/transports?preset=logist'],
        'drive' => ['Привезти ТС', 'logistics:/transports?preset=work'],
        'cdek' => ['Документы СДЭКом', 'logistics:/transports?preset=work'],
    ];

    /**
     * Блок маршрута сделки → подэтап, когда ход поставщика или менеджера, и наш ход без своего дела (`stage:*`). У вендоров
     * пути разные — на схеме общий; чего нет в таблице — последним подэтапом стороны.
     */
    private const BLOCKS = [
        'Согласование с поставщиком' => ['s-agree', 'm-consent', 'write'],
        'Реквизиты для счёта' => ['s-requisites', 'm-requisites', 'invoice'],
        'Оплата' => ['s-paid', 'm-pay', 'paid'],
        'Получение автомобиля' => ['s-docs', 'm-pickup', 'settle'],
        'Договор' => ['s-docs', 'm-contract', 'settle'],
        'Оформление документов' => ['s-docs', 'm-contract', 'settle'],
        'Расчёт с поставщиком' => ['s-docs', 'm-contract', 'settle'],
        'Взяли под себя' => ['s-docs', 'm-contract', 'settle'],
        'Оформляем на нас' => ['s-docs', 'm-contract', 'settle'],
        'Собираем заявку поставщику' => ['s-docs', 'm-contract', 'request'],
        'Заявка у поставщика' => ['s-docs', 'm-contract', 'request'],
        'Покупатель от поставщика' => ['s-docs', 'm-contract', 'request'],
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
     * задач, лишние — числом `more`), подэтапы с числом, горящими и ссылкой, всего и горящих; источники и выходы.
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
        $vendors = Vendor::whereKey($faces->where('type', 'vendor')->pluck('id')->unique())->get()->keyBy('id');

        $stages = collect(self::STAGES)->map(function (array $stage, string $key) use ($snap, $fixed, $users, $vendors) {
            [$title, $steps] = $stage;
            $people = match (true) {
                isset($fixed[$key]) => collect($fixed[$key])->map(fn (int $id) => ['type' => 'user', 'id' => $id, 'model' => $users->get($id)]),
                $key === 'bidding' => collect([['type' => 'icon', 'icon' => 'users', 'label' => 'Менеджеры']]),
                default => collect($snap['people'][$key] ?? [])
                    ->map(fn ($p) => [...$p, 'model' => $p['type'] === 'vendor' ? $vendors->get($p['id']) : $users->get($p['id'])])
                    ->sortByDesc('n'),
            };
            $people = $people->filter(fn ($p) => $p['type'] === 'icon' || $p['model'])->values();
            $steps = collect($steps)->map(fn (string $step) => [
                'key' => $step,
                'title' => self::STEPS[$step][0],
                'href' => self::href($step, $snap),
                'n' => $snap['steps'][$step]['n'] ?? 0,
                'hot' => $snap['steps'][$step]['hot'] ?? 0,
            ]);

            return [
                'key' => $key,
                'title' => $title,
                'people' => $people->take(self::SHOWN),
                'more' => max(0, $people->count() - self::SHOWN),
                'steps' => $steps,
                'total' => $steps->sum('n'),
                'hot' => $steps->sum('hot'),
            ];
        })->all();

        return ['stages' => $stages, 'sources' => $snap['sources'], 'ends' => $snap['ends']];
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
        $unsold = fn ($q) => OfferController::scopeFor('done', $q)->where('offers.state', OfferState::Draft);
        $tabs = self::tabs($offers(), [
            'floor' => $tab('nofloor'), 'floor:hot' => $hot('nofloor'),
            'price' => $tab('unpriced'), 'price:hot' => $hot('unpriced'),
            // Оценка горит и просроченной просьбой менеджеру.
            'estimate' => $tab('estimating'), 'estimate:hot' => $hot('estimating', fn ($w) => $w->orWhereHas('estimates', fn ($e) => $e->waiting()->where('due_at', '<', now()))),
            'ready' => $tab('priced'), 'ready:hot' => $hot('priced'),
            'slots' => $tab('slots'), 'slots:hot' => $hot('slots'),
            'unsold' => $unsold, 'unsold:hot' => fn ($q) => $unsold($q)->whereRaw($panic),
            // Приём горит, когда до конца меньше `HOT_HOURS`, а подтверждений нет («Сгорает!» на сайте; без них он и есть
            // в «Опубликованных»).
            'confirm' => $tab('published'), 'confirm:hot' => $hot('published', fn ($w) => $w->orWhere(fn ($e) => $e
                ->where('offers.bids_close_at', '>', now())->where('offers.bids_close_at', '<', now()->addHours(Offer::HOT_HOURS)))),
            'bids' => $tab('bids'), 'bids:hot' => $hot('bids'),
        ]);
        $steps = [];
        foreach ($tabs as $key => $n) {
            [$step, $kind] = [...explode(':', $key), 'n'];
            $steps[$step][$kind] = $n;
        }
        $steps['letters'] = ['n' => Boxes::registerCount(Scope::Offers), 'hot' => 0];
        // «Выразить интерес» — «К запросу» вендоров с галереей; ведёт к тому, у кого их больше.
        $claims = Vendor::where('to_gallery', true)->get()->mapWithKeys(fn (Vendor $v) => [$v->id => ClaimRequest::pendingFor($v)->count()]);
        $steps['claims'] = ['n' => $claims->sum(), 'hot' => 0];

        $people = [];
        self::deals($carcade, $steps, $people);
        self::pickups($carcade, $steps, $people);

        $today = today();
        $born = fn () => $offers()->where('offers.created_at', '>=', $today);

        return [
            'steps' => $steps,
            'people' => array_map('array_values', $people),
            'claims_vendor' => $claims->filter()->sortDesc()->keys()->first() ?? $claims->keys()->first(),
            'sources' => [
                'mail' => ['Почта', $born()->where('offers.created_via', 'mail')->count()],
                'migtorg' => ['Мигторг', $born()->where('offers.created_via', 'migtorg')->count()],
                'park' => ['Парковка', $born()->where('offers.created_via', 'park')->count()],
                'gallery' => ['Галерея', Interest::where('created_at', '>=', $today)->whereHas('offer', fn ($o) => $o->whereNotNull('gallery_from'))->count()],
            ],
            'ends' => [
                'done' => ['Выдано за месяц', Deal::where('state', DealState::Done)->whereNull('garage_payer')->where('closed_at', '>=', now()->subDays(30))
                    ->whereHas('offer', fn ($o) => self::withoutCarcade($o, $carcade))->count()],
                'garage' => ['В гараже', Deal::where('state', DealState::Active)->whereNull('auction_at')->whereNotNull('garage_payer')
                    ->whereHas('offer', fn ($o) => self::withoutCarcade($o, $carcade))->count()],
            ],
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
     * Идущие сделки по подэтапам. Наш ход — по делу (`Position::todo`, его же «Ваш ход» «Сделок»): переоценка, ответ про неё
     * и письма Альфы — Андрею (`Owners::forTodo`), остальное — Кате; ход поставщика и менеджера — по блоку маршрута, с
     * числом у каждого вендора и менеджера. Гаражная у менеджера не считается: машина стоит в гараже, его ход там — только
     * просьбой (`Deal::managerTask`), а «Оплата счёта ПРАЙМ» висит до продажи. Везёт логистика — это «Вывоз».
     */
    private static function deals(?int $carcade, array &$steps, array &$people): void
    {
        $alfa = Vendor::where('name', 'ilike', '%альфа%')->pluck('id')->all();
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
            $block = self::BLOCKS[$position->stage?->block?->name ?? $position->stage?->name] ?? ['s-docs', 'm-contract', 'settle'];
            $hot = $position->isOverdue() || Binding::panics($deal->offer);
            if (isset($ours[$deal->offer_id]) && ($todo = $position->todo())) {
                $add(self::ours($todo['key'], $block[2], in_array($deal->offer->vendor_id, $alfa, true)), $hot || $todo['key'] === 'price-ask');

                continue;
            }
            match ($position->waitsFor()) {
                WaitsFor::Supplier => $add($block[0], $hot, $deal->offer->vendor_id ? ['type' => 'vendor', 'id' => $deal->offer->vendor_id] : null, 'supplier'),
                WaitsFor::Manager => $deal->isGarage() ? null : $add(
                    str_contains(mb_strtolower($position->stage->name), 'договор') ? 'm-contract' : $block[1],
                    $hot, $deal->buyer_id ? ['type' => 'user', 'id' => $deal->buyer_id] : null, 'manager'),
                default => null,
            };
        }
    }

    /** Подэтап нашего хода по делу «Ваш ход»; дела без своего ключа (`stage:*`) — по блоку маршрута. */
    private static function ours(string $key, string $byBlock, bool $alfa): string
    {
        return match (true) {
            in_array($key, ['reprice', 'price-ask'], true) => 'reprice',
            $key === 'reprice-answer' => 'answer',
            Owners::forTodo($key, $alfa) === Owners::of('alfa') => 'answer',
            in_array($key, ['letter', 'bounced'], true) => 'write',
            $key === 'reply' => 'reply',
            $key === 'requisites', str_starts_with($key, 'gap:') => 'invoice',
            $key === 'consent' => 'call',
            str_starts_with($key, 'request:') => 'request',
            default => $byBlock,
        };
    }

    /**
     * Вывоз: без исполнителя — назначить (Катя), у логиста — его, документы СДЭКом — Лиза, в работе — исполнители. Горит —
     * день вывоза прошёл без «Забрал» (`isLate`) или у логиста и исполнителя стоит больше суток.
     */
    private static function pickups(?int $carcade, array &$steps, array &$people): void
    {
        $open = fn (): Builder => Transport::query()->open()->inLogistics()
            ->when($carcade, fn ($q) => $q->whereNotIn('transports.car_id', Offer::where('vendor_id', $carcade)->select('car_id')));
        $rows = fn (Builder $q) => $q->get(['transports.id', 'transports.car_id', 'state', 'logist_id', 'executor_id', 'pickup_at', 'assigned_at']);
        $late = fn (Transport $t) => $t->isLate()
            || (in_array($t->state, [TransportState::Logist, TransportState::Assigned], true) && $t->assigned_at?->lt(now()->subDay()));
        $tally = function (string $step, $list, ?\Closure $who) use (&$steps, &$people, $late) {
            $steps[$step] = ['n' => $list->count(), 'hot' => $list->filter($late)->count()];
            foreach ($list as $t) {
                if (! ($id = $who ? $who($t) : null)) {
                    continue;
                }
                $people['pickup']['user:'.$id] ??= ['type' => 'user', 'id' => $id, 'n' => 0, 'late' => 0];
                $people['pickup']['user:'.$id]['n']++;
                $people['pickup']['user:'.$id]['late'] += $late($t) ? 1 : 0;
            }
        };

        $tally('assign', $rows($open()->where('state', TransportState::New)), null);
        $tally('logist', $rows($open()->step('logist')), fn (Transport $t) => $t->logist_id);
        $tally('cdek', $rows($open()->awaitingDocs()), fn () => Owners::of('docs'));
        $tally('drive', $rows($open()->where(fn ($s) => $s->whereIn('state', [TransportState::Assigned, TransportState::Moving])
            ->orWhere(fn ($a) => $a->where('state', TransportState::Agreed)->where(fn ($d) => $d->where('docs_by_cdek', false)->orWhereNotNull('docs_sent_at'))))), fn (Transport $t) => $t->executor_id);
    }
}
