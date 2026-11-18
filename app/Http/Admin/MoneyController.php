<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\ExistingLink;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\LinkExists;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\ConfirmPayment;
use App\Billing\Actions\IssueServiceInvoice;
use App\Billing\Actions\RejectPayment;
use App\Billing\Actions\SwapPaymentProof;
use App\Billing\ChargeKind;
use App\Billing\Seller;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Http\Billing\InvoiceActions;
use App\Offers\Deal;
use App\Offers\DealCredit;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\Money;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * «Оплаты» по менеджерам (18.11.2026, владелец: «начальник попросил их сгруппировать по менеджерам — аватарка, имя, справа сумма долга и
 * стрелочка; внутри все оплаты по этому менеджеру, в том числе то, что надо ему отправить»; «список по машинам, как в предложениях, а
 * не по плательщикам»). В каждом разделе («Комиссии · ДКП · Выплаты») — люди строками (`people`), внутри — страница человека
 * (`person`, `/work/money/managers/{id}`): машины группами «Отправить» (с «Отправить» и «Уже оплачено»), «Сообщил об оплате»,
 * «Ждём оплату», «Оплачено за 30 дней»; строка открывает окно машины. Лупа и «Оплаченные» — прежней таблицей по всем.
 *
 * Прежде — две вкладки по направлению денег (17.11.2026, владелец согласовал разбор «Оплат»; с 07.10.2026 было три,
 * третья — «По ссылкам», и одни и те же долги жили в двух местах по способу оплаты):
 * - «Нам должны» — все наши счета: сделки и гараж (по счёту и по ссылке), разовые оплаты по ссылке; группы «Проверить»
 *   (пишет, что оплатил), «Просрочено» и «Ждём» с суммой у заголовка; у счёта со ссылкой — что с ней словом в статусе;
 *   «Откуда» — чип (сделка, гараж, вручную), прежний смысл списка ссылок;
 * - «Мы должны» — вознаграждения менеджерам и перевозки (логисту): группа на получателя, сколько ему всего.
 * Оплаченное — чипом «Оплаченные» (без него в ленте только дела). Карточка ссылки — по-прежнему `?tab=links&peek={ссылка}`
 * (уведомления, «Открыть ссылку», строка ссылки в карточке счёта): список под ней — «Нам должны». Выписки СберБизнеса здесь
 * нет — она в Настройки → Банк. Чип менеджера — на обеих (один выбран — его расчёт строкой сверху), лупа ищет в обеих.
 */
class MoneyController
{
    use InvoiceActions;

    /**
     * Разделы — крупными заголовками (17.11.2026, владелец: «как было в „Работе“, красиво, как в App Store»): «Комиссии» — оплата
     * подбора ПРАЙМ (и разовые услуги по ссылке), «ДКП» — счета за сами машины от ПРАЙМ и Р.О.С. (оплаты страховым и хозяевам напрямую
     * мы не ведём — счетов у нас нет), «Выплаты» — перевозки партнёрам и перевозчикам (прежнее «Мы должны»; вознаграждения менеджерам мы не выплачиваем, 18.11.2026).
     */
    public const TABS = ['fees' => 'Комиссии', 'dkp' => 'ДКП', 'payouts' => 'Выплаты'];

    /** Разделы «нам должны»: группы «Проверить», «Ждём», чип «Откуда», номер счёта. */
    private const OWED = ['fees', 'dkp'];

    /** «Мы должны»: перевозки партнёрам и перевозчикам (логистика, 10.10.2026). */
    public const PAYOUTS = [ChargeKind::Tow];

    /**
     * Сортировка вкладки (07.10.2026): по умолчанию «Срочное» — ждём сверху тех, где по ссылке не вышло, дальше по сроку,
     * оплаченное свежим; иначе поле столбца в обеих группах. «Проверить» не сортируется — это дела.
     */
    public static function sorts(string $tab): array
    {
        return ['auto' => ['Срочное', 'desc'], 'created' => ['Создано', 'desc'], 'due' => ['Срок', 'asc']]
            // По плательщику и номеру счёта — нет (18.11.2026): строки по машинам, у комиссии счёта нет.
            + ($tab === 'payouts' ? ['payer' => ['Кому', 'asc']] : []) + ['amount' => ['Сумма', 'desc']];
    }

