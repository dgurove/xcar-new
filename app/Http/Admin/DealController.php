<?php

namespace App\Http\Admin;

use App\Billing\DealDebt;
use App\Live\Rows;
use App\Offers\Actions\DeclineBySupplier;
use App\Offers\Actions\SetReceiver;
use App\Offers\Actions\UpdateDealMoney;
use App\Offers\Binding;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\OfferFiles;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Sort;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DealController
{
    // «Ваш ход» вместо «Горит» (27.10.2026, владелец: рыжее только когда от нас что-то нужно; чужая просрочка — не наш ход).
    // «Все» вместо «В работе» (27.10.2026, владелец: «в работе то, что есть в других вкладках, — бред», «очень не хватает
    // „Все“»): все идущие, группами по этапу. Гаражные — тоже сделки, скрываются чипом «Гараж».
    // «Оплата» (27.10.2026, владелец: «не понимаю, кто сколько должен заплатить и когда») — кто сколько должен нам: группы
    // по менеджеру с суммой и просрочкой (`DealController::debts`, `Billing\DealDebt`).
    public const PRESETS = ['us' => 'Ваш ход', 'manager' => 'Ждём менеджера', 'supplier' => 'Ждём поставщика', 'pay' => 'Оплата', 'all' => 'Все', 'done' => 'Закончены'];

    public const SORTS = ['deadline' => ['Срок', 'asc'], 'binding' => ['Срок обязывающего', 'asc'], 'fresh' => ['Дата', 'desc'], 'amount' => ['Сумма', 'desc']];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($deal = Deal::find($key)) ? $this->detail($deal) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $stage = "(select left(md5(coalesce(b.name, s.name)), 8) from offer_positions p join workflow_stages s on s.id = p.stage_id
            left join workflow_blocks b on b.id = s.block_id where p.offer_id = deals.offer_id and p.track = 'sale' order by p.id limit 1)";
        $facets = Facets::for($request, 'crm-deals',
            Common::manager('deals.buyer_id'),
            Common::vendor('(select vendor_id from offers where offers.id = deals.offer_id)'),
            // Этап — блок маршрута по имени (как в строке): у разных вендоров одноимённые блоки — один вариант.
            Facet::column('stage', 'Этап', ['этап', 'этапа', 'этапов'], $stage)->labels(fn (array $keys) => DB::table('workflow_stages as s')
                ->leftJoin('workflow_blocks as b', 'b.id', '=', 's.block_id')
                ->selectRaw('distinct left(md5(coalesce(b.name, s.name)), 8) as k, coalesce(b.name, s.name) as name')
                ->whereRaw('left(md5(coalesce(b.name, s.name)), 8) in ('.implode(',', array_fill(0, max(count($keys), 1), '?')).')', $keys ?: [''])
                ->get()->mapWithKeys(fn ($r) => [$r->k => new Option($r->k, $r->name)])->all()),
            // Срок — шаг сделки просрочен или нет; у закрытых сделок срока нет — «в срок».
            Facet::column('due', 'Срок', ['срок', 'срока', 'сроков'], "(case when deals.state = 'active' and exists (select 1 from offer_positions p
                where p.offer_id = deals.offer_id and p.track = 'sale' and p.deadline_at < now()) then 'late' else 'ok' end)")
                ->labels(fn (array $keys) => array_intersect_key(['late' => new Option('late', 'Просрочено'), 'ok' => new Option('ok', 'В срок')], array_flip($keys))),
            // Срок обязывающего горит, а поставщику не заплачено (19.10.2026, `Offers\Binding`).
            Facet::toggle('binding', 'Горит обязывающее', fn ($q) => $q->whereHas('offer', fn ($o) => Binding::scope($o, 'deal')))->dot('danger'),
            Facet::column('garage', 'Гараж', ['вид', 'вида', 'видов'], "(case when deals.garage_payer is null then 'deal' else 'garage' end)")
                ->labels(fn (array $keys) => array_intersect_key(['deal' => new Option('deal', 'Без гаража'), 'garage' => new Option('garage', 'Гаражные')], array_flip($keys))),
        );
        $askedSort = $request->query('sort');
        ListPrefs::sync($request, 'crm-deals', keep: $facets->keys());
        OfferController::forgetBindingSort($request, $askedSort);
        $preset = $request->query('preset', 'us');
        // Старые ссылки («Горит» утренней сводки) — на «Ваш ход», «В работе» — на «Все».
        $preset = match ($preset) {
            'hot' => 'us', 'active' => 'all', default => array_key_exists($preset, self::PRESETS) ? $preset : 'us'
        };
        $searching = $facets->searching();
        $sort = Sort::from($request->query('sort'), self::SORTS, 'deadline');

        // Гаражные — тоже сделки (27.10.2026, владелец: «в сделках их стоит отображать»; с 13.10 их здесь не было).
        $q = Deal::query()->with(['offer', 'offer.vendor', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice', 'buyer', 'openRequirement', 'invoices.claims', 'invoices.party', 'contract']);
        if ($searching) {
            // Лупа — по всем сделкам: номер предложения, VIN, марка, убыток, менеджер.
            $term = trim((string) $request->query('q'));
            $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->searchCrm($term))
                ->orWhereHas('buyer', fn ($u) => $u->search($term)));
        } else {
            self::scope($q, $preset);
        }
        $facets->apply($q);
        // Числа пилюль — с чипами (10.10.2026: «Горит» без числа, а таб «Работа» горел им же — владелец не понимал, откуда 26).
        $counts = collect(['us', 'manager', 'supplier', 'pay', 'all'])->mapWithKeys(fn (string $p) => [$p => self::scope($facets->applyTo(Deal::query()), $p)->count()])->all();
        $dir = $sort->dir();
        match ($sort->key) {
            'fresh' => $q->orderBy('created_at', $dir),
            'amount' => $q->orderByRaw("amount {$dir} nulls last"),
            'binding' => $q->orderByRaw("(select binding_until from offers where offers.id = deals.offer_id) {$dir} nulls last"),
            // По сроку — сначала ответ поставщика (как непрочитанное письмо), потом прочий наш ход, потом остальное.
            default => $q->orderByRaw(self::turnRank())->orderByRaw("(select min(deadline_at) from offer_positions where offer_positions.offer_id = deals.offer_id) {$dir} nulls last"),
        };
        $q->orderByDesc('deals.id');
        Rows::only($q, 'deals.id');
        $groups = $preset === 'us' && ! $searching ? self::todo($q->get(), $sort->key === 'deadline') : null;
        $debts = $preset === 'pay' && ! $searching ? self::debts($q->get()) : null;
        // Таблица — вся на одной странице; строками — постранично.
        $table = ListView::isTable(ListView::pick($request, 0));
        $deals = $table ? ListView::paginate($request, $q) : $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString();

        return view('admin.deals.index', [
            'groups' => $groups,
            // «Все» таблицей — группами по этапу.
            'stages' => $preset === 'all' && ! $searching && $table ? self::stages($deals->getCollection()) : null,
            'debts' => $debts,
            'deals' => $deals,
            'preset' => $preset,
            'counts' => $counts,
            'sort' => $sort,
            'facets' => $facets,
            'detail' => $detail,
        ]);
    }

    /**
     * «Ваш ход» — список дел (27.10.2026, владелец: «не видно, что нужно сделать, а что просто просрочено»): группы по тому,
     * что сделать (`Position::todo`), внутри — кто дольше ждёт; у заявки поставщику кнопка одна на группу (письмо на все
     * сделки вендора). Весь список одной страницей: дел — десятки. @return Collection<string, array>
     */
    private static function todo(Collection $deals, bool $byWait): Collection
    {
        $rows = $deals->map(function (Deal $deal) {
            $position = $deal->offer?->position();
            $position?->setRelation('offer', $deal->offer)->setRelation('deal', $deal);

            return ['deal' => $deal, 'todo' => $position?->todo()];
        })->filter(fn ($r) => $r['todo']);

        return $rows->groupBy(fn ($r) => $r['todo']['key'])->map(function (Collection $rows, string $key) use ($byWait) {
            $first = $rows->first();
            $group = ['title' => $first['todo']['title'], 'rank' => $first['todo']['rank'], 'button' => null,
                'rows' => $byWait ? $rows->sortBy(fn ($r) => $r['todo']['since']?->getTimestamp() ?? PHP_INT_MAX)->values() : $rows->values()];
            if (str_starts_with($key, 'request:')) {
                $vendor = $first['deal']->offer->vendor;
                $group['title'] .= $vendor ? ': '.$vendor->name : '';
                // Без числа: в письмо идут и гаражные сделки вендора, которых здесь нет, — «(8)» над шестью строками путало.
                $group['button'] = ['label' => 'Отправить заявку', 'letters' => '/work/mail/new?request='.$vendor?->id.'&then=close'];
            }

            return $group;
        })->sortBy([['rank', 'asc'], ['title', 'asc']]);
    }

    /**
     * «Оплата» — кто сколько должен (27.10.2026): группы по менеджеру (аукцион — своей группой), у группы сумма и просрочено;
     * первыми — у кого больше просрочено, внутри — просроченные, дальше по сроку. Весь список одной страницей.
     *
     * @return array{owed: float, overdue: float, groups: Collection<string, array>}
     */
    private static function debts(Collection $deals): array
    {
        $rows = $deals->map(fn (Deal $deal) => ['deal' => $deal, 'debt' => DealDebt::of($deal)])->filter(fn ($r) => $r['debt'] && ! $r['debt']->paid());
        $groups = $rows->groupBy(fn ($r) => $r['deal']->isAuction() ? 'auction' : 'manager:'.$r['deal']->buyer_id)->map(fn (Collection $rows) => [
            'title' => $rows->first()['deal']->isAuction() ? 'Аукцион' : ($rows->first()['deal']->buyer?->name ?? 'Без менеджера'),
            'owed' => $rows->sum(fn ($r) => $r['debt']->owed),
            'overdue' => $rows->sum(fn ($r) => $r['debt']->overdue),
            'rows' => $rows->sortBy(fn ($r) => [$r['debt']->late() ? 0 : 1, $r['debt']->due?->getTimestamp() ?? PHP_INT_MAX])->values(),
        ])->sortBy([['overdue', 'desc'], ['owed', 'desc']]);

        return ['owed' => $groups->sum('owed'), 'overdue' => $groups->sum('overdue'), 'groups' => $groups];
    }

    /**
     * «Все» — воронка (27.10.2026): группы по этапу (блок маршрута, одноимённые у разных вендоров — одна группа) в
     * порядке маршрута; видно, где сделки стоят. Сделка без шага продажи — последней группой. @return Collection<string, array>
     */
    private static function stages(Collection $deals): Collection
    {
        return $deals->groupBy(function (Deal $deal) {
            $stage = $deal->offer?->position()?->stage;

            return $stage ? ($stage->block?->name ?? $stage->name) : 'Без этапа';
        })->map(fn (Collection $rows, string $name) => [
            'title' => $name,
            // Место блока у вендоров разное (Совкомбанк забирает до оплаты) — среднее по сделкам группы.
            'rank' => $rows->avg(fn (Deal $d) => ($s = $d->offer?->position()?->stage) ? ($s->block?->position ?? 50) : 99),
            'rows' => $rows->values(),
        ])->sortBy([['rank', 'asc'], ['title', 'asc']]);
    }

    /**
     * Столбцы «Сделок» — одно описание для шапки, строк и групп (`Support\Table`). todo — «Ваш ход» списком дел: что сделать
     * говорит группа, у строки — сколько ждёт и кнопка; иначе — таблица со сроком шага, оплатой и суммой. preset — строке:
     * во вкладке «Ждём менеджера» / «Ждём поставщика» срок шага без «ждём …».
     */
    public static function table(bool $todo = false, bool $staged = false, ?string $preset = null): Table
    {
        $title = new Column('title', 'Марка, модель', grow: true);
        // Ширина у каждого столбца (29.10.2026): без неё `x-ui.table :rest` гадали числом в виде, и название то резалось, то
        // оставляло дыру.
        $vendor = new Column('vendor', 'Вендор, № убытка', from: 'sm', rem: 10);
        $manager = new Column('manager', 'Менеджер', from: 'sm', rem: 9);

        return match (true) {
            $todo => Table::of($title, $vendor, $manager, new Column('wait', 'Ждёт', from: 'sm', fill: true, rem: 8), new Column('action', num: true, rem: 9)),
            // «Оплата» — что с оплатой, сколько должен и срок (`x-deal.pay-row`); менеджер — заголовком группы.
            $preset === 'pay' => Table::of($title, $vendor, new Column('status', 'Что с оплатой', from: 'sm', fill: true, rem: 12),
                new Column('owed', 'Должен', num: true, class: 'nums', rem: 7), new Column('due', 'Срок', from: 'sm', num: true, rem: 6)),
            default => new Table([$title, $vendor, new Column('number', '№', from: '2xl', dim: true, class: 'nums', rem: 7), $manager,
                // «Все» сгруппированы по этапу — этап говорит заголовок группы, столбца нет (и таблица влезает с «Оплатой»).
                $staged ? null : new Column('stage', 'Этап', from: 'sm', fill: true, rem: 12),
                new Column('term', 'Что сейчас', from: 'sm', sort: 'deadline', fill: $staged, rem: 9),
                // Оплата — сколько должны нам и до какого числа (27.10.2026); на телефоне — под суммой.
                new Column('pay', 'Оплата', from: 'sm', num: true, class: 'nums', rem: 7),
                new Column('amount', 'Сумма', num: true, sort: 'amount', class: 'nums', rem: 7)], ['preset' => $preset]),
        };
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
        $sale = fn ($f) => $q->where('state', DealState::Active)->whereHas('offer.positions', fn ($p) => $f($p->where('track', 'sale')));

        return match ($preset) {
            'us' => $sale(fn ($p) => $p->needsUs()),
            'supplier' => $sale(fn ($p) => $p->waiting(WaitsFor::Supplier)->whereNot(fn ($n) => $n->needsUs())),
            // Чей ход — позиции (`Position::waiting`): этап оплаты без счёта ждёт нас, а не менеджера.
            'manager' => $sale(fn ($p) => $p->waiting(WaitsFor::Manager)->whereNot(fn ($n) => $n->needsUs())),
            'pay' => $q->whereRaw(DealDebt::OWES_SQL),
            'done' => $q->whereIn('state', [DealState::Done, DealState::Cancelled]),
            default => $q->where('state', DealState::Active), // all
        };
    }

    /**
     * Сделка целиком — это редактор предложения (06.10.2026: одна страница, три дорожки — «Продажа», «Вывоз», «Сделка»
     * с деньгами, ДКП и заметкой). Старые ссылки и уведомления ведут сюда — отсюда туда.
     */
    public function show(Deal $deal)
    {
        return redirect('/offers/'.$deal->offer->number);
    }

    /** Карточка чата сделки — фрейм перечитывает её по новому сообщению (`x-deal.chat-card`). */
    public function chat(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer, 404);

        return Blade::render('<x-deal.chat-card :deal="$deal" :compact="$compact"/>', ['deal' => $deal, 'compact' => $request->boolean('compact')]);
    }

    /** Карточка сделки рядом со списком (Detail): путь, деньги, письма, заметка — без истории. */
    private function detail(Deal $deal)
    {
        return view('admin.deals.detail', $this->data($deal));
    }

    /** Сделка целиком — одна выборка на страницу и карточку. */
    private function data(Deal $deal): array
    {
        $deal->load(['buyer', 'bid', 'requirements.media', 'requirements.stage.block', 'offer', 'offer.media', 'offer.settlement',
            'offer.vendor.workflows', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.positions.stage.workflow', 'offer.events.user']);
        $offer = $deal->offer;

        $threads = OfferFiles::threads($offer);

        // Письма и документы — как в редакторе: письмо вендору, документы к подписанию и ответ страховой ведут отсюда.
        return OfferFiles::letters($offer, auth()->user()) + [
            'docs' => OfferFiles::docs($offer, $threads),
            'deal' => $deal,
            'offer' => $offer,
            'events' => $offer->events->where('created_at', '>=', $deal->created_at),
        ];
    }

    /** Деньги сделки — пока по ней нет счёта или есть только неоплаченный подбор по ДКП (`Deal::moneyEditable`). */
    public function money(Request $request, Deal $deal, UpdateDealMoney $update)
    {
        $request->merge(['share' => Money::rubles($request->input('share')) ?: null]);
        $data = $request->validate(['commission' => ['nullable', 'integer', 'min:0'], 'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            'share' => ['nullable', 'integer', 'min:1']]);
        // Поле вознаграждения пустое — вознаграждения нет; поля нет вовсе (гаражная доля) — прежнее.
        $commission = $request->has('commission') ? (isset($data['commission']) ? (int) $data['commission'] : null) : $deal->commission;
        $scheme = DealScheme::choiceFor($deal->offer->vendor?->deal_format) ? DealScheme::tryFrom($data['scheme'] ?? '') : DealScheme::Prime;
        // Р.О.С. — только у Альфы (`DealScheme::casesFor`), как в «Принять»: чужому вендору схему с запроса не берём.
        if ($scheme && ! in_array($scheme, DealScheme::casesFor($deal->offer->vendor, $deal->offer), true)) {
            $scheme = DealScheme::forOffer($deal->offer);
        }
        $update($deal, $request->user(), $commission, CommissionMode::tryFrom($data['mode'] ?? '') ?? $deal->commission_mode,
            $scheme, isset($data['share']) ? (int) $data['share'] : null);

        return back()->with('toast', 'Сохранено');
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

    /**
     * Ответ поставщика списком (09.10.2026): каждой сделке — её исход «Поставщик согласовал…» (своей ветки) или
     * «Поставщик отказал». Без ответа — ждёт дальше; уже ушедшую с этапа пропускаем.
     */
    public function supplierAnswer(Request $request, TakeExit $take)
    {
        $answers = collect($request->validate(['answers' => ['array'], 'answers.*' => ['nullable', Rule::in(['agreed', 'declined'])]])['answers'] ?? [])->filter();
        $done = ['agreed' => 0, 'declined' => 0];
        foreach (Deal::whereKey($answers->keys())->where('state', DealState::Active)->with('offer.positions.stage.exits.to')->get() as $deal) {
            $prefix = $answers[$deal->id] === 'agreed' ? 'поставщик согласовал' : 'поставщик отказал';
            $exit = $deal->offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $e) => str_starts_with(mb_strtolower($e->label), $prefix));
            if ($exit) {
                $take($deal->offer, $exit, Actor::Staff, $request->user());
                $done[$answers[$deal->id]]++;
            }
        }
        $toast = collect(['отдаёт '.$done['agreed'] => $done['agreed'], 'отказал '.$done['declined'] => $done['declined']])->filter()->keys()->implode(', ');

        // Строки остальных сделок списка — у автора тоже (своё событие `rows` он пропускает): поток `rows` ответа карточки.
        return back(fallback: '/work/deals')->with('toast', $toast ? 'Отмечено: '.$toast : 'Ничего не отмечено')->with('live-rows', $answers->keys()->all());
    }

    public function note(Request $request, Deal $deal)
    {
        $deal->update($request->validate(['notes' => ['nullable', 'string', 'max:5000']]));

        return back()->with('toast', 'Заметка сохранена');
    }
}
