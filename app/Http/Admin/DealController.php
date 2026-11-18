<?php

namespace App\Http\Admin;

use App\Billing\DealDebt;
use App\Live\Rows;
use App\Offers\Actions\AttachSignedContract;
use App\Offers\Actions\ChangeDealScheme;
use App\Offers\Actions\DeclineBySupplier;
use App\Offers\Actions\MarkContractSigned;
use App\Offers\Actions\MarkSoldDeal;
use App\Offers\Actions\ReopenDeal;
use App\Offers\Actions\SetDealDate;
use App\Offers\Actions\SetReceiver;
use App\Offers\Actions\UpdateDealMoney;
use App\Offers\Binding;
use App\Offers\CarWindow;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\DealScheme;
use App\Offers\DealTabs;
use App\Offers\OfferFilters;
use App\Offers\Giving;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    // «Ваш ход» вместо «Горит» (27.10.2026, владелец: рыжее только когда от нас что-то нужно; чужая просрочка — не наш ход).
    // С 16.11.2026 сделка — состояние предложения: вкладки сделки живут в «Предложениях» (`OfferController::DEAL_TABS`), этот
    // контроллер рисует их строки (`tab`), прежний адрес `/work/deals` отвечает 301 (`index`).
    public const SORTS = ['deadline' => ['Срок', 'asc'], 'binding' => ['Срок обязывающего', 'asc'], 'fresh' => ['Дата', 'desc'], 'amount' => ['Сумма', 'desc']];

    /**
     * Прежние «Сделки» (`/work/deals`) — 301 в вкладки «Предложений»: «Ваш ход» с делами, «Ждём менеджера / поставщика» —
     * «Согласование» с чипом «Чей ход», «Оплата», «Все» — «Все» предложений, «Закончены» — «Закрыты»; `?peek=` сделки —
     * номером машины.
     */
    public function index(Request $request)
    {
        $preset = (string) $request->query('preset', 'us');
        $to = match (true) {
            $preset === 'pay' => 'paying',
            in_array($preset, ['all', 'active'], true) => 'all',
            $preset === 'done' => 'finished',
            // Дела прежнего «Ваш ход» — в свои этапы (16.11.2026).
            default => match ((string) $request->query('todo')) {
                'reply' => 'answered', 'write', 'request' => 'letter', 'reprice' => 'reprice', 'invoice' => 'bill', default => 'agree',
            },
        };
        $to = ['preset' => $to];
        $keep = array_intersect_key($request->query(), array_flip(['q', 'manager', 'vendor']));
        if (($peek = $request->query('peek')) && ($deal = Deal::with('offer')->find($peek))) {
            $keep['peek'] = $deal->offer?->number;
        }

        return redirect('/offers?'.http_build_query($to + $keep), 301);
    }

    /**
     * Окно строки вкладки сделки: ключ — номер машины. Модератору — то же окно машины (17.11.2026, владелец: «модераторам полный
     * доступ ко всем сделкам»; до того — урезанное окно документов `admin.deals.docs-window`).
     */
    private static function window(Request $request, string $key): mixed
    {
        return CarWindow::of($request, OfferNumber::find($key));
    }

    public function tab(Request $request, string $preset)
    {
        // Окно машины — одно на все списки (`CarWindow`); модератору (14.11.2026, `canEditDeals`) — окно документов.
        $detail = Detail::of($request, fn (string $key) => self::window($request, $key));
        if ($detail->framed()) {
            return $detail->response();
        }
        $stage = "(select left(md5(coalesce(b.name, s.name)), 8) from offer_positions p join workflow_stages s on s.id = p.stage_id
            left join workflow_blocks b on b.id = s.block_id where p.offer_id = deals.offer_id and p.track = 'sale' order by p.id limit 1)";
        $staged = isset(DealTabs::TABS[$preset]);
        // Шапка вкладки (17.11.2026): под кнопками — «Горит срок обязывающего»; остальное и параметры машины, денег и сроков
        // (`OfferFilters`) — в окне «Фильтр»; «Во вкладке» — вход в блок шага продажи (вкладка сделки — блок).
        $since = "(select coalesce(p.block_entered_at, p.entered_at) from offer_positions p where p.offer_id = deals.offer_id and p.track = 'sale' limit 1)";
        $facets = Facets::for($request, 'crm-deals', ...[
            Common::manager('deals.buyer_id'),
            Common::vendor('(select vendor_id from offers where offers.id = deals.offer_id)'),
            // Этап — блок маршрута по имени (как в строке): у разных вендоров одноимённые блоки — один вариант.
            Facet::column('stage', 'Этап', ['этап', 'этапа', 'этапов'], $stage)->icon('route')->labels(fn (array $keys) => DB::table('workflow_stages as s')
                ->leftJoin('workflow_blocks as b', 'b.id', '=', 's.block_id')
                ->selectRaw('distinct left(md5(coalesce(b.name, s.name)), 8) as k, coalesce(b.name, s.name) as name')
                ->whereRaw('left(md5(coalesce(b.name, s.name)), 8) in ('.implode(',', array_fill(0, max(count($keys), 1), '?')).')', $keys ?: [''])
                ->get()->mapWithKeys(fn ($r) => [$r->k => new Option($r->k, $r->name)])->all()),
            // Срок — шаг сделки просрочен или нет; у закрытых сделок срока нет — «в срок».
            Facet::column('due', 'Срок', ['срок', 'срока', 'сроков'], '(case when '.Deal::ACTIVE_SQL." and exists (select 1 from offer_positions p
                where p.offer_id = deals.offer_id and p.track = 'sale' and p.deadline_at < now()) then 'late' else 'ok' end)")
                ->labels(fn (array $keys) => array_intersect_key(['late' => new Option('late', 'Просрочено'), 'ok' => new Option('ok', 'В срок')], array_flip($keys)))->icon('clock'),
            // Срок обязывающего горит, а поставщику не заплачено (19.10.2026, `Offers\Binding`).
            Facet::toggle('binding', 'Горит срок обязывающего', fn ($q) => $q->whereHas('offer', fn ($o) => Binding::scope($o, 'deal')))->dot('danger')->quick()->icon('fire'),
            Facet::column('garage', 'Гараж', ['вид', 'вида', 'видов'], "(case when deals.garage_payer is null then 'deal' else 'garage' end)")->icon('warehouse')
                ->labels(fn (array $keys) => array_intersect_key(['deal' => new Option('deal', 'Без гаража'), 'garage' => new Option('garage', 'Гаражные')], array_flip($keys))),
            ...OfferFilters::for('deals.offer_id', $request->user()->canManageCrm(), $since),
        ])->saving('offers');
        $askedSort = $request->query('sort');
        // «Чей ход» не запоминается: вкладки прежде были вкладками, не фильтром — в соседней вкладке он путал бы.
        ListPrefs::sync($request, 'crm-deals', keep: array_values(array_diff($facets->keys(), OfferController::ONCE)));
        OfferController::forgetBindingSort($request, $askedSort);
        $searching = $facets->searching();
        $sort = Sort::from($request->query('sort'), self::SORTS, 'deadline');

        // Гаражные — тоже сделки (27.10.2026, владелец: «в сделках их стоит отображать»; с 13.10 их здесь не было).
        $q = Deal::query()->with([...DealTabs::WITH, ...User::withAvatar('buyer.media')])->withCount('parallels')
            // Параллельные (07.11.2026) — не свои строки: они в сделке своей машины, у строки метка «+N параллельно».
            ->where(fn ($w) => $w->where('deals.parallel', false)->orWhereNotNull('deals.closed_at'));
        if ($searching) {
            // Лупа — по всем сделкам: номер предложения, VIN, марка, убыток, менеджер.
            $term = trim((string) $request->query('q'));
            $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->searchCrm($term))
                ->orWhereHas('buyer', fn ($u) => $u->search($term)));
        } else {
            self::scope($q, $preset);
        }
        $facets->apply($q);
        $dir = $sort->dir();
        match ($sort->key) {
            'fresh' => $q->orderBy('created_at', $dir),
            'amount' => $q->orderByRaw("amount {$dir} nulls last"),
            'binding' => $q->orderByRaw("(select binding_until from offers where offers.id = deals.offer_id) {$dir} nulls last"),
            // По сроку — сначала ответ поставщика (как непрочитанное письмо), потом прочий наш ход, потом остальное.
            default => $q->orderByRaw(self::turnRank())->orderByRaw("(select min(deadline_at) from offer_positions where offer_positions.offer_id = deals.offer_id) {$dir} nulls last"),
        };
        $q->orderByDesc('deals.id');
        // Группы собираются в памяти по всему списку, до сужения строк живого обновления (`Rows::only`): числа вкладок дел
        // клиент берёт из того же ответа (`data-pill-count`), а строки рисуются только запрошенные. Ключ строки — номер машины.
        $whole = ! $searching && $staged ? (clone $q)->get() : null;
        Rows::only($q, fn ($q, array $keys) => $q->whereHas('offer', fn ($o) => $o->whereIn('offers.number', $keys)));
        $live = Rows::keys();
        // Вкладка этапа (16.11.2026, владелец: «сначала наше дело, потом кого ждём»): дела зелёными группами по тому, что сделать,
        // дальше «Ждём менеджера», «Ждём поставщика». Числа групп — по всему списку, строки живого обновления — только запрошенные.
        $groups = $whole !== null ? self::stageGroups($whole, $live) : null;
        // «Менеджер должен нам» — сверху итог: кто сколько должен нам (27.10.2026, `DealDebt`).
        $debts = $whole !== null && $preset === 'paying' ? self::debts($whole) : null;
        $table = ListView::isTable(ListView::pick($request, 0));
        $deals = match (true) {
            $whole !== null => new LengthAwarePaginator([], $whole->count(), 1),
            $table => ListView::paginate($request, $q),
            default => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
        };
        $user = $request->user();

        return view('admin.deals.index', [
            'groups' => $groups,
            'debts' => $debts,
            'deals' => $deals,
            'preset' => $preset,
            // Колесо — вкладки «Предложений» с разделами (16.11.2026).
            'presets' => OfferController::presets($user),
            'sections' => OfferController::sections($user),
            'counts' => OfferController::counts($user),
            'sort' => $sort,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    /**
     * Группы вкладки этапа: наши дела по тому, что сделать (`Position::todo`, заголовок — дело), дальше кого ждём. Внутри — кто
     * дольше ждёт. Кнопки «Отправить заявку» у группы заявки поставщику нет (16.11.2026, владелец: «не нужна эта кнопка вообще»).
     *
     * @return Collection<string, array{title: string, ours: bool, size: int, rows: Collection}>
     */
    private static function stageGroups(Collection $deals, ?array $live): Collection
    {
        $rows = DealTabs::rows($deals)->values();
        $wait = DealTabs::WAITS;
        $groups = $rows->groupBy(fn (array $r) => $r['todo'] ? 'do:'.$r['todo']['key'] : 'wait:'.DealTabs::waits($r['deal']))
            ->map(function (Collection $rows, string $key) use ($wait) {
                $first = $rows->first();
                $ours = str_starts_with($key, 'do:');
                $since = fn (array $r) => ($r['todo']['since'] ?? $r['deal']->offer?->position()?->entered_at)?->getTimestamp() ?? PHP_INT_MAX;

                return [
                    'title' => $ours ? (DealTabs::TODO_TITLES[$first['todo']['title']] ?? $first['todo']['title']) : DealTabs::waitTitle(substr($key, 5)),
                    'ours' => $ours, 'rank' => $ours ? $first['todo']['rank'] : 1000 + array_search(strtok(substr($key, 5), ':'), array_keys($wait), true),
                    // Письмо шага согласования не ушло — группа красным (17.11.2026, владелец).
                    // Данные паспорта не распознались и договор не отправлен при оплаченном счёте — тоже (18.11.2026).
                    'danger' => str_starts_with($key, 'do:letter-agree:') || in_array($key, ['do:gap:unread', 'do:contract:unread', 'do:contract:paid'], true),
                    'size' => $rows->count(),
                    'rows' => $rows->sortBy($since)->map(fn (array $r) => ['deal' => $r['deal'], 'todo' => $r['todo'] ?? ['key' => '', 'title' => '', 'rank' => 0, 'since' => null, 'button' => null]])->values(),
                ];
            })->sortBy([['rank', 'asc'], ['title', 'asc']]);

        return $live === null ? $groups : $groups->map(fn (array $g) => [...$g, 'rows' => $g['rows']->filter(fn ($r) => in_array((string) $r['deal']->offer?->number, $live, true))->values()])
            ->filter(fn (array $g) => $g['rows']->isNotEmpty());
    }

    /**
     * «Оплата» — кто сколько должен (27.10.2026): группы по менеджеру (аукцион — своей группой), у группы сумма и просрочено;
     * первыми — у кого больше просрочено, внутри — просроченные, дальше по сроку. Весь список одной страницей.
     *
     * @return array{owed: float, overdue: float, groups: Collection<string, array>}
     */
    private static function debts(Collection $deals, bool $all = false): array
    {
        // Вкладка «Оплата» (`all`, 16.11.2026) — и те, у кого долга нет: счёта ещё нет или оплачено, ждём поставщика.
        $rows = $deals->map(fn (Deal $deal) => ['deal' => $deal, 'debt' => DealDebt::of($deal)])->filter(fn ($r) => $all || ($r['debt'] && ! $r['debt']->paid()));
        $groups = $rows->groupBy(fn ($r) => $r['deal']->isAuction() ? 'auction' : 'manager:'.$r['deal']->buyer_id)->map(fn (Collection $rows) => [
            'title' => $rows->first()['deal']->isAuction() ? 'Аукцион' : ($rows->first()['deal']->buyer?->name ?? 'Без менеджера'),
            'owed' => $rows->sum(fn ($r) => $r['debt']?->owed ?? 0),
            'overdue' => $rows->sum(fn ($r) => $r['debt']?->overdue ?? 0),
            'rows' => $rows->sortBy(fn ($r) => [$r['debt']?->late() ? 0 : 1, $r['debt']?->due?->getTimestamp() ?? PHP_INT_MAX])->values(),
        ])->sortBy([['overdue', 'desc'], ['owed', 'desc']]);

        return ['owed' => $groups->sum('owed'), 'overdue' => $groups->sum('overdue'), 'groups' => $groups];
    }

    /**
     * Столбцы вкладок сделки — одно описание для шапки, строк и групп (`Support\Table`). Этап — дела и ожидание
     * (`x-deal.todo-row`): что сделать или кого ждём — заголовок группы, у строки — сколько ждёт и кнопка дела. «Завершённые» и
     * лупа — таблица с этапом, оплатой и суммой (`x-deal.table-row`).
     */
    public static function table(bool $staged = false, bool $actions = true): Table
    {
        $title = new Column('title', 'Марка, модель', grow: true);
        // Ширина у каждого столбца (29.10.2026): без неё `x-ui.table :rest` гадали числом в виде, и название то резалось, то
        // оставляло дыру.
        $vendor = new Column('vendor', '№ убытка', from: 'sm', role: ColRole::Code, rem: 10);
        $manager = new Column('manager', 'Менеджер', from: 'sm', role: ColRole::Who, rem: 9);

        return $staged
            // Кнопка дела — самой правой, за сроком «Ждёт».
            ? Table::of(...array_filter([$title, $vendor, $manager, new Column('wait', 'Ждёт', from: 'sm', role: ColRole::When, rem: 8), $actions ? new Column('action', role: ColRole::When, rem: 9) : null]))
            : new Table([$title, $vendor, new Column('number', '№', from: '2xl', class: 'nums', role: ColRole::Code, rem: 7), $manager,
                new Column('stage', 'Этап', from: 'sm', role: ColRole::State, rem: 12),
                new Column('term', 'Что сейчас', from: 'sm', sort: 'deadline', role: ColRole::When, rem: 9),
                new Column('pay', 'Оплата', from: 'sm', num: true, class: 'nums', rem: 7),
                new Column('amount', 'Сумма', num: true, sort: 'amount', class: 'nums', rem: 7)]);
    }

    /** 0 — поставщик ответил, 1 — прочий наш ход, 2 — ждём других. */
    private static function turnRank(): string
    {
        $sale = "select 1 from offer_positions where offer_positions.offer_id = deals.offer_id and offer_positions.track = 'sale'";

        return "(case when exists ({$sale} and offer_positions.payload ?? 'reply_at') then 0
            when exists ({$sale} and ".Position::NEEDS_US_SQL.') then 1 else 2 end)';
    }

    /**
     * Пилюля списка: «Ваш ход» — нужно наше действие (`Position::needsUs`; им же число у заголовка «Сделки», `Nav::staffCounts`),
     * «Ждём менеджера» / «Ждём поставщика» — их ход, сколько бы ни было просрочено.
     */
    private static function scope($q, string $preset)
    {
        return match ($preset) {
            // «Завершённые» — выданные (16.11.2026): продажа на аукционе, «Сделка закрыта»; и гаражные с закрытым расчётом — машина
            // остаётся «В гараже», а не «Выдан» (18.11.2026: 2610272959 числом в колесе была, а строкой — ни в одной вкладке; условие
            // колеса — `OfferController::scopeFor('finished')`). Сделка гаражной — любая не отменённая.
            'finished' => $q->where(fn ($w) => $w->where(fn ($d) => $d->done()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Delivered)))
                ->orWhere(fn ($g) => $g->whereNull('deals.cancelled_at')->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Garage)
                    ->whereHas('garageCar', fn ($c) => $c->where('state', \App\Garage\CarState::Settled))))),
            // Этап маршрута — раскладка `DealTabs`.
            default => $q->active()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Sold)->whereIn('offers.id', DealTabs::ids($preset))),
        };
    }

    /**
     * Сделка целиком — это редактор предложения (06.10.2026: одна страница, три дорожки — «Продажа», «Вывоз», «Сделка»
     * с деньгами, ДКП и заметкой). Старые ссылки и уведомления ведут сюда — отсюда туда.
     */
    public function show(Request $request, Deal $deal)
    {
        return redirect('/offers/'.$deal->offer->number.($deal->isGarage() ? '#garage' : ''));
    }

    /** Карточка чата сделки — фрейм перечитывает её по новому сообщению (`x-deal.chat-card`). */
    public function chat(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer, 404);

        return Blade::render('<x-deal.chat-card :deal="$deal" :compact="$compact" :inline="$inline"/>', ['deal' => $deal, 'compact' => $request->boolean('compact'), 'inline' => $request->boolean('inline')]);
    }

    /** Деньги сделки — пока по ней нет счёта или есть только неоплаченный подбор по ДКП (`Deal::moneyEditable`). */
    public function money(Request $request, Deal $deal, UpdateDealMoney $update)
    {
        // Услуги подбора из ползунка вознаграждения (16.11.2026): ноль — вознаграждение съело всю разницу, это ошибка, а не «стереть».
        $request->merge(['share' => blank($request->input('share')) ? null : Money::rubles($request->input('share'))]);
        $data = $request->validate(['commission' => ['nullable', 'integer', 'min:0'],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            'share' => ['nullable', 'integer', 'min:1']], ['share.min' => Giving::SHARE_LEFT]);
        // Поле вознаграждения пустое — вознаграждения нет; поля нет вовсе (гаражная доля) — прежнее.
        $commission = $request->has('commission') ? (isset($data['commission']) ? (int) $data['commission'] : null) : $deal->commission;
        $scheme = DealScheme::tryFrom($data['scheme'] ?? '');
        // Схема не из этого вендора (`DealScheme::casesFor`), как в «Принять», — по умолчанию предложения.
        if ($scheme && ! in_array($scheme, DealScheme::casesFor($deal->offer->vendor, $deal->offer), true)) {
            $scheme = DealScheme::forOffer($deal->offer);
        }
        $update($deal, $request->user(), $commission, $scheme, isset($data['share']) ? (int) $data['share'] : null);

        return back()->with('toast', 'Сохранено');
    }

    /** «Схема» строкой в окошке и в сделке (03.11.2026): сменить — деньги, счета и шаг реквизитов встают по новой. */
    public function scheme(Request $request, Deal $deal, ChangeDealScheme $change)
    {
        abort_unless($deal->isActive(), 404);
        $scheme = DealScheme::from($request->validate(['scheme' => ['required', Rule::enum(DealScheme::class)]])['scheme']);
        $change($deal, $scheme, $request->user(), $request->boolean('scheme_ok'));

        return back()->with('toast', 'Схема: '.$scheme->label());
    }

    /** Кто заберёт с нашей парковки — админ за менеджера (`SetReceiver`). */
    public function receiver(Request $request, Deal $deal, SetReceiver $set)
    {
        $deal = $set($deal, $request->user(), $request->validate(['who' => ['required', 'string', 'max:20'], 'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'], 'at' => ['nullable', 'date']]));

        return back()->with('toast', 'Заберёт '.$deal->receiver()?->labelFor(null));
    }

    /** «Поставщик отказал…» (28.10.2026): сделка закрыта по причине, предложение в архиве (`DeclineBySupplier`). */
    public function declined(Request $request, Deal $deal, DeclineBySupplier $decline)
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500'], 'pickup' => ['nullable', Rule::in(['keep', 'drop'])]], [], ['note' => 'Почему']);
        $decline($deal, $data['note'], $request->user(), dropPickup: ($data['pickup'] ?? 'keep') === 'drop');

        return back()->with('toast', 'Сделка закрыта: отказ поставщика');
    }

    /** «Уже продана…» (14.11.2026): шаги до закрытия или до неподписанного договора — одним нажатием (`MarkSoldDeal`). */
    public function sold(Request $request, Deal $deal, MarkSoldDeal $mark)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        // Про деньги — только явным ответом (14.11.2026, 666916: «Да» по умолчанию подтверждали не глядя).
        $asksMoney = MarkSoldDeal::unpaid($deal)->isNotEmpty() || ! $deal->agreed();
        $data = $request->validate(['signed' => ['required', Rule::in(['yes', 'no'])],
            'manager_paid' => [$asksMoney ? 'required' : 'nullable', Rule::in(['yes', 'no'])],
            'supplier_paid' => [MarkSoldDeal::asksSupplier($deal) ? 'required' : 'nullable', Rule::in(['yes', 'no'])],
            'sold_on' => ['required', 'date', 'before_or_equal:today']],
            ['manager_paid.required' => 'Ответьте: менеджер заплатил?', 'supplier_paid.required' => 'Ответьте: мы заплатили поставщику?'], ['sold_on' => 'когда продали']);
        $deal = $mark($deal, $request->user(), $data['signed'] === 'yes', isset($data['manager_paid']) ? $data['manager_paid'] === 'yes' : null,
            isset($data['supplier_paid']) ? $data['supplier_paid'] === 'yes' : null, Carbon::parse($data['sold_on'], 'Europe/Moscow'));

        return back()->with('toast', $deal->isActive() ? 'Встала на «'.$deal->offer->stage()?->name.'»' : 'Сделка закрыта');
    }

    /** «Продано» / «Оплачено» нажатием во «Все» (14.11.2026, `SetDealDate`): пустое — очистить. */
    public function dates(Request $request, Deal $deal, SetDealDate $set)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $data = $request->validate(['what' => ['required', Rule::in([DealDates::SOLD, DealDates::PAID])],
            'at' => ['nullable', 'date', 'before_or_equal:today']]);
        // «Очистить» в шторке — та же форма с `clear`: дата в поле остаётся, но снимается.
        if ($request->boolean('clear')) {
            $data['at'] = null;
        }
        $deal = $set($deal, $data['what'], filled($data['at'] ?? null) ? Carbon::parse($data['at'], 'Europe/Moscow') : null, $request->user());

        return back()->with('toast', ($data['what'] === 'sold' ? 'Продано' : 'Оплачено').': '.(filled($data['at'] ?? null) ? Carbon::parse($data['at'])->format('d.m.Y') : 'снято')
            .($deal->isActive() && $data['what'] === 'paid' && blank($data['at'] ?? null) ? ', сделка снова в работе' : ''));
    }

    /** «Вернуть сделку в работу…» (14.11.2026, `ReopenDeal`). */
    public function reopen(Request $request, Deal $deal, ReopenDeal $reopen)
    {
        abort_unless($request->user()->canManageCrm(), 403);
        $data = $request->validate(['stage_id' => ['required', 'integer'], 'undo_sold' => ['nullable', 'boolean']]);
        $stage = Stage::findOrFail($data['stage_id']);
        $reopen($deal, $stage, $request->user(), $request->boolean('undo_sold', true));

        return back()->with('toast', 'Сделка снова в работе: «'.$stage->name.'»');
    }

    /** «Договор уже подписан» (14.11.2026): покупателя и его данных не просим, шаги договора — пройдены (`MarkContractSigned`). */
    public function contractSigned(Request $request, Deal $deal, MarkContractSigned $mark)
    {
        abort_unless($request->user()->canEditDeals(), 403);
        $deal = $mark($deal, $request->user());
        $stage = $deal->offer->stage();

        return back()->with('toast', $stage?->aboutContract() || $stage?->asks === Asks::Buyer
            ? 'Договор подписан. Закрыть сделку — «Уже продана…»' : 'Договор подписан, сделка на «'.$stage?->name.'»');
    }

    /**
     * Ответ поставщика списком (09.10.2026): каждой сделке — её исход «Поставщик согласовал…» (своей ветки) или
     * «Поставщик отказал». Без ответа — ждёт дальше; уже ушедшую с этапа пропускаем.
     */
    public function supplierAnswer(Request $request, TakeExit $take)
    {
        $answers = collect($request->validate(['answers' => ['array'], 'answers.*' => ['nullable', Rule::in(['agreed', 'declined'])]])['answers'] ?? [])->filter();
        $done = ['agreed' => 0, 'declined' => 0];
        $deals = Deal::whereKey($answers->keys())->active()->with('offer.positions.stage.exits.to')->get();
        foreach ($deals as $deal) {
            $prefix = $answers[$deal->id] === 'agreed' ? 'поставщик согласовал' : 'поставщик отказал';
            $exit = $deal->offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $e) => str_starts_with(mb_strtolower($e->label), $prefix));
            if ($exit) {
                $take($deal->offer, $exit, Actor::Staff, $request->user());
                $done[$answers[$deal->id]]++;
            }
        }
        $toast = collect(['отдаёт '.$done['agreed'] => $done['agreed'], 'отказал '.$done['declined'] => $done['declined']])->filter()->keys()->implode(', ');

        // Строки остальных сделок списка — у автора тоже (своё событие `rows` он пропускает): поток `rows` ответа карточки.
        return back(fallback: '/offers?preset=agree')->with('toast', $toast ? 'Отмечено: '.$toast : 'Ничего не отмечено')
            ->with('live-rows', $deals->map(fn (Deal $d) => $d->offer->number)->all());
    }

    public function note(Request $request, Deal $deal)
    {
        $deal->update($request->validate(['notes' => ['nullable', 'string', 'max:5000']]));

        return back()->with('toast', 'Заметка сохранена');
    }

    /**
     * Подписанный договор, сделанный вне системы (15.11.2026, `AttachSignedContract`): приложить на любом шаге, без
     * покупателя; файлов можно несколько (страницы фотографиями).
     */
    public function signed(Request $request, Deal $deal, AttachSignedContract $attach)
    {
        $request->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => ['file', 'max:30720']],
            ['files.required' => 'Выберите файл договора', 'files.*.max' => 'Файл больше 30 МБ']);
        foreach ($request->file('files') as $file) {
            $attach($deal, $file, $request->user());
        }

        return back()->with('toast', 'Подписанный договор приложен');
    }

    /** Подписанный договор одним PDF — файлы сделки и ответы шага договора (`SignedContractPdf`, 17.11.2026). */
    public function signedPdf(Deal $deal)
    {
        $pdf = \App\Offers\Documents\SignedContractPdf::render($deal) ?? abort(404);

        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename*=UTF-8\'\''.rawurlencode(\App\Offers\Documents\SignedContractPdf::fileName($deal))]);
    }

    public function removeSigned(Request $request, Deal $deal, Media $media)
    {
        abort_unless(AttachSignedContract::allows($deal), 404);
        AttachSignedContract::remove($deal, $media, $request->user());

        return back()->with('toast', 'Файл договора убран');
    }
}
