<?php

namespace App\Space;

use App\Http\Admin\OfferController;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Mail\Account;
use App\Mail\Boxes;
use App\Mail\Candidate;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Bid;
use App\Offers\Binding;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Estimate;
use App\Offers\Interest;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Ago;
use App\Users\User;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Доска конвейера (04.11.2026, владелец: «этапы, кто за них отвечает и список задач — как столбцы канбан-доски, только
 * красиво парящие в космосе»; «видно, у кого сколько задач застряло»). Этап — глагол, у него люди с числом задач и сами
 * задачи: машина, сделка, вывоз или дело почты. Горит — только настоящий срок, что уже есть в системе: шаг сделки
 * просрочен, оценка просрочена, приём кончается без подтверждений, обязывающий Совкомбанка, менеджер не ответил про
 * закупочную, вывоз опаздывает или стоит. Давность без срока не горит: черновиков без закупочной на проде 300 с лишним,
 * и почти все старше двух дней — красным было бы всё.
 *
 * Числа и двери — те же, что у вкладок предложений (`OfferController::scopeFor`), «Сделок» (`Position::todo`, `waitsFor`)
 * и вывоза (`Transport`). Каркаде нет. Кто за что — `Owners`. Снимок — скаляры, без моделей; люди и логотипы — при выводе.
 */
final class Board
{
    private const KEY = 'space.board';

    private const TTL = 30;

    /** Сколько задач у этапа рисуется карточками; остальные — «ещё N» (варианты с кучей рисуют все). */
    public const SHOWN = 12;

    /** Варианты вида на выбор владельцу (страница `/space/variants/N`, временно): номер → название. */
    public const VARIANTS = [
        1 => 'Парящие заголовки',
        2 => 'Стеклянные колонки',
        3 => 'Линия метро',
        4 => 'Капсулы',
        5 => 'Фото-карточки',
        6 => 'Тепловая карта',
        7 => 'Только текст',
        8 => 'Неон',
        9 => 'Стопки',
        10 => 'Дорожки по людям',
    ];

    /** Варианты с линией потока между этапами; остальные — без линий. */
    public const LINED = [2, 3, 4, 6, 8];

    public const PHASES = ['intake' => 'Заведение', 'sale' => 'Продажа', 'deal' => 'Сделка'];

    /** Этапы слева направо: глагол и фаза. */
    public const STAGES = [
        'letters' => ['Завести из писем', 'intake'],
        'floor' => ['Вписать закупочную', 'intake'],
        'price' => ['Назначить цену продажи', 'intake'],
        'estimate' => ['Оценить', 'intake'],
        'ready' => ['Выставить в продажу', 'sale'],
        'slots' => ['Опубликовать', 'sale'],
        'intake' => ['Собрать подтверждения', 'sale'],
        'bids' => ['Выбрать менеджера', 'sale'],
        'unsold' => ['Вернуть в продажу', 'sale'],
        'claims' => ['Запросить у страховой', 'sale'],
        'agree' => ['Согласовать с поставщиком', 'deal'],
        'requisites' => ['Получить реквизиты', 'deal'],
        'pay' => ['Получить оплату', 'deal'],
        'pickup' => ['Забрать ТС', 'deal'],
        'docs' => ['Оформить документы', 'deal'],
        'settle' => ['Рассчитаться с поставщиком', 'deal'],
    ];

    /** Блок маршрута сделки → этап (у вендоров пути разные, на доске — общий). */
    private const BLOCKS = [
        'Согласование с поставщиком' => 'agree',
        'Реквизиты для счёта' => 'requisites',
        'Оплата' => 'pay',
        'Получение автомобиля' => 'pickup',
        'Оформление документов' => 'docs',
        'Договор' => 'docs',
        'Расчёт с поставщиком' => 'settle',
    ];

    /** Связи доски: источники втекают в этапы, этапы — друг в друга слева направо. */
    public static function links(): array
    {
        $keys = array_keys(self::STAGES);
        $chain = array_map(fn ($a, $b) => [$a, $b, 'flow'], array_slice($keys, 0, -1), array_slice($keys, 1));

        return [
            ['src:mail', 'src:in', 'flow'], ['src:migtorg', 'src:in', 'flow'], ['src:park', 'src:in', 'flow'], ['src:gallery', 'src:in', 'flow'],
            ['src:in', $keys[0], 'flow'],
            ...$chain,
            ['settle', 'end:done', 'flow'],
        ];
    }