    /** Менеджер счёта: покупатель сделки, у гаражной — держатель машины. */
    private const MANAGER = 'coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id limit 1))';

    /** Получатель выплаты — сотрудник по своей стороне (логист — вывоз). */
    private const RECIPIENT = '(select u.id from users u where u.party_id = billing_invoices.party_id order by u.id limit 1)';

    /** Чей счёт в разделе: у долга нам — менеджер сделки или гаража, у выплаты — получатель. */
    private static function personSql(string $tab): string
    {
        return $tab === 'payouts' ? self::RECIPIENT : self::MANAGER;
    }

    private const WITH = ['party', 'charges', 'payments', 'offer', 'deal.offer', 'deal.buyer', 'garageCar.manager', 'garageCar.offer'];

    public function index(Request $request)
    {
        // Ключ карточки с `tab=links` — ссылка (`linkUrl`: уведомления, строка ссылки в карточке счёта); иначе — счёт, у
        // разовой оплаты — её последняя ссылка. Номера ссылок и счетов пересекаются, угадывать по одному числу нельзя.
        $detail = Detail::of($request, fn (string $key) => $request->query('tab') === 'links'
            ? (($link = PayLink::find($key)) ? $this->link($link) : null)
            : (($invoice = Invoice::find($key)) ? (($invoice->isService() && ($link = $invoice->payLinks()->reorder()->latest('id')->first())) ? $this->link($link) : $this->detail($invoice)) : null));
        if ($detail->framed()) {
            return $detail->response();
        }
        // Вкладки ссылок больше нет (17.11.2026): `tab=links` — только карточка ссылки, список под ней — «Комиссии». Прежнее
        // `tab=owed` (закладки, уведомления) — раздел открытого счёта, без него — «Комиссии».
        $tab = (string) $request->query('tab');
        if (! array_key_exists($tab, self::TABS)) {
            $peek = $tab === 'owed' && ctype_digit((string) $request->query('peek')) ? Invoice::find((int) $request->query('peek')) : null;
            $tab = $peek ? self::tabOf($peek) : 'fees';
        }
        $facets = self::facets($request, $tab);
        // «Оплаченные» не помнится: раздел открывается делами, а не историей.
        ListPrefs::sync($request, 'crm-money', keep: array_values(array_diff($facets->keys(), ['paid'])));
        // Варианты чипов — по счетам вкладки: «сколько будет» в шторке чипа отвечает этот запрос.
        $facets->apply(self::of($tab));
        $qs = trim((string) $request->query('q'));
        // Лупа — по обеим вкладкам сразу (мимо пилюли), открытые и оплаченные, без страниц.
        $tabs = $qs === '' ? [$tab] : array_keys(self::TABS);
        $sort = fn (string $t) => Sort::from($request->query('sort'), self::sorts($t), '-auto');
        $sections = collect($tabs)->mapWithKeys(fn ($t) => [$t => $this->section($t, $t === $tab ? $facets : self::facets($request, $t), $qs, $sort($t))])->all();
        $paid = collect($sections)->flatMap(fn ($s) => $s['paid'] instanceof Collection ? $s['paid'] : $s['paid']->items())->flatMap(fn ($i) => $i->payments->pluck('id'));

        // Без лупы и «Оплаченных» — люди строками (18.11.2026): внутри человека — его машины.
        $people = $qs === '' && ! $facets->on('paid') ? self::people($tab, $facets) : null;

        return view('admin.money.index', [
            'detail' => $detail, 'facets' => $facets, 'q' => $qs, 'tab' => $tab, 'sections' => $sections, 'sort' => $sort($tab),
            'counts' => self::counts($facets), 'attempts' => AcquiringPayment::whereIn('payment_id', $paid)->get()->keyBy('payment_id'),
            'people' => $people,
        ]);
    }

