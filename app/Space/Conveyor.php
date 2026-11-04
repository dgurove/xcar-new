<?php

namespace App\Space;

use App\Http\Admin\OfferController;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Estimate;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Plural;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use App\Workflow\WaitsFor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Конвейер на главной CRM (04.11.2026, владелец: «схема конвейер кто что делает — ЭТО ОЧЕНЬ ВАЖНО»): весь путь машины от
 * поступления до выдачи, у этапа — кто его держит и сколько у него висит. Числа — те же двери, что у вкладок предложений
 * (`OfferController::scopeFor`), «Сделок» (`Position::todo`, `waitsFor`) и вывоза (`Transport`), чтобы схема не расходилась
 * со списками. Каркаде на схеме нет (владелец: «забей, не получилось сотрудничество»). Кто за что — `Owners`.
 *
 * Снимок — числа и id, без моделей: общий на всех сотрудников, 15 с в кэше, сбрасывается вместе со счётчиками таб-бара
 * (`Nav::forgetStaffCounts` — любое событие сотрудникам); люди и логотипы подставляются при выводе (`view`).
 */
final class Conveyor
{
    public const KEY = 'space.conveyor';

    /**
     * Этапы: название и место центра карточки в мире — в широкой раскладке (ПК: змейка, вторая линия идёт обратно) и в
     * высокой (телефон: столбец сверху вниз, ветки по бокам). Порядок — порядок в разметке.
     */
    public const NODES = [
        'migtorg' => ['Мигторг', [-1780, -330], [260, 0]],
        'mail' => ['Почта', [-1780, -110], [-260, 0]],
        'park' => ['Парковка', [-1780, 110], [0, 0]],
        'manual' => ['Вручную', [-1780, 330], [-260, 250]],
        'gallery' => ['Галерея', [-1460, -330], [260, 250]],
        'claims' => ['К запросу', [-1100, -330], [260, 500]],
        'floor' => ['Закупочная', [-1360, 0], [0, 250]],
        'price' => ['Цена продажи', [-1020, 0], [0, 500]],
        'estimate' => ['На оценке', [-1020, 290], [-260, 500]],
        'ready' => ['В продажу', [-680, 0], [0, 750]],
        'slots' => ['Публикация', [-340, 0], [0, 1000]],
        'intake' => ['Приём подтверждений', [0, 0], [0, 1250]],
        'unsold' => ['Не продано', [-340, 290], [260, 1000]],
        'bids' => ['Выбор подтверждения', [340, 0], [0, 1500]],
        'agree' => ['Согласование', [340, 580], [0, 1750]],
        'garage' => ['Гараж', [340, 870], [260, 1750]],
        'requisites' => ['Реквизиты', [0, 580], [0, 2000]],
        'pay' => ['Оплата', [-340, 580], [0, 2250]],
        'pickup' => ['Получение', [-680, 580], [0, 2500]],
        'assign' => ['Назначить вывоз', [-680, 870], [260, 2500]],
        'logist' => ['Логист', [-1020, 870], [260, 2750]],
        'executors' => ['Исполнители', [-1360, 870], [260, 3000]],
        'cdek' => ['Документы СДЭКом', [-1780, 870], [260, 3250]],
        'docs' => ['Документы', [-1020, 580], [0, 2750]],
        'settle' => ['Расчёт с поставщиком', [-1360, 580], [0, 3000]],
        'done' => ['Выдан', [-1780, 580], [0, 3250]],
    ];

    /** Связи: откуда, куда и вид — `flow` (основной поток), `back` (возврат), `hint` (пунктиром, без потока). */
    public const LINKS = [
        ['mail', 'floor', 'flow'], ['migtorg', 'floor', 'flow'], ['park', 'floor', 'flow'], ['manual', 'floor', 'flow'],
        ['migtorg', 'gallery', 'flow'], ['gallery', 'claims', 'flow'], ['claims', 'floor', 'hint'],
        ['floor', 'price', 'flow'], ['price', 'estimate', 'flow'], ['estimate', 'price', 'back'], ['price', 'ready', 'flow'],
        ['ready', 'slots', 'flow'], ['slots', 'intake', 'flow'], ['intake', 'bids', 'flow'], ['intake', 'unsold', 'back'],
        ['unsold', 'ready', 'back'], ['bids', 'agree', 'flow'], ['agree', 'garage', 'flow'], ['agree', 'requisites', 'flow'],
        ['requisites', 'pay', 'flow'], ['pay', 'pickup', 'flow'], ['pickup', 'docs', 'flow'], ['docs', 'settle', 'flow'],
        ['settle', 'done', 'flow'], ['pickup', 'assign', 'flow'], ['assign', 'logist', 'flow'], ['logist', 'executors', 'flow'],
        ['executors', 'docs', 'flow'], ['cdek', 'executors', 'hint'],
    ];