    /**
     * Доска для вывода: у этапа люди (модели подставлены, по убыванию задач, ответственный первым), задачи (горящие
     * первыми, дальше самые давние), всего, горящих; источники и выходы — числами.
     */
    public static function view(): array
    {
        $snap = Cache::remember(self::KEY, self::TTL, fn () => self::count());
        $faces = collect($snap['stages'])->flatMap(fn ($s) => [...$s['people'], ...array_filter(array_column($s['tasks'], 'who'))]);
        $users = User::with(User::withAvatar())->whereKey($faces->where('type', 'user')->pluck('id')->unique())->get()->keyBy('id');
        $vendorIds = $faces->where('type', 'vendor')->pluck('id')
            ->merge(collect($snap['stages'])->flatMap(fn ($s) => array_column($s['tasks'], 'vendor')))->filter()->unique();
        $vendors = Vendor::whereKey($vendorIds)->get()->keyBy('id');
        $hydrate = fn (?array $who) => $who === null ? null : [...$who, 'model' => match ($who['type']) {
            'user' => $users->get($who['id']), 'vendor' => $vendors->get($who['id']), default => null,
        }];

        $stages = collect(self::STAGES)->map(function (array $stage, string $key) use ($snap, $hydrate, $vendors) {
            [$title, $phase] = $stage;
            $raw = $snap['stages'][$key];
            $tasks = collect($raw['tasks'])->map(fn ($t) => [...$t, 'who' => $hydrate($t['who']), 'vendorModel' => $vendors->get($t['vendor'])])->values();
            $people = collect($raw['people'])->map($hydrate)
                ->filter(fn ($p) => ! in_array($p['type'], ['user', 'vendor'], true) || $p['model'])
                ->sortBy([fn ($a, $b) => ($b['lead'] ?? false) <=> ($a['lead'] ?? false), fn ($a, $b) => $b['n'] <=> $a['n']])->values();

            return [
                'key' => $key,
                'title' => $title,
                'phase' => $phase,
                'people' => $people,
                'tasks' => $tasks,
                'total' => $tasks->count(),
                'hot' => $tasks->whereNotNull('hot')->count(),
            ];
        })->all();

        return ['stages' => $stages, 'sources' => $snap['sources'], 'ends' => $snap['ends'], 'phases' => self::PHASES];
    }