    /**
     * Чипы вкладки: менеджер (у обеих, помнится один на раздел), «Откуда» у «Нам должны» — сделка, гараж или вручную (что
     * было подписью строки в списке ссылок, 09.10.2026), «Оплаченные» — вместо открытых оплаченное, свежее сверху.
     */
    private static function facets(Request $request, string $tab): Facets
    {
        // Чипа «Менеджер» нет (18.11.2026): раздел и так по менеджерам.
        return Facets::for($request, 'crm-money', ...array_filter([
            in_array($tab, self::OWED, true) ? Facet::column('from', 'Откуда', ['источник', 'источника', 'источников'], MoneyLinks::FROM)
                ->labels(fn (array $keys) => collect(MoneyLinks::FROM_LABELS)->only($keys)->map(fn ($l, $k) => new Option($k, $l))->all())->natural() : null,
            Facet::toggle('paid', $tab === 'payouts' ? 'Выплаченные' : 'Оплаченные', fn ($q) => $q),
        ]));
    }

    /**
     * Счета раздела: «Комиссии» — наши выставленные, кроме счетов за машину (подбор, разовые услуги); «ДКП» — счета за машину от
     * ПРАЙМ и Р.О.С. (сделки и гараж); «Выплаты» — вознаграждения и перевозки.
     */
    private static function of(string $tab, ?Facets $facets = null)
    {
        $q = Invoice::crmMoney();
        // Неотправленный менеджеру счёт сделки — тоже здесь (18.11.2026, владелец: «внутри все оплаты по этому менеджеру и в том
        // числе то, что надо ему отправить»): группой «Отправить» у человека. Вознаграждение от поставщика до оплаты — нет.
        // До шага оплаты неотправленного не видно вовсе: это ещё не долг (`Invoice::ON_PAY_STEP_SQL`).
        $ours = fn () => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'issued')
            ->where(fn ($w) => $w->where(fn ($x) => $x->shownToManager())
                ->orWhere(fn ($x) => $x->whereRaw(Invoice::ON_PAY_STEP_SQL)));

