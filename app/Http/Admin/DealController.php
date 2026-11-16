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
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\DealScheme;
use App\Offers\DealWork;
use App\Offers\Giving;
use App\Offers\InsurerReplies;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\ReplyKind;
use App\Offers\ReplyReview;
use App\Offers\Reprice;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Sort;
use App\Support\Surface;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Users\User;
use App\Vendors\Vendor;
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

    /** Вкладки этапов — с группами по шагу маршрута и чипом «Чей ход». */
    private const STAGED = ['agree', 'paying', 'docs', 'pickup', 'settle', 'self'];

    /**
     * Прежние «Сделки» (`/work/deals`) — 301 в вкладки «Предложений»: «Ваш ход» с делами, «Ждём менеджера / поставщика» —
     * «Согласование» с чипом «Чей ход», «Оплата», «Все» — «Все» предложений, «Закончены» — «Закрыты»; `?peek=` сделки —
     * номером машины.
     */
    public function index(Request $request)
    {
        $preset = (string) $request->query('preset', 'us');
        $to = match ($preset) {
            'manager', 'supplier' => ['preset' => 'agree', 'turn' => $preset],
            'pay' => ['preset' => 'paying'],
            'all', 'active' => ['preset' => 'all'],
            'done' => ['preset' => 'finished'],
            default => ['preset' => 'us'],
        };
        $keep = array_intersect_key($request->query(), array_flip(['todo', 'sub', 'q', 'manager', 'vendor']));
        if (($peek = $request->query('peek')) && ($deal = Deal::with('offer')->find($peek))) {
            $keep['peek'] = $deal->offer?->number;
        }

        return redirect('/offers?'.http_build_query($to + $keep), 301);
    }

    /** Окно строки вкладки сделки: ключ — номер машины; модератору — окно документов её сделки. */
    private static function window(Request $request, string $key): mixed
    {
        $offer = OfferNumber::find($key);
        if ($request->user()->canManageCrm()) {
            return CarWindow::of($request, $offer);
        }
        $deal = $offer?->lastDeal()->with(['offer', 'buyer', 'contract'])->first();

        return $deal && ! $deal->isParallel() ? view('admin.deals.docs-window', ['deal' => $deal]) : null;
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
        $staged = in_array($preset, self::STAGED, true);
        $facets = Facets::for($request, 'crm-deals', ...[
            // Чей ход (16.11.2026, вместо вкладок «Ждём менеджера» / «Ждём поставщика»): с «Менеджер» — группы по менеджеру, с
            // «Поставщик» — по вендору, как было (`waiting`, 04.11.2026: «кого и чего ждём»).
            ...($staged ? [Facet::column('turn', 'Чей ход', ['ход', 'хода', 'ходов'], self::TURN_SQL)->single()
                ->labels(fn (array $keys) => array_intersect_key(['us' => new Option('us', 'Наш'), 'manager' => new Option('manager', 'Ждём менеджера'),
                    'supplier' => new Option('supplier', 'Ждём поставщика')], array_flip($keys)))] : []),
            Common::manager('deals.buyer_id'),
            Common::vendor('(select vendor_id from offers where offers.id = deals.offer_id)'),
            // Этап — блок маршрута по имени (как в строке): у разных вендоров одноимённые блоки — один вариант.
            Facet::column('stage', 'Этап', ['этап', 'этапа', 'этапов'], $stage)->labels(fn (array $keys) => DB::table('workflow_stages as s')
                ->leftJoin('workflow_blocks as b', 'b.id', '=', 's.block_id')
                ->selectRaw('distinct left(md5(coalesce(b.name, s.name)), 8) as k, coalesce(b.name, s.name) as name')
                ->whereRaw('left(md5(coalesce(b.name, s.name)), 8) in ('.implode(',', array_fill(0, max(count($keys), 1), '?')).')', $keys ?: [''])
                ->get()->mapWithKeys(fn ($r) => [$r->k => new Option($r->k, $r->name)])->all()),
            // Срок — шаг сделки просрочен или нет; у закрытых сделок срока нет — «в срок».
            Facet::column('due', 'Срок', ['срок', 'срока', 'сроков'], '(case when '.Deal::ACTIVE_SQL." and exists (select 1 from offer_positions p
                where p.offer_id = deals.offer_id and p.track = 'sale' and p.deadline_at < now()) then 'late' else 'ok' end)")
                ->labels(fn (array $keys) => array_intersect_key(['late' => new Option('late', 'Просрочено'), 'ok' => new Option('ok', 'В срок')], array_flip($keys))),
            // Срок обязывающего горит, а поставщику не заплачено (19.10.2026, `Offers\Binding`).
            Facet::toggle('binding', 'Горит срок обязывающего', fn ($q) => $q->whereHas('offer', fn ($o) => Binding::scope($o, 'deal')))->dot('danger'),
            Facet::column('garage', 'Гараж', ['вид', 'вида', 'видов'], "(case when deals.garage_payer is null then 'deal' else 'garage' end)")
                ->labels(fn (array $keys) => array_intersect_key(['deal' => new Option('deal', 'Без гаража'), 'garage' => new Option('garage', 'Гаражные')], array_flip($keys))),
        ]);
        $askedSort = $request->query('sort');
        // «Чей ход» не запоминается: вкладки прежде были вкладками, не фильтром — в соседней вкладке он путал бы.
        ListPrefs::sync($request, 'crm-deals', keep: array_values(array_diff($facets->keys(), ['turn'])));
        OfferController::forgetBindingSort($request, $askedSort);
        $searching = $facets->searching();
        $sort = Sort::from($request->query('sort'), self::SORTS, 'deadline');
        $turn = $staged ? (string) $request->query('turn') : '';

        // Гаражные — тоже сделки (27.10.2026, владелец: «в сделках их стоит отображать»; с 13.10 их здесь не было).
        $q = Deal::query()->with(['offer', 'offer.vendor', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'offer.pendingReprice', 'buyer', ...User::withAvatar('buyer.media'), 'openRequirement', 'invoices.claims', 'invoices.party', 'contract'])->withCount('parallels')
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
        $whole = ! $searching && ($preset === 'us' || $staged) ? (clone $q)->get() : null;
        Rows::only($q, fn ($q, array $keys) => $q->whereHas('offer', fn ($o) => $o->whereIn('offers.number', $keys)));
        $live = Rows::keys();
        $link = fn (array $set) => '/offers?'.str_replace('%2C', ',', http_build_query(array_filter(
            array_merge(array_diff_key($request->query(), array_flip(['page', 'rows', 'part', 'peek', 'sub'])), $set, ['preset' => $preset]), fn ($v) => $v !== null && $v !== '')));
        $work = match (true) {
            $whole === null => null,
            $preset === 'us' => self::todo($whole, $sort->key === 'deadline', $request, $link, $live),
            in_array($turn, ['manager', 'supplier'], true) => self::waiting($whole, $turn, $request, $link, $live),
            default => null,
        };
        $groups = $preset === 'us' ? $work['groups'] ?? null : null;
        $waiting = $preset !== 'us' ? $work['groups'] ?? null : null;
        // «Оплата» (27.10.2026, владелец: «не понимаю, кто сколько должен заплатить и когда») — по менеджеру с суммой и
        // просрочкой; с 16.11.2026 это вкладка этапа: и те, у кого счёта ещё нет.
        $debts = $whole !== null && $work === null && $preset === 'paying' ? self::debts(self::liveOnly($whole, $live), all: true) : null;
        // Этапы — группами по шагу маршрута в его порядке (16.11.2026).
        $steps = $whole !== null && $work === null && $debts === null ? self::steps(self::liveOnly($whole, $live)) : null;
        $table = ListView::isTable(ListView::pick($request, 0));
        $deals = match (true) {
            $whole !== null => new LengthAwarePaginator([], $whole->count(), 1),
            $table => ListView::paginate($request, $q),
            default => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
        };
        $user = $request->user();

        return view('admin.deals.index', [
            'groups' => $groups,
            'stages' => $steps,
            'debts' => $debts,
            'waiting' => $waiting,
            // Вкладки дел второго ряда (`x-deal.work-tabs`) и выбранный подзаголовок.
            'work' => $work,
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

    /** Строки живого обновления — только запрошенные (номера машин). */
    private static function liveOnly(Collection $deals, ?array $live): Collection
    {
        return $live === null ? $deals : $deals->filter(fn (Deal $d) => in_array((string) $d->offer?->number, $live, true))->values();
    }

    /** Чей ход по шагу продажи: «наш» (`Position::NEEDS_US_SQL`), менеджера или поставщика — чип «Чей ход» вкладок этапов. */
    private const TURN_SQL = '(select case when '.Position::NEEDS_US_SQL." then 'us'
        when coalesce(offer_positions.waits_for, (select s.waits_for from workflow_stages s where s.id = offer_positions.stage_id)) = 'manager' then 'manager'
        when coalesce(offer_positions.waits_for, (select s.waits_for from workflow_stages s where s.id = offer_positions.stage_id)) = 'supplier' then 'supplier'
        else 'us' end from offer_positions where offer_positions.offer_id = deals.offer_id and offer_positions.track = 'sale' order by offer_positions.id limit 1)";

    /**
     * «Ваш ход» — список дел (27.10.2026, владелец: «не видно, что нужно сделать, а что просто просрочено»): группы по тому,
     * что сделать (`Position::todo`), внутри — кто дольше ждёт; у заявки поставщику кнопка одна на группу (письмо на все
     * сделки вендора). Весь список одной страницей: дел — десятки.
     *
     * С 09.11.2026 — вкладками дел (`Offers\DealWork::section`, `?todo=`; владелец: «безумно неудобно», «Разобрать ответ —
     * отдельная вкладка, и в ней подзаголовки: каким-то пришло „ГОТС поступили на реализацию“, по каким-то запрос реквизитов»):
     * первой и по умолчанию — «Все» (10.11.2026): все дела группами в порядке вкладок. «Разобрать ответ» — подзаголовками по смыслу письма
     * (`Offers\ReplyKind`). Подзаголовок — ссылка (`&sub=`): только эта группа, адрес для начальника — скрепкой.
     *
     * @param  ?list<string>  $live  ключи строк живого обновления — рисуются только они, числа — по всему списку
     * @return array{tabs: list<array>, tab: string, sub: ?array, groups: Collection<string, array>}
     */
    private static function todo(Collection $deals, bool $byWait, Request $request, \Closure $link, ?array $live): array
    {
        // Переоценки письмом без ответа — одним запросом на список, а не на сделку (`Position::repriceUnanswered`, 10.11.2026).
        $letters = Reprice::whereIn('offer_id', $deals->pluck('offer_id')->filter()->unique())->where('state', Reprice::APPLIED)
            ->where('source', 'letter')->whereNull('answered_at')->distinct()->pluck('offer_id')->flip();
        $rows = $deals->map(function (Deal $deal) use ($letters) {
            $position = $deal->offer?->position();
            $position?->setRelation('offer', $deal->offer)->setRelation('deal', $deal);
            $position && ($position->letterRepriceKnown = $letters->has($deal->offer_id));
            $todo = $position?->todo();

            return $todo ? ['deal' => $deal, 'todo' => $todo, 'section' => DealWork::section($todo['key'])] : null;
        })->filter()->values();
        $counts = $rows->countBy('section');
        $asked = (string) $request->query('todo');
        // «Все» — первой и по умолчанию (10.11.2026, владелец: «чтобы точно не пропустили — а то разберут первую вкладку, а на
        // остальные забьют»): все дела группами в порядке вкладок, у групп «Разобрать ответ» — смысл письма.
        $tab = isset(DealWork::SECTIONS[$asked]) ? $asked : DealWork::ALL;
        $tabs = collect([DealWork::ALL => 'Все', ...DealWork::SECTIONS])
            ->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title, 'n' => $key === DealWork::ALL ? $rows->count() : $counts[$key] ?? 0,
                'count' => 'todo:'.$key, 'href' => $link(['preset' => null, 'what' => null, 'todo' => $key === DealWork::ALL ? null : $key])])->values()->all();

        $all = $tab === DealWork::ALL;
        $rows = $all ? $rows : $rows->where('section', $tab);
        $kinds = self::replyKinds($rows->where('section', 'reply')->pluck('deal'));
        $order = array_flip(array_keys(DealWork::SECTIONS));
        $groups = $rows->groupBy(fn ($r) => ($all ? $r['section'].'.' : '').($r['section'] === 'reply' ? $kinds[$r['deal']->id]->value : $r['todo']['key']))->map(function (Collection $rows, string $key) use ($byWait, $all, $link, $order) {
            $first = $rows->first();
            $section = $first['section'];
            $sub = $all ? substr($key, strlen($section) + 1) : $key;
            $kind = $section === 'reply' ? ReplyKind::from($sub) : null;
            $group = ['title' => $kind ? ($all ? DealWork::SECTIONS['reply'].': ' : '').$kind->title() : $first['todo']['title'],
                'section' => $order[$section], 'rank' => $kind ? array_search($kind, ReplyKind::cases(), true) : $first['todo']['rank'],
                'button' => null, 'size' => $rows->count(),
                'rows' => $byWait ? $rows->sortBy(fn ($r) => $r['todo']['since']?->getTimestamp() ?? PHP_INT_MAX)->values() : $rows->values()];
            if (str_starts_with($sub, 'request:')) {
                $vendor = $first['deal']->offer->vendor;
                $group['title'] .= $vendor ? ': '.$vendor->name : '';
                // Без числа: в письмо идут и гаражные сделки вендора, которых здесь нет, — «(8)» над шестью строками путало.
                $group['button'] = ['label' => 'Отправить заявку', 'letters' => '/work/mail/new?request='.$vendor?->id.'&then=close'];
            }
            // Подзаголовок — ссылка на свою группу во вкладке раздела, скрепка — её адрес для начальника.
            $group['href'] = $link(['preset' => null, 'todo' => $section, 'sub' => $sub]);
            $group['link'] = Surface::Crm->url('/offers?'.http_build_query(['preset' => 'us', 'todo' => $section, 'sub' => $sub]));

            return $group;
        })->sortBy([['section', 'asc'], ['rank', 'asc'], ['title', 'asc']]);

        return self::narrow(['tabs' => $tabs, 'tab' => $tab], $groups, $request, $link(['preset' => null, 'todo' => $tab]), $live);
    }

    /** Смысл писем на разборе по сделкам вкладки — одним запросом (`ReplyReview`), старый путь без строки разбора — последний ответ. */
    private static function replyKinds(Collection $deals): Collection
    {
        $letters = ReplyReview::pending()->whereIn('deal_id', $deals->pluck('id'))->with('message.attachments')->get()
            ->groupBy('deal_id')->map(fn (Collection $reviews) => $reviews->pluck('message')->filter());

        return $deals->mapWithKeys(fn (Deal $deal) => [$deal->id => ReplyKind::forDeal(
            ($letters[$deal->id] ?? collect())->whenEmpty(fn () => InsurerReplies::for($deal)->take(1)->load('attachments')))]);
    }

    /**
     * Подзаголовок по ссылке (`&sub=`) — только эта группа (нет такой — пусто, а не всё: ссылку дали на неё); строки живого
     * обновления — только запрошенные.
     */
    private static function narrow(array $work, Collection $groups, Request $request, string $clear, ?array $live): array
    {
        $sub = $work['tab'] === DealWork::ALL ? '' : (string) $request->query('sub');
        if ($sub !== '') {
            $work['sub'] = ['title' => $groups[$sub]['title'] ?? self::subTitle($sub), 'clear' => $clear];
            $groups = $groups->only([$sub]);
        }
        if ($live !== null) {
            $groups = $groups->map(fn (array $g) => [...$g, 'rows' => $g['rows']->filter(fn ($r) => in_array((string) ($r instanceof Deal ? $r : $r['deal'])->offer?->number, $live, true))->values()])
                ->filter(fn (array $g) => $g['rows']->isNotEmpty());
        }

        return [...$work, 'sub' => $work['sub'] ?? null, 'groups' => $groups];
    }

    /** Подпись подзаголовка, которого сейчас нет в списке (ссылку дали на него, а он уже разобран). */
    private static function subTitle(string $sub): string
    {
        // Подписи — как у дел `Position::todo`.
        return ReplyKind::tryFrom($sub)?->title() ?? match (true) {
            str_starts_with($sub, 'stage:') => substr($sub, 6),
            str_starts_with($sub, 'request:') => 'Отправить заявку поставщику',
            default => [
                'reprice' => 'Решить по переоценке', 'reprice-answer' => 'Ответить поставщику про переоценку',
                'price-ask' => 'Менеджер не ответил про закупочную', 'letter' => 'Написать поставщику',
                'bounced' => 'Письмо поставщику не дошло', 'consent' => 'Менеджер не ответил, берёт ли',
                'gap:share' => 'Вписать вознаграждение', 'gap:commission' => 'Назначить вознаграждение',
                'gap:ros_requisites' => 'Заполнить реквизиты Р.О.С.', 'gap:invoice' => 'Выставить счёт',
            ][$sub] ?? 'Группа',
        };
    }

    /**
     * «Ждём менеджера» / «Ждём поставщика» — группами по тому, кого ждём (04.11.2026, владелец: «непонятно, КОГО из менеджеров
     * мы ждём и ЧЕГО мы от них ждём, а это самое главное»): у группы менеджер (аватар, «Позвонить») или вендор, у строки — что
     * ждём (этап: «Нет реквизитов», «Оплата счёта ПРАЙМ», «Забрать автомобиль») и срок (`x-deal.wait-row`). Первыми — где
     * просрочено, дальше — где больше сделок; внутри — по сроку списка. Весь список одной страницей.
     *
     * С 09.11.2026 — вкладками по тому, чего ждём (`?what=`, владелец выбрал из вариантов): подэтапы схемы главной
     * (`DealWork::waitStep`), блок не из таблицы — «Прочее», первой и по умолчанию — «Все» (10.11.2026).
     *
     * @return array{tabs: list<array>, tab: string, sub: ?array, groups: Collection<string, array{title: string, manager: ?User, vendor: ?Vendor, late: int, size: int, rows: Collection}>}
     */
    private static function waiting(Collection $deals, string $preset, Request $request, \Closure $link, ?array $live): array
    {
        $manager = $preset === 'manager';
        // Чего ждём — вкладками (09.11.2026, `DealWork::waitStep`, `?what=`): те же подэтапы, что на схеме главной.
        $steps = $deals->mapWithKeys(function (Deal $d) {
            $position = $d->offer?->position();
            $position?->setRelation('deal', $d);

            return [$d->id => ($position ? DealWork::waitStep($position, $d) : null) ?? DealWork::OTHER];
        });
        $counts = $steps->countBy();
        $order = [...DealWork::WAITS[$preset], DealWork::OTHER];
        $asked = (string) $request->query('what');
        // «Все» — первой и по умолчанию, как в «Ваш ход» (10.11.2026).
        $tab = in_array($asked, $order, true) ? $asked : DealWork::ALL;
        $tabs = collect([DealWork::ALL, ...$order])->map(fn (string $key) => ['key' => $key, 'title' => $key === DealWork::ALL ? 'Все' : DealWork::waitTitle($key),
            'n' => $key === DealWork::ALL ? $deals->count() : $counts[$key] ?? 0,
            'count' => 'what:'.$preset.':'.$key, 'href' => $link(['todo' => null, 'what' => $key === DealWork::ALL ? null : $key])])->all();
        $deals = $tab === DealWork::ALL ? $deals : $deals->filter(fn (Deal $d) => $steps[$d->id] === $tab)->values();

        $groups = $deals->groupBy(fn (Deal $d) => $manager ? ($d->isAuction() ? 'auction' : 'manager:'.$d->buyer_id) : 'vendor:'.$d->offer?->vendor_id)
            ->map(function (Collection $rows) use ($manager) {
                $first = $rows->first();
                $vendor = $manager ? null : $first->offer?->vendor;

                return [
                    'title' => $manager ? ($first->isAuction() ? 'Аукцион' : ($first->buyer?->name ?? 'Без менеджера')) : ($vendor?->name ?? 'Без вендора'),
                    'manager' => $manager && ! $first->isAuction() ? $first->buyer : null,
                    'vendor' => $vendor,
                    'late' => $rows->filter(fn (Deal $d) => $d->offer?->position()?->isOverdue())->count(),
                    'size' => $rows->count(),
                    'rows' => $rows->values(),
                ];
            })->sortBy([['late', 'desc'], ['size', 'desc'], ['title', 'asc']]);

        return self::narrow(['tabs' => $tabs, 'tab' => $tab], $groups, $request, $link(['what' => $tab]), $live);
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
     * Вкладка этапа — группами по шагу маршрута в его порядке (16.11.2026, владелец выбрал вкладки «по этапам маршрута»):
     * «Письмо о покупке», «Согласие менеджера»… Пометку гаражной ветки «, в гараж» говорит строка. Без шага — последней.
     */
    private static function steps(Collection $deals): Collection
    {
        return $deals->groupBy(function (Deal $deal) {
            $stage = $deal->offer?->position()?->stage;

            return $stage ? str_replace(', в гараж', '', $stage->name) : 'Без шага';
        })->map(fn (Collection $rows, string $name) => [
            'title' => $name,
            'rank' => $rows->avg(fn (Deal $d) => ($s = $d->offer?->position()?->stage) ? ($s->block?->position ?? 50) * 1000 + $s->position : PHP_INT_MAX),
            'rows' => $rows->values(),
        ])->sortBy([['rank', 'asc'], ['title', 'asc']]);
    }

    /**
     * Столбцы «Сделок» — одно описание для шапки, строк и групп (`Support\Table`). todo — «Ваш ход» списком дел: что сделать
     * говорит группа, у строки — сколько ждёт и кнопка; иначе — таблица со сроком шага, оплатой и суммой. preset — строке:
     * во вкладке «Ждём менеджера» / «Ждём поставщика» срок шага без «ждём …».
     */
    public static function table(bool $todo = false, bool $staged = false, ?string $preset = null, bool $waiting = false): Table
    {
        $title = new Column('title', 'Марка, модель', grow: true);
        // Ширина у каждого столбца (29.10.2026): без неё `x-ui.table :rest` гадали числом в виде, и название то резалось, то
        // оставляло дыру.
        $vendor = new Column('vendor', '№ убытка', from: 'sm', role: ColRole::Code, rem: 10);
        $manager = new Column('manager', 'Менеджер', from: 'sm', role: ColRole::Who, rem: 9);

        return match (true) {
            // Кнопка шага — самой правой, за сроком «Ждёт».
            $todo => Table::of($title, $vendor, $manager, new Column('wait', 'Ждёт', from: 'sm', role: ColRole::When, rem: 8), new Column('action', role: ColRole::When, rem: 9)),
            // «Ждём менеджера» / «Ждём поставщика» (`x-deal.wait-row`): кого ждём — заголовком группы, у строки — что ждём и срок.
            $waiting => Table::of($title, $vendor, new Column('what', 'Ждём', from: 'sm', role: ColRole::State, rem: 12),
                new Column('term', 'Срок', from: 'sm', role: ColRole::When, rem: 9),
                new Column('amount', 'Сумма', num: true, class: 'nums', rem: 7)),
            // «Оплата» — что с оплатой, сколько должен и срок (`x-deal.pay-row`); менеджер — заголовком группы.
            $preset === 'paying' => Table::of($title, $vendor, new Column('status', 'Что с оплатой', from: 'sm', role: ColRole::State, rem: 12),
                new Column('owed', 'Должен', num: true, class: 'nums', rem: 7), new Column('due', 'Срок', from: 'sm', role: ColRole::When, rem: 6)),
            default => new Table([$title, $vendor, new Column('number', '№', from: '2xl', class: 'nums', role: ColRole::Code, rem: 7), $manager,
                // «Все» сгруппированы по этапу — этап говорит заголовок группы, столбца нет (и таблица влезает с «Оплатой»).
                $staged ? null : new Column('stage', 'Этап', from: 'sm', role: ColRole::State, rem: 12),
                new Column('term', 'Что сейчас', from: 'sm', sort: 'deadline', role: ColRole::When, rem: 9),
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
        return match ($preset) {
            // «Ваш ход» — нужно наше действие (`Position::needsUs`; им же число у «Предложений» в таб-баре, `Nav::staffCounts`).
            'us' => $q->active()->whereHas('offer.positions', fn ($p) => $p->where('track', 'sale')->needsUs()),
            // «Закрыты» — выданные (16.11.2026): продажа на аукционе, «Сделка закрыта».
            'finished' => $q->done()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Delivered)),
            // Этап — блок шага продажи (`DealWork::tabSql`).
            default => $q->active()->whereHas('offer', fn ($o) => $o->where('offers.state', OfferState::Sold)->whereRaw(DealWork::tabSql($preset))),
        };
    }

    /**
     * Сделка целиком — это редактор предложения (06.10.2026: одна страница, три дорожки — «Продажа», «Вывоз», «Сделка»
     * с деньгами, ДКП и заметкой). Старые ссылки и уведомления ведут сюда — отсюда туда.
     */
    public function show(Request $request, Deal $deal)
    {
        // Модератору страницы машины нет — окно документов в «Сделках».
        if (! $request->user()->canManageCrm()) {
            return redirect('/offers?'.http_build_query(['preset' => 'agree', 'peek' => $deal->offer->number]));
        }

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
        // «Нам по ссылке» из ползунка вознаграждения (16.11.2026): ноль — вознаграждение съело всю разницу, это ошибка, а не «стереть».
        $request->merge(['share' => blank($request->input('share')) ? null : Money::rubles($request->input('share'))]);
        $data = $request->validate(['commission' => ['nullable', 'integer', 'min:0'], 'mode' => ['nullable', Rule::enum(CommissionMode::class)],
            'scheme' => ['nullable', Rule::enum(DealScheme::class)],
            'share' => ['nullable', 'integer', 'min:1']], ['share.min' => Giving::SHARE_LEFT]);
        // Поле вознаграждения пустое — вознаграждения нет; поля нет вовсе (гаражная доля) — прежнее.
        $commission = $request->has('commission') ? (isset($data['commission']) ? (int) $data['commission'] : null) : $deal->commission;
        $scheme = DealScheme::tryFrom($data['scheme'] ?? '');
        // Схема не из этого вендора (`DealScheme::casesFor`), как в «Принять», — по умолчанию предложения.
        if ($scheme && ! in_array($scheme, DealScheme::casesFor($deal->offer->vendor, $deal->offer), true)) {
            $scheme = DealScheme::forOffer($deal->offer);
        }
        $update($deal, $request->user(), $commission, CommissionMode::tryFrom($data['mode'] ?? '') ?? $deal->commission_mode,
            $scheme, isset($data['share']) ? (int) $data['share'] : null);

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
        abort_unless($request->user()->canManageCrm(), 403);
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
        abort_unless($request->user()->canManageCrm(), 403);
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
        return back(fallback: '/offers?preset=us')->with('toast', $toast ? 'Отмечено: '.$toast : 'Ничего не отмечено')
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

    public function removeSigned(Request $request, Deal $deal, Media $media)
    {
        abort_unless(AttachSignedContract::allows($deal), 404);
        AttachSignedContract::remove($deal, $media, $request->user());

        return back()->with('toast', 'Файл договора убран');
    }
}