    private static function count(): array
    {
        $carcade = OfferController::carcadeId();
        $offers = fn () => self::withoutCarcade(Offer::query()->notEmptyDraft(), $carcade);
        $tab = fn (string $preset) => OfferController::scopeFor($preset, $offers());
        $owner = fn (string $work) => ['type' => 'user', 'id' => Owners::of($work)];
        $robot = ['type' => 'robot'];
        $managers = ['type' => 'icon', 'icon' => 'users', 'label' => 'Менеджеры'];
        $today = today();

        $stages = [
            'claims' => self::offerTasks(self::claims(), fn () => $owner('claims')),
            'letters' => self::letters($owner('drafts')),
            'floor' => self::offerTasks($tab('nofloor'), fn () => $owner('floor')),
            'price' => self::offerTasks($tab('unpriced'), fn () => $owner('price')),
            'estimate' => self::estimates($offers, $owner('price')),
            'ready' => self::offerTasks($tab('priced'), fn () => $owner('price')),
            'slots' => self::offerTasks($tab('slots'), fn () => $robot, fn (Offer $o) => $o->slot_at ? 'выйдет '.$o->slot_at->format('d.m H:i') : null),
            'intake' => self::offerTasks($tab('published')->withCount(['bids as live_bids' => fn ($b) => $b->whereIn('state', ['active', 'accepted'])]), fn () => $managers,
                fn (Offer $o) => $o->bids_close_at ? 'приём до '.$o->bids_close_at->format('H:i') : null,
                fn (Offer $o) => $o->bids_close_at && ! $o->live_bids && $o->bids_close_at->isFuture() && $o->bids_close_at->diffInHours(now(), true) < 12
                    ? 'до '.$o->bids_close_at->format('H:i').', подтверждений нет' : null,
                fn (Offer $o) => $o->published_at),
            'bids' => self::offerTasks($tab('bids')->withCount(['bids as live_bids' => fn ($b) => $b->where('state', 'active')]), fn () => $owner('bids'),
                fn (Offer $o) => $o->live_bids.' '.\App\Support\Plural::of($o->live_bids, ['подтверждение', 'подтверждения', 'подтверждений']),
                at: fn (Offer $o) => $o->bids_close_at?->isPast() ? $o->bids_close_at : $o->published_at),
            'unsold' => self::offerTasks($tab('done')->where('offers.state', OfferState::Draft), fn () => $owner('price'),
                at: fn (Offer $o) => $o->intake_closed_at ?? $o->tab_at),
            ...self::deals($carcade, $owner),
        ];
        // Люди этапа — по задачам: кто держит и сколько, горящие у каждого.
        foreach ($stages as $key => $stage) {
            $people = [];
            foreach ($stage['tasks'] as $t) {
                if (! $t['who']) {
                    continue;
                }
                $id = $t['who']['type'].':'.($t['who']['id'] ?? $t['who']['icon'] ?? '');
                $people[$id] ??= [...$t['who'], 'n' => 0, 'late' => 0];
                $people[$id]['n']++;
                $people[$id]['late'] += $t['hot'] ? 1 : 0;
            }
            usort($stages[$key]['tasks'], fn ($a, $b) => [$b['hot'] !== null, $a['at']] <=> [$a['hot'] !== null, $b['at']]);
            $stages[$key]['people'] = array_values($people);
        }
        self::photos($stages);

        $born = fn () => $offers()->where('offers.created_at', '>=', $today);

        return [
            'stages' => $stages,
            'sources' => [
                'mail' => ['Почта', $born()->where('offers.created_via', 'mail')->count()],
                'migtorg' => ['Мигторг', $born()->where('offers.created_via', 'migtorg')->count()],
                'park' => ['Парковка', $born()->where('offers.created_via', 'park')->count()],
                'gallery' => ['Галерея', Interest::where('created_at', '>=', $today)->whereHas('offer', fn ($o) => $o->whereNotNull('gallery_from'))->count(), Offer::inGallery()->count()],
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
     * Задачи-предложения одного этапа: кто держит (`$who`), строка под названием (`$note`), горит ли (`$hot`, иначе —
     * только обязывающий Совкомбанка) и с какого времени ждёт (`$at`, иначе — когда попало во вкладку).
     */
    private static function offerTasks(Builder $q, \Closure $who, ?\Closure $note = null, ?\Closure $hot = null, ?\Closure $at = null): array
    {
        $q->getQuery()->columns ?? $q->select('offers.*');
        $rows = $q->selectRaw(Binding::panicSql().' as binding_hot')->get();

        return ['tasks' => $rows->map(fn (Offer $o) => [
            ...self::offerFields($o),
            'at' => ($at ? $at($o) : null)?->timestamp ?? $o->tab_at?->timestamp ?? $o->created_at->timestamp,
            'hot' => ($hot ? $hot($o) : null) ?? ($o->binding_hot ? 'обязывающий до '.$o->binding_until->format('d.m') : null),
            'note' => $note ? $note($o) : null,
            'who' => $who($o),
        ])->all()];
    }

    private static function offerFields(Offer $o): array
    {
        return ['k' => 'o:'.$o->id, 'offer' => $o->id, 'title' => $o->titleWithYear(), 'cat' => $o->category()->value, 'vendor' => $o->vendor_id, 'ref' => $o->claim_ref];
    }

    /** «К запросу» галереи: ждут письма поставщику (`ClaimRequest::pendingFor`) у вендоров с галереей. */
    private static function claims(): Builder
    {
        $vendors = Vendor::where('to_gallery', true)->get();

        return Offer::query()->where(function ($w) use ($vendors) {
            foreach ($vendors as $v) {
                $w->orWhereIn('offers.id', ClaimRequest::pendingFor($v)->select('offers.id'));
            }
            $vendors->isEmpty() && $w->whereRaw('false');
        });
    }

    /** «Из писем»: дела почты, которые надо завести (`Boxes::register`) — цепочка или одинокое письмо-заявка. */
    private static function letters(array $who): array
    {
        $rows = Thread::whereIn('account_id', Account::where('scope', Scope::Offers)->select('id'))
            ->where('messages_count', '>', 0)->whereNull('archived_at')
            ->where(fn ($w) => Boxes::register($w, Scope::Offers))
            ->selectRaw(Boxes::group(Scope::Offers).' as k, max(last_message_at) as at, max(candidate_id) as candidate_id, max(vendor_id) as vendor_id, max(subject) as subject')
            ->groupBy('k')->get();
        $candidates = Candidate::whereKey($rows->pluck('candidate_id')->filter())->get()->keyBy('id');

        return ['tasks' => $rows->map(function ($r) use ($candidates, $who) {
            $c = $r->candidate_id ? $candidates->get($r->candidate_id) : null;
            $at = Carbon::parse($r->at);

            return [
                'k' => 'm:'.$r->k, 'offer' => null, 'cat' => null,
                'title' => $c?->title() ?? ($r->subject ?: 'Письмо'),
                'vendor' => $r->vendor_id ? (int) $r->vendor_id : null,
                'ref' => $c?->value('claim_ref'),
                'at' => $at->timestamp,
                'hot' => null,
                'note' => null,
                'who' => $who,
            ];
        })->all()];
    }

    /** Оценка: ждут менеджера (просрочено — горит), оценили — решает Андрей «Взять». */
    private static function estimates(\Closure $offers, array $andrey): array
    {
        $waiting = Estimate::waiting()->whereIn('offer_id', $offers()->where('offers.state', OfferState::Draft)->select('offers.id'))
            ->with('offer')->get();
        $given = OfferController::scopeFor('estimating', $offers())->whereHas('estimates', fn ($e) => $e->needsDecision())->get();

        return ['tasks' => [
            ...$waiting->filter(fn (Estimate $e) => $e->offer)->map(fn (Estimate $e) => [
                ...self::offerFields($e->offer), 'k' => 'e:'.$e->id,
                'at' => $e->created_at->timestamp,
                'hot' => $e->isOverdue() ? 'просрочено '.Ago::short($e->due_at) : null,
                'note' => $e->due_at ? 'до '.$e->due_at->format('d.m H:i') : null,
                'who' => ['type' => 'user', 'id' => $e->user_id],
            ])->values()->all(),
            ...$given->map(fn (Offer $o) => [...self::offerFields($o), 'at' => $o->tab_at?->timestamp ?? $o->created_at->timestamp, 'hot' => null, 'note' => 'оценили, взять', 'who' => $andrey])->all(),
        ]];
    }

    /**
     * Идущие сделки по этапам: у каждой один держатель — чей ход. Наш ход — по делу (`Position::todo`): переоценка и
     * письма Альфы — Андрею, остальное — Кате; ход менеджера — его аватар; поставщика — логотип вендора; везёт логистика —
     * кто ведёт вывоз сейчас (назначить — Катя, логист, документы СДЭКом — Лиза, исполнитель). Вывоз без сделки — тоже
     * задача «Забрать ТС».
     */
    private static function deals(?int $carcade, \Closure $owner): array
    {
        $alfa = Vendor::where('name', 'ilike', '%альфа%')->pluck('id')->all();
        $deals = Deal::where('state', DealState::Active)->whereNull('auction_at')->whereNull('garage_payer')
            ->whereHas('offer', fn ($o) => self::withoutCarcade($o, $carcade))
            ->with(['offer', 'offer.vendor', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice'])->get();
        $transports = Transport::query()->open()->inLogistics()
            ->when($carcade, fn ($q) => $q->whereNotIn('transports.car_id', Offer::where('vendor_id', $carcade)->select('car_id')))
            ->with('car')->get();
        $byCar = $transports->keyBy('car_id');
        $ours = Position::query()->needsUs()->where('track', 'sale')->whereIn('offer_id', $deals->pluck('offer_id'))->pluck('offer_id')->flip();
        $hold = fn (Transport $t) => match (true) {
            $t->state === TransportState::New => $owner('pickup'),
            $t->state === TransportState::Logist && $t->logist_id => ['type' => 'user', 'id' => $t->logist_id],
            $t->docs_by_cdek && $t->executor_id && ! $t->docs_sent_at => $owner('docs'),
            (bool) $t->executor_id => ['type' => 'user', 'id' => $t->executor_id],
            default => ['type' => 'icon', 'icon' => 'cat-truck', 'label' => 'Вывоз'],
        };
        $late = fn (Transport $t) => match (true) {
            $t->isLate() => 'опаздывает',
            in_array($t->state, [TransportState::Logist, TransportState::Assigned], true) && $t->assigned_at?->lt(now()->subDay()) => 'стоит '.Ago::short($t->assigned_at),
            default => null,
        };

        $out = array_fill_keys(['agree', 'requisites', 'pay', 'pickup', 'docs', 'settle'], ['tasks' => []]);
        $carried = [];
        foreach ($deals as $deal) {
            $position = $deal->offer?->position();
            $block = $position?->stage?->block?->name;
            $node = $block !== null ? self::BLOCKS[$block] ?? null : null;
            if (! $node) {
                continue;
            }
            $position->setRelation('deal', $deal);
            $todo = isset($ours[$deal->offer_id]) ? $position->todo() : null;
            $transport = $byCar->get($deal->offer->car_id);
            $transport && $carried[$transport->id] = true;
            $who = match (true) {
                (bool) $todo => ['type' => 'user', 'id' => Owners::forTodo($todo['key'], in_array($deal->offer->vendor_id, $alfa, true))],
                default => match ($position->waitsFor()) {
                    WaitsFor::Manager => $deal->buyer_id ? ['type' => 'user', 'id' => $deal->buyer_id] : null,
                    WaitsFor::Supplier => $deal->offer->vendor_id ? ['type' => 'vendor', 'id' => $deal->offer->vendor_id] : null,
                    WaitsFor::Us => $transport ? $hold($transport) : ['type' => 'icon', 'icon' => 'cat-truck', 'label' => 'Вывоз'],
                    default => ['type' => 'icon', 'icon' => 'clock', 'label' => 'Ждём'],
                },
            };
            $hot = match (true) {
                $position->priceAskLate() !== null => 'менеджер не ответил',
                $position->isOverdue() => 'просрочено '.Ago::short($position->deadline_at),
                Binding::panics($deal->offer) => 'обязывающий до '.$deal->offer->binding_until?->format('d.m'),
                $transport !== null => $late($transport),
                default => null,
            };
            $out[$node]['tasks'][] = [
                ...self::offerFields($deal->offer), 'k' => 'd:'.$deal->id,
                'at' => ($position->block_entered_at ?? $position->entered_at)?->timestamp ?? $deal->created_at->timestamp,
                'hot' => $hot,
                'note' => $todo['title'] ?? ($position->deadline_at && ! $hot ? 'срок '.$position->deadline_at->format('d.m') : null),
                'who' => $who,
            ];
        }
        foreach ($transports as $t) {
            if (isset($carried[$t->id])) {
                continue;
            }
            $out['pickup']['tasks'][] = [
                'k' => 't:'.$t->id, 'offer' => null, 'title' => $t->car?->titleWithYear() ?: 'ТС', 'cat' => $t->car?->category?->value, 'vendor' => null, 'ref' => $t->car?->claim_ref,
                'at' => ($t->assigned_at ?? $t->created_at)->timestamp,
                'hot' => $late($t),
                'note' => 'вывоз',
                'who' => $hold($t),
            ];
        }

        return $out;
    }

    /** Кадр машины — только у задач, что рисуются карточками (вариант с фото). */
    private static function photos(array &$stages): void
    {
        $ids = collect($stages)->flatMap(fn ($s) => array_column(array_slice($s['tasks'], 0, self::SHOWN), 'offer'))->filter()->unique();
        $offers = Offer::whereKey($ids)->with('media')->get()->keyBy('id');
        foreach ($stages as $key => $stage) {
            foreach (array_slice($stage['tasks'], 0, self::SHOWN) as $i => $t) {
                $media = $t['offer'] ? $offers->get($t['offer'])?->mainPhoto() : null;
                $stages[$key]['tasks'][$i]['photo'] = $media ? \App\Media\MediaUrl::for($media, 'thumb') : null;
            }
        }
    }

    private static function withoutCarcade($q, ?int $carcade, string $column = 'offers.vendor_id')
    {
        return $q->when($carcade, fn ($q) => $q->where(fn ($v) => $v->whereNull($column)->orWhere($column, '!=', $carcade)));
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /** Давность словом для карточки: «3 д». */
    public static function age(int $at): string
    {
        return Ago::short(Carbon::createFromTimestamp($at));
    }

    /** @return Collection<int, array> */
    public static function shown(array $stage): Collection
    {
        return $stage['tasks']->take(self::SHOWN);
    }
}