        return match ($tab) {
            'dkp' => $ours()->where('kind', ChargeKind::Sale)->whereIn('seller', [Seller::Prime->value, Seller::Ros->value]),
            'fees' => $ours()->where(fn ($w) => $w->where('kind', '!=', ChargeKind::Sale)->orWhereNotIn('seller', [Seller::Prime->value, Seller::Ros->value])),
            default => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'owed')->whereIn('kind', self::PAYOUTS),
        };
    }

    /** Раздел счёта: выплата, счёт за машину или комиссия. */
    public static function tabOf(Invoice $i): string
    {
        return match (true) {
            $i->isOwed() => 'payouts',
            $i->kind === ChargeKind::Sale && in_array($i->seller, [Seller::Prime, Seller::Ros], true) => 'dkp',
            default => 'fees',
        };
    }

    /**
     * Вкладка группами: claims — менеджер пишет, что оплатил (только «Нам должны»); open — ждём, сверху то, где по ссылке
     * пытались и не вышло; paid — оплаченные свежими сверху, по 50 на страницу. Без лупы — либо дела (claims, open), либо
     * с чипом «Оплаченные» только paid; с лупой — всё.
     */
    private function section(string $tab, Facets $facets, string $qs, Sort $sort): array
    {
        // Лупа — `Invoice::search` (пустой запрос — без отбора); без неё у оплаченного страницы.
        $q = fn () => self::of($tab, $facets)->search($qs)->with(self::WITH);
        $history = $qs === '' && $facets->on('paid');
        $todo = $qs !== '' || ! $history;
        $claims = in_array($tab, self::OWED, true) && $todo
            ? Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereIn('billing_invoices.id', self::of($tab, $facets)->search($qs)->select('billing_invoices.id')))
                ->with(['invoice' => fn ($i) => $i->with(self::WITH)])->orderBy('paid_at')->orderBy('id')->get()
            : collect();
        $tried = $tab === 'payouts' ? collect() : self::tried(self::of($tab, $facets)->search($qs))->pluck('billing_invoices.id');
        $auto = $sort->key === 'auto';
        $open = ! $todo ? collect() : $q()->where('state', InvoiceState::Issued)->with('payLinks.attempts')
            ->when(in_array($tab, self::OWED, true), fn ($w) => $w->whereDoesntHave('claims'))
            ->when($auto && $tried->isNotEmpty(), fn ($w) => $w->orderByRaw('billing_invoices.id in ('.$tried->map(fn ($id) => (int) $id)->implode(',').') desc'))
            ->when($auto, fn ($w) => $w->orderByRaw('due_at asc nulls last'), fn ($w) => self::order($w, $sort, open: true))
            ->orderBy('billing_invoices.id')->get();
        $last = Payment::select('paid_at')->whereColumn('invoice_id', 'billing_invoices.id')->where('state', PaymentState::Confirmed)->whereNull('voided_at')->latest('paid_at')->limit(1);
        $paid = $q()->where('state', InvoiceState::Paid)->when($auto, fn ($w) => $w->orderByDesc($last), fn ($w) => self::order($w, $sort, open: false))->orderByDesc('billing_invoices.id');

        return ['claims' => $claims, 'open' => $open, 'tried' => $tried, 'paid' => match (true) {
            $qs !== '' => $paid->limit(50)->get(),
            $history => $paid->paginate(50)->withQueryString(),
            default => collect(),
        }];
    }

    /** Поле сортировки столбца: у открытых сумма — остаток, у оплаченных — итог. */
    private static function order($q, Sort $sort, bool $open)
    {
        $dir = $sort->dir();

        return match ($sort->key) {
            'created' => $q->orderBy('billing_invoices.created_at', $dir),
            'due' => $q->orderByRaw("billing_invoices.due_at {$dir} nulls last"),
            'number' => $q->orderByRaw("billing_invoices.number {$dir} nulls last"),
            'payer' => $q->orderBy(Party::select('name')->whereColumn('billing_parties.id', 'billing_invoices.party_id')->limit(1), $dir),
            'amount' => $q->orderByRaw(($open ? 'greatest(billing_invoices.total - billing_invoices.paid, 0)' : 'billing_invoices.total').' '.$dir),
            default => $q,
        };
    }

    /**
     * Числа пилюль — сколько открытого; tones — где есть дело: не прошла по ссылке, пишет, что оплатил, выплатить. Дело —
     * красной точкой у названия (`pill-todo`, 17.11.2026), а не рыжей заливкой пилюли: блоки цветом не заливаются.
     */
    private static function counts(Facets $facets): array
    {
        $open = fn (string $t) => self::of($t, $facets)->where('state', InvoiceState::Issued);
        // Дело: менеджер пишет, что оплатил, или по ссылке пытались и не вышло — позвонить (ссылки — только у комиссий).
        // Неотправленное менеджеру — тоже дело (18.11.2026).
        $todo = fn (string $t) => $open($t)->whereHas('claims')->exists() || ($t === 'fees' && MoneyLinks::tried())
            || ($t !== 'payouts' && $open($t)->whereNotNull('deal_id')->whereNull('shown_at')->where('paid', '<=', 0)->whereRaw(Invoice::ON_PAY_STEP_SQL)->exists());

        return [
            'counts' => ['fees' => $open('fees')->count(), 'dkp' => $open('dkp')->count(), 'payouts' => $n = $open('payouts')->count()],
            'tones' => array_filter(['fees' => $todo('fees') ? 'pill-todo' : '', 'dkp' => $todo('dkp') ? 'pill-todo' : '', 'payouts' => $n ? 'pill-todo' : '']),
        ];
    }

    /**
     * Люди раздела строками (18.11.2026): кто, сколько всего открытого (неотправленное тоже), что требует нас — отправить,
     * проверить его «оплатил», ссылка не прошла, просрочено; у выплаты — реквизитов нет. Сначала те, где есть дело, потом по сумме.
     * Без менеджера (разовая услуга, получатель не сотрудник) — последней строкой, ключ `none`.
     *
     * @return Collection<int, array{key: string, user: ?User, sum: float, send: int, claimed: float, tried: bool, overdue: float, noDetails: bool}>
     */
    public static function people(string $tab, ?Facets $facets = null): Collection
    {
        $open = self::of($tab, $facets)->where('state', InvoiceState::Issued)
            ->select('billing_invoices.*')->selectRaw(self::personSql($tab).' as person_id')
            ->with(['payments', 'party'])->withSum('claims', 'amount')->get();
        $tried = $tab === 'payouts' ? collect() : self::tried(self::of($tab, $facets))->pluck('billing_invoices.id')->flip();
        $users = User::whereIn('id', $open->pluck('person_id')->filter()->unique())->get()->keyBy('id');

        return $open->groupBy(fn ($i) => $i->person_id && $users->has($i->person_id) ? (string) $i->person_id : 'none')
            ->map(fn (Collection $bills, string $key) => [
                'key' => $key, 'user' => $users->get((int) $key),
                'sum' => round($bills->sum(fn ($i) => $i->remaining()), 2),
                'send' => $tab === 'payouts' ? 0 : $bills->filter(fn ($i) => ! $i->isShownToManager())->count(),
                'claimed' => round((float) $bills->sum('claims_sum_amount'), 2),
                'tried' => $bills->contains(fn ($i) => $tried->has($i->id)),
                'overdue' => round($bills->filter(fn ($i) => $i->isOverdue())->sum(fn ($i) => $i->remaining()), 2),
                'noDetails' => $tab === 'payouts' && $bills->contains(fn ($i) => ! $i->party->payoutReady()),
            ])
            ->sortBy([
                fn ($a, $b) => ($a['key'] === 'none') <=> ($b['key'] === 'none'),
                fn ($a, $b) => (int) ($b['send'] > 0 || $b['claimed'] > 0 || $b['tried']) <=> (int) ($a['send'] > 0 || $a['claimed'] > 0 || $a['tried']),
                fn ($a, $b) => (int) ($b['overdue'] > 0) <=> (int) ($a['overdue'] > 0),
                fn ($a, $b) => $b['sum'] <=> $a['sum'],
            ])->values();
    }

    /** Адрес человека в «Оплатах»: его машины в разделе. */
    public static function personUrl(?User $user, string $tab): string
    {
        return '/work/money/managers/'.($user?->id ?? 'none').'?tab='.$tab;
    }

    /**
     * Страница человека (18.11.2026): его машины в разделе группами — «Отправить» (кнопками «Отправить» и «Уже оплачено» —
     * наличными), «Сообщил об оплате» («Поступила» / «Не поступила»), «Ждём оплату» (просроченные и «ссылка не прошла» сверху),
     * у выплат — «Выплатить»; последней — «Оплачено за 30 дней». Строка — окно машины (`CarWindow`), без машины — карточка счёта.
     */
    public function person(Request $request, string $person)
    {
        $user = $person === 'none' ? null : User::findOrFail((int) $person);
        $detail = Detail::of($request, fn (string $key) => \App\Offers\CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'fees';
        $mine = fn (string $t) => self::of($t)->where(fn ($w) => $user
            ? $w->whereRaw(self::personSql($t).' = ?', [$user->id])
            : $w->whereRaw(self::personSql($t).' is null'));
        $open = $mine($tab)->where('state', InvoiceState::Issued)->with([...self::WITH, 'payLinks.attempts', 'claims'])->orderByRaw('due_at asc nulls last')->orderBy('billing_invoices.id')->get();
        // Один список (18.11.2026, владелец: «разделять на группы не надо, по порядку выставления, не отправленные сверху»):
        // невыставленные — первыми, остальное — по дню выставления, раньше выставленное выше (дольше ждёт).
        $rows = $open->sortBy([
            fn ($a, $b) => (int) $a->isShownToManager() <=> (int) $b->isShownToManager(),
            fn ($a, $b) => ($a->shown_at ?? $a->created_at) <=> ($b->shown_at ?? $b->created_at),
        ])->values();
        $paid = $mine($tab)->where('state', InvoiceState::Paid)->where('paid_at', '>=', now()->subDays(30)->toDateString())->with(self::WITH)->orderByDesc('paid_at')->orderByDesc('billing_invoices.id')->get();
        $attempts = AcquiringPayment::whereIn('payment_id', $paid->flatMap(fn ($i) => $i->payments->pluck('id')))->get()->keyBy('payment_id');
        // Числа у разделов человека — сколько открытого.
        $counts = collect(array_keys(self::TABS))->mapWithKeys(fn ($t) => [$t => $t === $tab ? $open->count() : $mine($t)->where('state', InvoiceState::Issued)->count()])->all();

        return view('admin.money.person', [
            'detail' => $detail, 'user' => $user, 'tab' => $tab, 'counts' => $counts, 'rows' => $rows, 'paid' => $paid, 'attempts' => $attempts,
        ]);
    }

    /** Адрес счёта в «Оплатах» — на своей вкладке, с карточкой (уведомления, Telegram). */
    public static function url(Invoice $i): string
    {
        if ($i->isService() && ($link = $i->payLinks()->reorder()->latest('id')->first())) {
            return self::linkUrl($link);
        }

        return '/work/money?tab='.self::tabOf($i).'&peek='.$i->id;
    }

    /** Ссылка на оплату в «Оплатах» — её карточка на «Ссылки». */
    public static function linkUrl(PayLink $link): string
    {
        return '/work/money?tab=links&peek='.$link->id;
    }

    /** Откуда деньги в ручной оплате: зачёт вознаграждения ставит только система, руками его не выбрать. */
    private static function sources(): array
    {
        return PaymentSource::manual();
    }

    /**
     * Страницы счёта в CRM нет (17.11.2026, владелец: «страница счёта вообще не нужна, удали её везде, там должна быть кнопка на
     * сделку»): прежний адрес — переадресация на машину в сделке или гараже, без машины — на карточку счёта в «Оплатах». Оплата,
     * правка, аннулирование, PDF — те же маршруты, ими живёт карточка.
     */
    public function show(Invoice $invoice)
    {
        $this->guardInvoice($invoice);

        return redirect($invoice->crmHref());
    }

    protected function invoiceView(): string
    {
        return 'billing.invoice-page';
    }

    /** «Мы должны» в CRM — вознаграждение менеджеру: «выплата». */
    protected function invoiceToasts(): array
    {
        return ['owedPaid' => 'Выплачено', 'owedRecorded' => 'Выплата записана', 'unpaid' => 'Отменено', 'voided' => 'Аннулирован'];
    }

    /** Число у «Оплат»: менеджер пишет, что оплатил; не прошла по ссылке; выплатить. Выписки в счёте нет (07.10.2026). */
    public static function todo(): int
    {
        return Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->crmMoney())->count()
            + self::tried(Invoice::crmMoney())->count()
            + Invoice::crmMoney()->where('direction', 'owed')->whereIn('kind', self::PAYOUTS)->where('state', InvoiceState::Issued)->count();
    }

    /**
     * Счёт ждёт денег, а по его открытой ссылке платить пытались и не вышло: банк отклонил, бросили, ЮKassa не открыла
     * оплату (05.10.2026: «непонятно, оплатили или нет») — тут надо позвонить, а не ждать.
     */
    private static function tried($q)
    {
        return $q->where('direction', 'issued')->where('state', InvoiceState::Issued)->whereHas('payLinks', fn ($l) => $l->where('state', PayLinkState::Open)
            ->where(fn ($w) => $w->whereNotNull('error_at')->orWhereHas('attempts', fn ($a) => $a->where('status', 'canceled'))));
    }

    public function detail(Invoice $invoice)
    {
        $invoice->load(['party', 'charges', 'payments', 'claims.media', 'deal.offer', 'deal.offer.media', 'deal.buyer']);

        return view('admin.money.detail', ['invoice' => $invoice, 'sources' => self::sources()]);
    }

    /**
     * Разовая оплата по ссылке: счёт ПРАЙМ на услугу без сделки (`IssueServiceInvoice`) — плательщик из заведённых или
     * новый по имени, почта по желанию (нет — спросит `/pay`). Дальше открывается карточка счёта со ссылкой.
     * Без подключённой ЮKassa ссылки не будет — и счёт не выставляется.
     */
    public function service(Request $request, IssueServiceInvoice $issue, Gateway $gateway)
    {
        abort_unless($gateway->configured(), 422, 'Оплата по ссылке не подключена');
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'], 'title' => ['required', 'string', 'max:200'],
            'party_id' => ['required', Rule::when(fn () => $request->input('party_id') !== 'new', ['exists:billing_parties,id'])],
            'party_name' => ['required_if:party_id,new', 'nullable', 'string', 'max:200'],
            'party_email' => ['nullable', 'email', 'max:120'],
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'], 'car_title' => ['nullable', 'string', 'max:160'],
        ], ['amount.required' => 'Сколько платят', 'title.required' => 'Назовите услугу', 'party_name.required_if' => 'Кто платит',
            'party_email.email' => 'Проверьте почту']);
        $payer = $data['party_id'] === 'new'
            ? ['name' => $data['party_name'], 'email' => $data['party_email'] ?? null]
            : Party::where('is_self', false)->findOrFail($data['party_id']);
        $offer = ! empty($data['offer_id']) ? Offer::find($data['offer_id']) : null;
        try {
            $invoice = $issue($payer, $request->user(), (float) $data['amount'], $data['title'], $offer, $data['car_title'] ?? null, $request->boolean('force'));
        } catch (LinkExists $e) {
            return redirect(self::linkUrl($e->link))->with('toast', 'Ссылка уже есть');
        }
        $link = $invoice->openLink();

        return redirect($link ? self::linkUrl($link) : self::url($invoice))->with(['toast' => 'Ссылка готова'] + ($link ? ['open-link' => $link->id] : []));
    }

    /**
     * ТС для ручной ссылки — подсказка под полем «ТС» (`x-billing.car-field`): номер, ДЛ, VIN, марка, модель; сначала со
     * сделкой, потом свежие, `limit` строк (до 20). Подсказка — номер и чья сделка: по ней ссылку и засчитают. Пустой
     * запрос — пусто: подсказка появляется, когда начали набирать.
     */
    public function cars(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if ($q === '') {
            return response()->json([]);
        }
        $offers = Offer::query()->with(['deal.buyer'])->searchCrm($q)
            ->orderByRaw('exists (select 1 from deals d where d.offer_id = offers.id and '.Deal::ACTIVE_D.') desc')
            ->latest('updated_at')->limit(max(1, min(20, (int) $request->query('limit', 20))))->get();

        return response()->json($offers->map(fn (Offer $o) => [
            'id' => $o->id,
            'label' => trim($o->title().' '.$o->year),
            'hint' => implode(', ', array_filter([OfferNumber::isPublic($o) ? '№ '.$o->number : null, $o->deal?->buyer?->shortName(), $o->deal ? DealCredit::status($o->deal) : null])),
            'payer' => $o->deal?->buyer?->name, 'party_id' => $o->deal?->buyer?->party_id,
        ])->values());
    }

    /** «Ссылка уже есть» в форме, до отправки: по выбранной ТС или этому плательщику (`ExistingLink`). Нет — пусто. */
    public function existing(Request $request)
    {
        $offer = $request->filled('offer_id') ? Offer::find((int) $request->query('offer_id')) : null;
        $party = ctype_digit((string) $request->query('party_id')) ? (int) $request->query('party_id') : null;
        $link = ExistingLink::find($offer, $party, $party ? null : (string) $request->query('party_name'));

        return $link ? view('admin.money.link-exists', ['link' => $link]) : response('');
    }

    /** Карточка ссылки на «Ссылки»: кто платит, ТС, откуда, что с ней, QR и текст, попытки, счёт. */
    public function link(PayLink $link)
    {
        $link->load(MoneyLinks::WITH);

        return view('admin.money.link', ['link' => $link, 'invoice' => $link->invoice]);
    }

    /** Бывший экран «Расчёты с менеджерами»: теперь чип менеджера в «Оплатах» (06.10.2026), адрес жив для закладок. */
    public function managers()
    {
        return redirect('/work/money');
    }

    public function confirm(Request $request, Payment $payment, ConfirmPayment $confirm)
    {
        $confirm($payment, $request->user());

        return back()->with('toast', 'Оплата принята');
    }

    /** «Это за другое»: чек сообщения об оплате ПРАЙМ — на самом деле оплата поставщику (`SwapPaymentProof`, 16.11.2026). */
    public function toSupplier(Request $request, Payment $payment, SwapPaymentProof $swap)
    {
        $swap->toSupplier($payment, $request->user());

        return back()->with('toast', 'Чек перенесли в оплату поставщику');
    }

    public function reject(Request $request, Payment $payment, RejectPayment $reject)
    {
        $reject($payment, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null);

        return back()->with('toast', 'Отмечено: не поступила');
    }

    /**
     * Таблица страницы человека (18.11.2026, владелец выбрал «два этажа, как „Предложения“»): название и под ним что с оплатой,
     * справа сумма и под ней сколько прошло с выставления; кнопка — только у невыставленного, «сообщил об оплате» и выплаты
     * (`x-money.person-row`), ровно своей ширины (`fixed`).
     */
    public static function personTable(): Table
    {
        // Номера убытка нет (владелец: «захотят — нажмут, посмотрят»); на телефоне — два этажа: название и статус слева, сумма и
        // сколько прошло — концами строк (`.line-end`), кнопка — в строке статуса.
        // Ширины — ровно заданные (`fixed`): список и блок «Оплачено» — две таблицы, их столбцы должны стоять одинаково.
        return Table::of(new Column('title', 'Марка, модель', grow: true, fixed: true, rem: 20), new Column('status', 'Статус', from: 'sm', role: ColRole::State, rem: 12, fixed: true),
            // «Выставлено» и кнопка — один столбец (18.11.2026, владелец: «где выставлено — время, где не выставлено — кнопка»).
            new Column('amount', 'Сумма', num: true, cell: 'nums', rem: 7, fixed: true), new Column('since', 'Выставлено', from: 'sm', num: true, role: ColRole::When, cell: 'nums', rem: 7.5, fixed: true));
    }

    /**
     * Таблицы «Оплат» — одно описание на шапку, строки (`x-money.table-row`) и заголовки групп. «Нам
     * должны» — плательщик, менеджер и номер счёта; «Мы должны» — получатель группой, строка — за что.
     */
    public static function table(string $tab): Table
    {
        $created = new Column('created', 'Создано', from: 'sm', aside: true, sort: 'created', cell: 'nums', role: ColRole::When, rem: 6.5);
        $amount = new Column('amount', 'Сумма', num: true, sort: 'amount', cell: 'nums', rem: 7);
        // Счёт и ТС — что это за долг (код, приглушённо), статус — что с ним; менеджер — справа, кто.
        // ТС — одной строкой, длинное режется «…» (17.11.2026: «Lynk & Co / 900 / номер» в три этажа рвали таблицу).
        $car = new Column('car', 'ТС', from: 'sm', role: ColRole::Code, rem: 12, clip: true);
        $status = new Column('status', 'Статус', from: 'sm', sort: 'due', role: ColRole::State, rem: 12);

        return match ($tab) {
            // По машинам, а не по плательщикам (18.11.2026, владелец): название — ТС, менеджер — справа; номера счёта нет — у комиссии
            // счёта нет вовсе.
            'fees', 'dkp' => Table::of(new Column('title', 'Марка, модель', grow: true), $status, new Column('manager', 'Менеджер', from: 'lg', aside: true, role: ColRole::Who, rem: 9), $created, $amount),
            // «Мы должны»: открытые — группами по получателю (менеджер, логист), строка — за что; выплаченные — получателем.
            default => Table::of(new Column('title', 'Кому', grow: true, sort: 'payer'), $car, $status, $created, $amount),
        };
    }
}
