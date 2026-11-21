<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\ExistingLink;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\LinkExists;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\AssignServiceManager;
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
use App\Support\Portion;
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
    public const MANAGER = 'coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id limit 1))';

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
        // Прежние адреса ручной ссылки (уведомления, закладки: `?tab=links&peek=`, `?peek=` счёта) — к её человеку, окном рядом
        // (22.11.2026, владелец: «кликаешь — выкидывает обратно в менеджеров и открывается устаревшее окно»).
        if (($legacy = self::legacy($request)) !== null) {
            return redirect($legacy);
        }
        // Ключ карточки с `tab=links` — ссылка (строка ссылки в карточке счёта); иначе — счёт. Номера ссылок и счетов
        // пересекаются, угадывать по одному числу нельзя.
        $detail = Detail::of($request, fn (string $key) => $request->query('tab') === 'links'
            ? (($link = PayLink::find($key)) ? $this->link($link) : null)
            : (($invoice = Invoice::find($key)) ? $this->card($invoice) : null));
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
        // Чип «Статус» помнится один на «Оплаты» — раздел и страница человека (22.11.2026); «Оплаченных» больше нет.
        ListPrefs::sync($request, 'crm-money', keep: ['stage']);
        $facets = self::facets($request, $tab);
        if ($tab !== 'payouts') {
            $facets->apply(MoneyBook::deals($tab, false));
        }
        $qs = trim((string) $request->query('q'));
        // Лупа — по всем разделам сразу (мимо пилюли и чипов), открытые и оплаченные.
        $sort = fn (string $t) => Sort::from($request->query('sort'), self::sorts($t), '-auto');
        $sections = $qs === '' ? [] : collect(array_keys(self::TABS))->mapWithKeys(fn ($t) => [$t => $this->section($t, $qs, $sort($t))])->all();
        $paid = collect($sections)->flatMap(fn ($s) => $s['paid'])->flatMap(fn ($i) => $i->payments->pluck('id'));

        return view('admin.money.index', [
            'detail' => $detail, 'facets' => $facets, 'q' => $qs, 'tab' => $tab, 'sections' => $sections, 'sort' => $sort($tab),
            // Числа «Комиссий» и «ДКП» — по чипу «Статус» и на вкладке «Выплат» (у неё своего чипа нет).
            'counts' => self::counts($tab === 'payouts' ? MoneyBook::facets($request) : $facets), 'attempts' => AcquiringPayment::whereIn('payment_id', $paid)->get()->keyBy('payment_id'),
            // Без лупы — люди строками (18.11.2026): внутри человека — его сделки.
            'people' => $qs === '' ? self::people($tab, $facets) : null,
        ]);
    }

    /** Прежний адрес ручной ссылки или её счёта в разделе — новый адрес у её человека; иначе null. */
    private static function legacy(Request $request): ?string
    {
        $peek = (string) $request->query('peek');
        if (! ctype_digit($peek) || ! in_array($request->query('tab'), ['links', 'owed'], true) || $request->header('Turbo-Frame') === Detail::FRAME) {
            return null;
        }
        $invoice = $request->query('tab') === 'links' ? PayLink::find((int) $peek)?->invoice : Invoice::find((int) $peek);

        return $invoice?->isService() ? self::url($invoice) : null;
    }

    /** Карточка счёта: у разовой оплаты — её последняя ссылка, у остальных — счёт. */
    private function card(Invoice $invoice)
    {
        return ($invoice->isService() && ($link = $invoice->payLinks()->reorder()->latest('id')->first())) ? $this->link($link) : $this->detail($invoice);
    }

    /**
     * Чипы раздела: у «Комиссий» и «ДКП» — «Статус» сделки (`MoneyStage`, 22.11.2026; по умолчанию — после «Беру в работу»), у
     * «Выплат» чипов нет. «Оплаченных» и «Откуда» больше нет: у человека все его сделки, оплаченные — лентой ниже.
     */
    private static function facets(Request $request, string $tab): Facets
    {
        return $tab === 'payouts' ? Facets::for($request, 'crm-money') : MoneyBook::facets($request);
    }

    /**
     * Счета раздела для лупы и чисел: «Комиссии» — наши выставленные, кроме счетов за машину (подбор, разовые услуги); «ДКП» —
     * счета за машину от ПРАЙМ и Р.О.С. (сделки и гараж); «Выплаты» — перевозки.
     */
    public static function of(string $tab, ?Facets $facets = null)
    {
        $q = Invoice::crmMoney();
        // Неотправленный менеджеру счёт сделки — только на шаге оплаты (`Invoice::ON_PAY_STEP_SQL`): так его ищет лупа и считает
        // вкладка «Оплата за подбор» (`SelectionController`). Страница человека видит все сделки сама (`MoneyBook`, 22.11.2026).
        $ours = fn () => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'issued')
            ->where(fn ($w) => $w->where(fn ($x) => $x->shownToManager())->orWhere(fn ($x) => $x->whereRaw(Invoice::ON_PAY_STEP_SQL)));

        return match ($tab) {
            'dkp', 'fees' => $ours()->whereRaw(MoneyBook::tabSql($tab)),
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
     * Лупа по разделу группами: claims — менеджер пишет, что оплатил (не у выплат); open — ждём, сверху то, где по ссылке
     * пытались и не вышло; paid — оплаченные свежими сверху, до 50.
     */
    private function section(string $tab, string $qs, Sort $sort): array
    {
        $q = fn () => self::of($tab)->search($qs)->with(self::WITH);
        $claims = in_array($tab, self::OWED, true)
            ? Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereIn('billing_invoices.id', self::of($tab)->search($qs)->select('billing_invoices.id')))
                ->with(['invoice' => fn ($i) => $i->with(self::WITH)])->orderBy('paid_at')->orderBy('id')->get()
            : collect();
        $tried = $tab === 'payouts' ? collect() : self::tried(self::of($tab)->search($qs))->pluck('billing_invoices.id');
        $auto = $sort->key === 'auto';
        $open = $q()->where('state', InvoiceState::Issued)->with('payLinks.attempts')
            ->when(in_array($tab, self::OWED, true), fn ($w) => $w->whereDoesntHave('claims'))
            ->when($auto && $tried->isNotEmpty(), fn ($w) => $w->orderByRaw('billing_invoices.id in ('.$tried->map(fn ($id) => (int) $id)->implode(',').') desc'))
            ->when($auto, fn ($w) => $w->orderByRaw('due_at asc nulls last'), fn ($w) => self::order($w, $sort, open: true))
            ->orderBy('billing_invoices.id')->get();
        $last = Payment::select('paid_at')->whereColumn('invoice_id', 'billing_invoices.id')->where('state', PaymentState::Confirmed)->whereNull('voided_at')->latest('paid_at')->limit(1);
        $paid = $q()->where('state', InvoiceState::Paid)->when($auto, fn ($w) => $w->orderByDesc($last), fn ($w) => self::order($w, $sort, open: false))->orderByDesc('billing_invoices.id');

        return ['claims' => $claims, 'open' => $open, 'tried' => $tried, 'paid' => $paid->limit(50)->get()];
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
     * Числа разделов — сколько ждём денег (сделки с неоплаченным счётом раздела по чипу «Статус», ручные ссылки, выплаты);
     * tones — где есть дело: пишет, что оплатил, не прошла по ссылке, не выставлено, выплатить. Дело — красной точкой у названия
     * (`pill-todo`, 17.11.2026), а не заливкой пилюли.
     *
     * @return array{counts: array<string, int>, tones: array<string, string>}
     */
    private static function counts(Facets $facets, User|null|false $person = false): array
    {
        $counts = $tones = [];
        foreach (array_keys(self::TABS) as $t) {
            if ($t === 'payouts') {
                $n = self::payouts($person)->where('state', InvoiceState::Issued)->count();
                [$counts[$t], $tones[$t]] = [$n, $n ? 'pill-todo' : ''];

                continue;
            }
            $open = MoneyBook::openDeals($t, $person, $facets);
            $services = $t === 'fees' ? MoneyBook::services($person, open: true) : null;
            $counts[$t] = (clone $open)->count() + ($services ? (clone $services)->count() : 0);
            $todo = (clone $open)->whereRaw(MoneyBook::hasTabInvoice($t, open: true, extra: "(bi.shown_at is null and bi.paid <= 0 or exists (select 1 from billing_payments bp where bp.invoice_id = bi.id and bp.state = 'claimed'))"))->exists()
                || ($t === 'fees' && MoneyLinks::tried());
            $tones[$t] = $todo ? 'pill-todo' : '';
        }

        return ['counts' => $counts, 'tones' => array_filter($tones)];
    }

    /** Выплаты раздела этого человека (`false` — все, `null` — получатель не сотрудник). */
    private static function payouts(User|null|false $person)
    {
        return self::of('payouts')->when($person !== false, fn ($q) => $person
            ? $q->whereRaw(self::RECIPIENT.' = ?', [$person->id])
            : $q->whereRaw(self::RECIPIENT.' is null'));
    }

    /**
     * Люди раздела строками (18.11.2026): кто, сколько всего ждём (неотправленное тоже), что требует нас — отправить, проверить
     * его «оплатил», ссылка не прошла, просрочено; у выплаты — реквизитов нет. Сначала те, где есть дело, потом по сумме, потом
     * те, у кого всё оплачено (22.11.2026: «Оплаченных» больше нет — история у человека), по имени. Без менеджера (разовая
     * услуга, аукцион, получатель не сотрудник) — последней строкой, ключ `none`.
     *
     * @return Collection<int, array{key: string, user: ?User, sum: float, send: int, claimed: float, tried: bool, overdue: float, noDetails: bool}>
     */
    public static function people(string $tab, ?Facets $facets = null): Collection
    {
        $tried = self::tried(Invoice::crmMoney())->pluck('billing_invoices.id')->flip();
        if ($tab === 'payouts') {
            $rows = self::payouts(false)->where('state', InvoiceState::Issued)->select('billing_invoices.*')->selectRaw(self::RECIPIENT.' as person_id')
                ->with(['payments', 'party', 'claims'])->get()->map(fn (Invoice $i) => MoneyRow::payout($i)->setPerson($i->person_id));
            $history = self::payouts(false)->where('state', InvoiceState::Paid)->selectRaw(self::RECIPIENT.' as person_id')->distinct()->pluck('person_id');
        } else {
            $rows = MoneyBook::open($tab, false, $facets);
            $history = MoneyBook::paid($tab, false, $facets)->reorder()->select('deals.buyer_id')->distinct()->pluck('buyer_id')
                ->concat($tab === 'fees' ? MoneyBook::services(false, open: false)->reorder()->select([])->selectRaw('coalesce('.MoneyBook::SERVICE_PERSON.', billing_invoices.manager_id) as pid')->distinct()->pluck('pid') : []);
        }
        $key = fn ($id) => $id ? (string) $id : 'none';
        $users = User::whereIn('id', $rows->map(fn (MoneyRow $r) => $r->personId())->concat($history)->filter()->unique())->get()->keyBy('id');
        $known = fn (string $k) => $k !== 'none' && $users->has((int) $k) ? $k : 'none';
        $people = $rows->groupBy(fn (MoneyRow $r) => $known($key($r->personId())))
            ->map(fn (Collection $rs, string $k) => [
                'key' => $k, 'user' => $users->get((int) $k),
                'sum' => round($rs->sum(fn (MoneyRow $r) => $r->amount()), 2),
                'send' => $tab === 'payouts' ? 0 : $rs->filter(fn (MoneyRow $r) => $r->unsent()->isNotEmpty())->count(),
                'claimed' => round($rs->sum(fn (MoneyRow $r) => $r->claimed()), 2),
                'tried' => $rs->contains(fn (MoneyRow $r) => $r->open()->contains(fn (Invoice $i) => $tried->has($i->id))),
                'overdue' => round($rs->sum(fn (MoneyRow $r) => $r->overdue()), 2),
                'noDetails' => $tab === 'payouts' && $rs->contains(fn (MoneyRow $r) => ! $r->service->party->payoutReady()),
            ]);
        foreach ($history->map(fn ($id) => $known($key($id)))->unique() as $k) {
            $people[$k] ??= ['key' => $k, 'user' => $users->get((int) $k), 'sum' => 0.0, 'send' => 0, 'claimed' => 0.0, 'tried' => false, 'overdue' => 0.0, 'noDetails' => false];
        }

        return $people->sortBy([
            fn ($a, $b) => ($a['key'] === 'none') <=> ($b['key'] === 'none'),
            fn ($a, $b) => (int) ($b['send'] > 0 || $b['claimed'] > 0 || $b['tried']) <=> (int) ($a['send'] > 0 || $a['claimed'] > 0 || $a['tried']),
            fn ($a, $b) => (int) ($b['overdue'] > 0) <=> (int) ($a['overdue'] > 0),
            fn ($a, $b) => (int) ($b['sum'] > 0) <=> (int) ($a['sum'] > 0),
            fn ($a, $b) => $b['sum'] <=> $a['sum'],
            fn ($a, $b) => (string) $a['user']?->name <=> (string) $b['user']?->name,
        ])->values();
    }

    /** Адрес человека в «Оплатах»: его сделки в разделе. */
    public static function personUrl(?User $user, string $tab): string
    {
        return '/work/money/managers/'.($user?->id ?? 'none').'?tab='.$tab;
    }

    /**
     * Страница человека (18.11.2026; с 22.11.2026 — все его сделки, владелец: «отображались ВСЕ сделки, столбец „Статус“ — этап, и
     * фильтр»; «„Оплаченные“ — тут же, за всю историю, бесконечной лентой»). Строка — сделка со всеми её счетами раздела
     * (`MoneyBook`, `MoneyRow`): группы «Не выставлены» (и счёта ещё нет — словом, чего не хватает), «Выставлены», «Оплачены»
     * порциями по сотне. Строка — окно машины рядом (`CarWindow`), ручная ссылка и счёт без машины — его карточка (`s{счёт}`).
     */
    public function person(Request $request, string $person)
    {
        $user = $person === 'none' ? null : User::findOrFail((int) $person);
        $detail = Detail::of($request, fn (string $key) => str_starts_with($key, 's')
            ? (($i = Invoice::crmMoney()->find((int) substr($key, 1))) ? $this->card($i) : null)
            : \App\Offers\CarWindow::of($request, OfferNumber::find($key)), invoices: true);
        if ($detail->framed()) {
            return $detail->response();
        }
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'fees';
        ListPrefs::sync($request, 'crm-money', keep: ['stage']);
        $facets = self::facets($request, $tab);
        $qs = trim((string) $request->query('q'));
        if ($tab === 'payouts') {
            $mine = fn () => self::payouts($user)->search($qs)->with([...self::WITH, 'payLinks.attempts', 'claims']);
            $open = $mine()->where('state', InvoiceState::Issued)->orderByRaw('due_at asc nulls last')->orderBy('billing_invoices.id')->get()->map(fn (Invoice $i) => MoneyRow::payout($i));
            $paidQ = $mine()->where('state', InvoiceState::Paid)->orderByRaw('paid_at desc nulls last');
            $portion = Portion::of($request, $paidQ, (clone $paidQ)->reorder()->count());
            $paid = collect($portion->items())->map(fn (Invoice $i) => MoneyRow::payout($i));
        } else {
            $facets->apply(MoneyBook::deals($tab, $user, $qs));
            $open = MoneyBook::open($tab, $user, $facets, $qs);
            $paidQ = MoneyBook::paid($tab, $user, $facets, $qs);
            $portion = Portion::of($request, $paidQ, (clone $paidQ)->reorder()->count());
            $paid = MoneyBook::paidRows($portion->items());
            // Оплаченные ручные ссылки без сделки — их единицы: в первую порцию, по дню оплаты среди сделок.
            if ($tab === 'fees' && $portion->currentPage() === 1) {
                $paid = $paid->concat(MoneyBook::services($user, open: false)->search($qs)->get()->map(fn (Invoice $i) => MoneyRow::service($i)))
                    ->sortByDesc(fn (MoneyRow $r) => $r->paidAt()?->timestamp ?? 0)->values();
            }
        }
        $attempts = AcquiringPayment::whereIn('payment_id', $paid->flatMap(fn (MoneyRow $r) => $r->invoices->flatMap(fn ($i) => $i->payments->pluck('id'))))->get()->keyBy('payment_id');
        $tried = self::tried(Invoice::crmMoney())->pluck('billing_invoices.id')->flip();

        return view('admin.money.person', [
            'detail' => $detail, 'user' => $user, 'tab' => $tab, 'counts' => self::counts($tab === 'payouts' ? MoneyBook::facets($request) : $facets, $user)['counts'], 'facets' => $facets, 'q' => $qs,
            'rows' => $open, 'paid' => $paid, 'portion' => $portion, 'attempts' => $attempts, 'tried' => $tried,
        ]);
    }

    /**
     * Адрес счёта в «Оплатах» (уведомления, Telegram): разовая оплата — окном у своего человека (22.11.2026, `s{счёт}`), прочие —
     * карточкой счёта на своей вкладке.
     */
    public static function url(Invoice $i): string
    {
        if ($i->isService()) {
            return self::personUrl($i->manager(), 'fees').'&peek=s'.$i->id;
        }

        return '/work/money?tab='.self::tabOf($i).'&peek='.$i->id;
    }

    /** Ссылка на оплату в «Оплатах»: ручная — окно её счёта у человека, ссылка сделки — её карточка. */
    public static function linkUrl(PayLink $link): string
    {
        return $link->invoice?->isService() ? self::url($link->invoice) : '/work/money?tab=links&peek='.$link->id;
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

    /**
     * Модератору — только счета сделок (18.11.2026, владелец: «полный доступ ко всему, что касается договоров и счетов»): оплата,
     * аннулирование, срок, заявки менеджера, ссылки у счёта. Счёт без сделки (парковка, разовые ссылки) — админу.
     */
    public static function guardDeal(?Invoice $invoice): void
    {
        abort_unless(auth()->user()?->canManageCrm() || ($invoice && ($invoice->deal_id || $invoice->credit_deal_id)), 403);
    }

    protected function guardInvoice(Invoice $invoice): void
    {
        self::guardDeal($invoice);
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
     * Разовая оплата по ссылке: счёт ПРАЙМ на услугу без сделки (`IssueServiceInvoice`). Плательщика админ не указывает
     * (22.11.2026, владелец: «марку, модель и сумму указываем, а плательщика они сами — когда по ссылке переходят»): машина, сумма,
     * услуга и чья ссылка — менеджер (у машины со сделкой — её менеджер сам); почту для чека спрашивает `/pay`. После — туда же,
     * откуда создавали (`back`: раздел или страница человека), с карточкой ссылки рядом и её шторкой «Скопировать / Отправить».
     * Без подключённой ЮKassa ссылки не будет — и счёт не выставляется.
     */
    public function service(Request $request, IssueServiceInvoice $issue, Gateway $gateway)
    {
        abort_unless($gateway->configured(), 422, 'Оплата по ссылке не подключена');
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'], 'title' => ['required', 'string', 'max:200'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->whereJsonContains('roles', 'manager'))],
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'], 'car_title' => ['nullable', 'string', 'max:160'],
            'back' => ['nullable', 'string', 'max:300'],
        ], ['amount.required' => 'Сколько платят', 'title.required' => 'Назовите услугу', 'manager_id.exists' => 'Это не менеджер']);
        $offer = ! empty($data['offer_id']) ? Offer::find($data['offer_id']) : null;
        $manager = ! empty($data['manager_id']) ? User::find($data['manager_id']) : null;
        try {
            $invoice = $issue(null, $request->user(), (float) $data['amount'], $data['title'], $offer, $data['car_title'] ?? null, $request->boolean('force'), $manager);
        } catch (LinkExists $e) {
            return redirect(self::linkUrl($e->link))->with('toast', 'Ссылка уже есть');
        }
        $link = $invoice->openLink();
        // Остаться на месте (владелец): на странице человека — она же с окном ссылки, в разделе — раздел с ним.
        $back = (string) ($data['back'] ?? '');
        $here = str_starts_with($back, '/work/money/managers/')
            ? strtok($back, '?').'?'.http_build_query(['tab' => 'fees', 'peek' => 's'.$invoice->id])
            : '/work/money?'.http_build_query(['tab' => 'fees', 'peek' => $invoice->id]);

        return redirect($here)->with(['toast' => 'Ссылка готова'] + ($link ? ['open-link' => $link->id] : []));
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
            'payer' => $o->deal?->buyer?->name, 'party_id' => $o->deal?->buyer?->party_id, 'manager_id' => $o->deal?->buyer_id,
        ])->values());
    }

    /** «Ссылка уже есть» в форме, до отправки: по выбранной ТС (плательщика форма не спрашивает с 22.11.2026). Нет — пусто. */
    public function existing(Request $request)
    {
        $offer = $request->filled('offer_id') ? Offer::find((int) $request->query('offer_id')) : null;
        $link = $offer ? ExistingLink::forOffer($offer) : null;

        return $link ? view('admin.money.link-exists', ['link' => $link]) : response('');
    }

    /**
     * «Назначить менеджера» ручной ссылке (22.11.2026, `AssignServiceManager`): менеджер и, по желанию, ТС его сделки — тогда
     * оплата засчитывается в её счета и строка уходит в сделку. Пусто — снова «Без менеджера».
     */
    public function assign(Request $request, Invoice $invoice, AssignServiceManager $assign)
    {
        $data = $request->validate([
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->whereJsonContains('roles', 'manager'))],
            'offer_id' => ['nullable', 'integer', 'exists:offers,id'],
        ], ['manager_id.exists' => 'Это не менеджер']);
        $offer = ! empty($data['offer_id']) ? Offer::find($data['offer_id']) : null;
        $invoice = $assign($invoice, ! empty($data['manager_id']) ? User::find($data['manager_id']) : null, $offer, $request->user());

        return back()->with('toast', match (true) {
            (bool) $invoice->credit_deal_id => 'Засчитано в сделку',
            (bool) $invoice->manager_id => 'Менеджер: '.$invoice->assignedManager->shortName(),
            default => 'Без менеджера',
        });
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
        self::guardDeal($payment->invoice);
        $confirm($payment, $request->user());

        return back()->with('toast', 'Оплата принята');
    }

    /** «Это за другое»: чек сообщения об оплате ПРАЙМ — на самом деле оплата поставщику (`SwapPaymentProof`, 16.11.2026). */
    public function toSupplier(Request $request, Payment $payment, SwapPaymentProof $swap)
    {
        self::guardDeal($payment->invoice);
        $swap->toSupplier($payment, $request->user());

        return back()->with('toast', 'Чек перенесли в оплату поставщику');
    }

    public function reject(Request $request, Payment $payment, RejectPayment $reject)
    {
        self::guardDeal($payment->invoice);
        $reject($payment, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null);

        return back()->with('toast', 'Отмечено: не поступила');
    }

    /**
     * Таблица страницы человека (18.11.2026, владелец выбрал «два этажа, как „Предложения“»; 22.11.2026 — строка-сделка):
     * название, «Статус» — этап сделки (`MoneyStage`), «Оплата» — что с деньгами словом, сумма, сколько прошло с выставления
     * и на ПК — кнопки (владелец: «„Выставить“, „Оплатил наличными“ / „Напомнить“, „Назначить менеджера“ — без необходимости
     * проваливаться внутрь»). Ширины ровно заданные (`fixed`): открытые и лента оплаченных догружаются кусками, столбцы стоят одинаково.
     */
    public static function personTable(bool $payout = false): Table
    {
        // На телефоне — два этажа: название и сумма, под ними этап (или тревога оплаты) и сколько прошло; кнопка — одна, в строке.
        return Table::of(new Column('title', $payout ? 'За что' : 'Марка, модель', grow: true, fixed: true, rem: 18),
            $payout ? null : new Column('stage', 'Статус', from: 'sm', role: ColRole::State, rem: 14, fixed: true),
            new Column('pay', $payout ? 'Статус' : 'Оплата', from: 'sm', role: ColRole::State, rem: 11, fixed: true),
            new Column('amount', 'Сумма', num: true, cell: 'nums', rem: 7, fixed: true),
            new Column('since', 'Выставлено', from: 'sm', num: true, role: ColRole::When, cell: 'nums', rem: 6.5, fixed: true),
            // С окном справа кнопок нет (`aside`): действие — в самом окне.
            new Column('acts', '', from: 'lg', num: true, aside: true, role: ColRole::When, rem: 17, fixed: true));
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