    /** Блок маршрута сделки → этап конвейера (у вендоров пути разные, на схеме — общий; владелец 04.11.2026). */
    private const BLOCKS = [
        'Согласование с поставщиком' => 'agree',
        'Реквизиты для счёта' => 'requisites',
        'Оплата' => 'pay',
        'Получение автомобиля' => 'pickup',
        'Оформление документов' => 'docs',
        'Договор' => 'docs',
        'Расчёт с поставщиком' => 'settle',
    ];

    /** Сколько людей в карточке: дальше — «+N». */
    private const SHOWN = 6;

    /** @return array<string, array{holders: list<array>, note?: ?string, flow?: int}> */
    public static function snapshot(): array
    {
        return Cache::remember(self::KEY, 15, fn () => self::count());
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * Этапы для вывода: люди и логотипы подставлены, держатели по убыванию, лишние — числом `more`.
     *
     * @return array<string, array{title: string, wide: array, tall: array, holders: Collection, more: int, total: int, note: ?string, flow: int, idle: bool}>
     */
    public static function view(): array
    {
        $snap = self::snapshot();
        $holders = collect($snap)->flatMap(fn ($node) => $node['holders']);
        $users = User::with(User::withAvatar())->whereKey($holders->where('type', 'user')->pluck('id')->unique())->get()->keyBy('id');
        $vendors = Vendor::whereKey($holders->where('type', 'vendor')->pluck('id')->unique())->get()->keyBy('id');

        return collect(self::NODES)->map(function (array $node, string $key) use ($snap, $users, $vendors) {
            [$title, $wide, $tall] = $node;
            $list = collect($snap[$key]['holders'] ?? [])
                ->map(fn ($h) => [...$h, 'model' => match ($h['type']) {
                    'user' => $users->get($h['id']), 'vendor' => $vendors->get($h['id']), default => null
                }])
                ->filter(fn ($h) => ! in_array($h['type'], ['user', 'vendor'], true) || $h['model'])
                // Ответственный сотрудник и автоматика — первыми, дальше кто больше держит.
                ->sortBy([fn ($a, $b) => ($b['lead'] ?? false) <=> ($a['lead'] ?? false), fn ($a, $b) => ($b['n'] ?? 0) <=> ($a['n'] ?? 0)])
                ->values();
            // Всего — по снимку, а не по найденным людям: удалённый человек не обнуляет этап.
            $total = (int) collect($snap[$key]['holders'] ?? [])->sum(fn ($h) => $h['n'] ?? 0);

            return [
                'title' => $title,
                'wide' => $wide,
                'tall' => $tall,
                'holders' => $list->take(self::SHOWN),
                'more' => max(0, $list->count() - self::SHOWN),
                'total' => $total,
                'note' => $snap[$key]['note'] ?? null,
                'flow' => $snap[$key]['flow'] ?? 0,
                // Дела нет — карточка тише; автоматика работает всегда, источники и «Выдан» живут своей строкой.
                'idle' => $total === 0 && ! ($snap[$key]['note'] ?? null) && $list->doesntContain('type', 'robot'),
            ];
        })->all();
    }

    private static function count(): array
    {
        $carcade = OfferController::carcadeId();
        $offers = fn () => Offer::query()->notEmptyDraft()->when($carcade, fn ($q) => $q->where(fn ($v) => $v->whereNull('offers.vendor_id')->orWhere('offers.vendor_id', '!=', $carcade)));
        $tab = fn (string $key) => OfferController::scopeFor($key, $offers())->count();
        $today = today();
        $robot = fn (string $via, ?int $n = null) => ['type' => 'robot', 'via' => $via, 'n' => $n, 'lead' => true];
        $owner = fn (string $work, int $n) => ['type' => 'user', 'id' => Owners::of($work), 'n' => $n, 'lead' => true];
        $icon = fn (string $icon, int $n, ?string $label = null) => ['type' => 'icon', 'icon' => $icon, 'n' => $n, 'label' => $label];
        $born = fn () => $offers()->where('offers.created_at', '>=', $today);
        $note = fn (int $n, ?array $what = null) => $n > 0 ? '+'.$n.' '.($what ? Plural::of($n, $what).' ' : '').'сегодня' : null;

        // Менеджеры на оценке: ждут их ответа (просрочено — красным), а что оценили — решает Андрей.
        $estimates = Estimate::waiting()->whereIn('offer_id', $offers()->where('offers.state', OfferState::Draft)->select('offers.id'))
            ->selectRaw('user_id, count(*) as n, count(*) filter (where due_at < now()) as late')->groupBy('user_id')->get();
        $given = OfferController::scopeFor('estimating', $offers())->whereHas('estimates', fn ($e) => $e->needsDecision())->count();
        $unsold = OfferController::scopeFor('done', $offers())->where('offers.state', OfferState::Draft)->count();
        $claims = Vendor::where('to_gallery', true)->get()->sum(fn (Vendor $v) => ClaimRequest::pendingFor($v)->count());

        // Источники: сколько пришло сегодня — строкой дня и потоком по линии.
        $source = fn (array $holder, int $today) => ['holders' => [$holder], 'note' => $note($today), 'flow' => $today];
        $nodes = [
            'mail' => $source($robot('mail'), $born()->where('offers.created_via', 'mail')->count()),
            'migtorg' => $source($robot('migtorg'), $born()->where('offers.created_via', 'migtorg')->count()),
            'park' => $source($robot('park'), $born()->where('offers.created_via', 'park')->count()),
            'manual' => $source($owner('drafts', Boxes::registerCount(Scope::Offers)), $born()->whereNotNull('offers.moderator_id')->count()),
            'gallery' => ['holders' => [$icon('photo', Offer::inGallery()->count())], 'note' => $note(Interest::where('created_at', '>=', $today)->whereHas('offer', fn ($o) => $o->whereNotNull('gallery_from'))->count(), ['интерес', 'интереса', 'интересов'])],
            'claims' => ['holders' => [$owner('claims', $claims)]],
            'floor' => ['holders' => [$robot('auto'), $owner('floor', $tab('nofloor'))]],
            'price' => ['holders' => [$owner('price', $tab('unpriced'))]],
            'estimate' => ['holders' => [$owner('price', $given), ...$estimates->map(fn ($e) => ['type' => 'user', 'id' => $e->user_id, 'n' => (int) $e->n, 'late' => (int) $e->late])]],
            'ready' => ['holders' => [$owner('price', $tab('priced'))]],
            'slots' => ['holders' => [$robot('auto', $tab('slots'))]],
            'intake' => ['holders' => [$icon('users', $tab('published'))],
                'note' => $note(Bid::where('created_at', '>=', $today)->whereIn('offer_id', $offers()->select('offers.id'))->count(), ['подтверждение', 'подтверждения', 'подтверждений'])],
            'unsold' => ['holders' => [$owner('price', $unsold)]],
            'bids' => ['holders' => [$owner('bids', $tab('bids'))]],
        ];

        return [...$nodes, ...self::deals($carcade), ...self::pickups($carcade)];
    }

    /**
     * Идущие сделки по этапам: у каждой один держатель — чей ход. Наш ход — по делу (`Position::todo`, его же «Ваш ход»
     * «Сделок»): переоценка и письма Альфы — Андрею, остальное — Кате; ход менеджера — его аватар (просрочено — красным),
     * поставщика — логотип вендора, вывоз везёт логистика — грузовик. Гаражные — отдельным выходом «Гараж».
     */
    private static function deals(?int $carcade): array
    {
        $alfa = Vendor::where('name', 'ilike', '%альфа%')->pluck('id')->all();
        $deals = Deal::where('state', DealState::Active)->whereNull('auction_at')
            ->whereHas('offer', fn ($o) => $o->when($carcade, fn ($q) => $q->where(fn ($v) => $v->whereNull('vendor_id')->orWhere('vendor_id', '!=', $carcade))))
            ->with(['offer', 'offer.vendor', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice'])->get();

        $tally = [];
        $add = function (string $node, array $who, bool $late = false) use (&$tally) {
            $key = $who['type'].':'.($who['id'] ?? $who['icon'] ?? '');
            $tally[$node][$key] ??= [...$who, 'n' => 0, 'late' => 0];
            $tally[$node][$key]['n']++;
            $tally[$node][$key]['late'] += $late ? 1 : 0;
        };
        foreach ($deals as $deal) {
            if ($deal->isGarage()) {
                $deal->buyer_id && $add('garage', ['type' => 'user', 'id' => $deal->buyer_id]);

                continue;
            }
            $position = $deal->offer?->position();
            $node = self::BLOCKS[$position?->stage?->block?->name] ?? null;
            if (! $node) {
                continue;
            }
            $position->setRelation('deal', $deal);
            if ($todo = $position->todo()) {
                $add($node, ['type' => 'user', 'id' => Owners::forTodo($todo['key'], in_array($deal->offer->vendor_id, $alfa, true)), 'lead' => true]);

                continue;
            }
            match ($position->waitsFor()) {
                WaitsFor::Manager => $deal->buyer_id ? $add($node, ['type' => 'user', 'id' => $deal->buyer_id], $position->isOverdue()) : null,
                WaitsFor::Supplier => $deal->offer->vendor_id ? $add($node, ['type' => 'vendor', 'id' => $deal->offer->vendor_id]) : null,
                default => $add($node, ['type' => 'icon', 'icon' => 'cat-truck', 'label' => 'Везёт логистика']),
            };
        }

        $done = Deal::where('state', DealState::Done)->whereNull('garage_payer')->where('closed_at', '>=', now()->subDays(30))
            ->whereHas('offer', fn ($o) => $o->when($carcade, fn ($q) => $q->where(fn ($v) => $v->whereNull('vendor_id')->orWhere('vendor_id', '!=', $carcade))))
            ->count();

        return [
            ...collect(['agree', 'requisites', 'pay', 'pickup', 'docs', 'settle', 'garage'])
                ->mapWithKeys(fn ($node) => [$node => ['holders' => array_values($tally[$node] ?? [])]])->all(),
            'done' => ['holders' => [], 'note' => $done > 0 ? $done.' за месяц' : null, 'flow' => $done],
        ];
    }

    /** Вывоз: без исполнителя — назначить (Катя), у логиста — его, документы СДЭКом — Лиза, в работе — исполнители. */
    private static function pickups(?int $carcade): array
    {
        $open = fn (): Builder => Transport::query()->open()->inLogistics()
            ->when($carcade, fn ($q) => $q->whereNotIn('transports.car_id', Offer::where('vendor_id', $carcade)->select('car_id')));
        $people = fn (Builder $q, string $column) => $q->whereNotNull($column)->selectRaw("{$column} as id, count(*) as n")->groupBy($column)->get()
            ->map(fn ($r) => ['type' => 'user', 'id' => (int) $r->id, 'n' => (int) $r->n])->all();

        return [
            'assign' => ['holders' => [['type' => 'user', 'id' => Owners::of('pickup'), 'n' => $open()->where('state', TransportState::New)->count(), 'lead' => true]]],
            'logist' => ['holders' => $people($open()->step('logist'), 'logist_id')],
            'executors' => ['holders' => $people($open()->where(fn ($s) => $s->whereIn('state', [TransportState::Assigned, TransportState::Moving])
                ->orWhere(fn ($a) => $a->where('state', TransportState::Agreed)->where(fn ($d) => $d->where('docs_by_cdek', false)->orWhereNotNull('docs_sent_at')))), 'executor_id')],
            'cdek' => ['holders' => [['type' => 'user', 'id' => Owners::of('docs'), 'n' => $open()->awaitingDocs()->count(), 'lead' => true]]],
        ];
    }
}
