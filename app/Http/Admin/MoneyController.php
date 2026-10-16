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
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Http\Billing\InvoiceActions;
use App\Offers\DealCredit;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Money;
use App\Support\Sort;
use App\Users\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * «Оплаты» в CRM — три вкладки по трём вопросам начальника (07.10.2026, владелец: «начальник не догадается вниз
 * пролистать»; до того 06.10 — лента «Надо сделать / Ждём деньги / История»):
 * - «По ссылкам» — все ссылки на оплату одним списком (09.10.2026, `MoneyLinks`): сделок, гаража, взятые менеджером и
 *   кнопкой «Создать ссылку», свежие сверху; карточка — сама ссылка (`?tab=links&peek={ссылка}`);
 * - «Нам должны» — счета менеджерам и их покупателям по сделкам и гаражу: пишет, что оплатил; ждём; оплачено;
 * - «Мы должны» — вознаграждения менеджерам и перевозки (партнёру, перевозчику): выплатить и выплачено.
 * Выписки СберБизнеса здесь нет — она в Настройки → Банк. Чип менеджера — на двух последних (один выбран — его расчёт
 * строкой сверху), лупа ищет во всех трёх. Карточка строки — счёт рядом, общая со стоянкой.
 */
class MoneyController
{
    use InvoiceActions;

    public const TABS = ['links' => 'По ссылкам', 'owed' => 'Нам должны', 'payouts' => 'Мы должны'];

    /** «Мы должны»: вознаграждения менеджерам и перевозки партнёрам и перевозчикам (логистика, 10.10.2026). */
    public const PAYOUTS = [ChargeKind::AgentFee, ChargeKind::Tow];

    /**
     * Сортировка вкладки (07.10.2026): по умолчанию «Срочное» — ждём сверху тех, где по ссылке не вышло, дальше по сроку,
     * оплаченное свежим; иначе поле столбца в обеих группах. «Проверить» не сортируется — это дела.
     */
    public static function sorts(string $tab): array
    {
        return ['auto' => ['Срочное', 'desc'], 'created' => ['Создано', 'desc'], 'due' => ['Срок', 'asc']]
            + ($tab === 'owed' ? ['number' => ['Счёт', 'asc']] : [])
            + ['payer' => [$tab === 'payouts' ? 'Менеджер' : 'Плательщик', 'asc'], 'amount' => ['Сумма', 'desc']];
    }

    /** Менеджер счёта: покупатель сделки, у гаражной — держатель машины. */
    private const MANAGER = 'coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id or g.payout_invoice_id = billing_invoices.id limit 1))';

    private const WITH = ['party', 'charges', 'payments', 'offer', 'deal.offer', 'deal.buyer', 'garageCar.manager', 'garageCar.offer', 'garagePayoutCar.manager', 'garagePayoutCar.offer'];

    public function index(Request $request)
    {
        // Ключ карточки на «По ссылкам» — ссылка (строки ссылок всегда несут `tab=links`, и нажатие мимо имени тоже —
        // `detail_controller#urlFor`); без вкладки — счёт, у разовой оплаты — её последняя ссылка: старые адреса
        // `?peek={счёт}` в уведомлениях. Номера ссылок и счетов пересекаются, угадывать по одному числу нельзя.
        $detail = Detail::of($request, fn (string $key) => $request->query('tab') === 'links'
            ? (($link = PayLink::find($key)) ? $this->link($link) : null)
            : (($invoice = Invoice::find($key)) ? (($invoice->isService() && ($link = $invoice->payLinks()->reorder()->latest('id')->first())) ? $this->link($link) : $this->detail($invoice)) : null));
        if ($detail->framed()) {
            return $detail->response();
        }
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'links';
        $facets = Facets::for($request, 'crm-money', Common::manager(self::MANAGER));
        $links = MoneyLinks::facets($request);
        ListPrefs::sync($request, 'crm-money', keep: $tab === 'links' ? $links->keys() : $facets->keys());
        // Варианты чипа — менеджеры счетов двух вкладок с менеджером («Нам должны», «Мы должны»). На «По ссылкам» свои
        // чипы (`MoneyLinks::facets`): «сколько будет» из их шторки отвечает там.
        $tab === 'links' || $facets->apply(Invoice::crmMoney()->where(fn ($w) => $w->where(fn ($x) => $x->where('direction', 'issued')->whereNot(self::oneOff()))
            ->orWhere(fn ($x) => $x->where('direction', 'owed')->whereIn('kind', self::PAYOUTS))));
        $qs = trim((string) $request->query('q'));
        // Лупа — по всем трём вкладкам сразу (мимо пилюли), без страниц у оплаченного.
        $tabs = $qs === '' ? [$tab] : array_keys(self::TABS);
        $sort = fn (string $t) => $t === 'links' ? Sort::from($request->query('sort'), MoneyLinks::sorts(), '-created') : Sort::from($request->query('sort'), self::sorts($t), '-auto');
        $sections = collect($tabs)->mapWithKeys(fn ($t) => [$t => $t === 'links'
            ? ['links' => MoneyLinks::list($links, $qs, $sort($t))]
            : $this->section($t, $facets, $qs, $sort($t))])->all();
        $paid = collect($sections)->except('links')->flatMap(fn ($s) => $s['paid'] instanceof Collection ? $s['paid'] : $s['paid']->items())->flatMap(fn ($i) => $i->payments->pluck('id'));

        // Выбран один менеджер — его расчёт строкой сверху (бывший экран «Расчёты с менеджерами»).
        $picked = $qs === '' && $tab !== 'links' && count($ids = $facets->selected('manager')) === 1 ? User::find((int) $ids[0]) : null;

        return view('admin.money.index', [
            'detail' => $detail, 'facets' => $tab === 'links' ? $links : $facets, 'q' => $qs, 'tab' => $tab, 'sections' => $sections, 'sort' => $sort($tab),
            'counts' => self::counts($facets), 'attempts' => AcquiringPayment::whereIn('payment_id', $paid)->get()->keyBy('payment_id'),
            'manager' => $picked ? ['user' => $picked, 'position' => (new ManagerLedger($picked, staff: true))->position()] : null,
        ]);
    }

    /**
     * Счета вкладки: нам по сделкам и гаражу, что платят по счёту (с чипом менеджера), выплаты менеджерам (с чипом).
     * Счёт со ссылкой на оплату живёт в «По ссылкам» (`MoneyLinks`), здесь его нет (09.10.2026, владелец: «тут должны быть
     * ссылки, а там то, что по счёту»); `links` — с ними: заявка менеджера «оплатил» по такому счёту всё равно здесь.
     */
    private static function of(string $tab, ?Facets $facets = null, bool $links = false)
    {
        $q = Invoice::crmMoney();

        return match ($tab) {
            'owed' => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'issued')->whereNot(self::oneOff())->when(! $links, fn ($w) => $w->whereDoesntHave('payLinks')),
            default => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'owed')->whereIn('kind', self::PAYOUTS),
        };
    }

    /** Разовая оплата по ссылке — счёт услуги без сделки и ТС (`Invoice::isService`). */
    private static function oneOff(): Closure
    {
        return fn ($w) => $w->where('billing_invoices.kind', ChargeKind::Service)->whereNull('billing_invoices.deal_id')->whereNull('billing_invoices.vehicle_id');
    }

    /**
     * Вкладка группами: claims — менеджер пишет, что оплатил (только «Нам должны»); open — ждём, сверху то, где по ссылке
     * пытались и не вышло; paid — оплаченные свежими сверху, по 50 на страницу.
     */
    private function section(string $tab, Facets $facets, string $qs, Sort $sort): array
    {
        // Лупа — `Invoice::search` (пустой запрос — без отбора); без неё у оплаченного страницы.
        $q = fn () => self::of($tab, $facets)->search($qs)->with(self::WITH);
        $claims = $tab === 'owed'
            ? Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereIn('billing_invoices.id', self::of('owed', $facets, links: true)->search($qs)->select('billing_invoices.id')))
                ->with(['invoice' => fn ($i) => $i->with(self::WITH)])->orderBy('paid_at')->orderBy('id')->get()
            : collect();
        $tried = $tab === 'payouts' ? collect() : self::tried(self::of($tab, $facets)->search($qs))->pluck('billing_invoices.id');
        $auto = $sort->key === 'auto';
        $open = $q()->where('state', InvoiceState::Issued)->with('payLinks.attempts')
            ->when($tab === 'owed', fn ($w) => $w->whereDoesntHave('claims'))
            ->when($auto && $tried->isNotEmpty(), fn ($w) => $w->orderByRaw('billing_invoices.id in ('.$tried->map(fn ($id) => (int) $id)->implode(',').') desc'))
            ->when($auto, fn ($w) => $w->orderByRaw('due_at asc nulls last'), fn ($w) => self::order($w, $sort, open: true))
            ->orderBy('billing_invoices.id')->get();
        $last = Payment::select('paid_at')->whereColumn('invoice_id', 'billing_invoices.id')->where('state', PaymentState::Confirmed)->whereNull('voided_at')->latest('paid_at')->limit(1);
        $paid = $q()->where('state', InvoiceState::Paid)->when($auto, fn ($w) => $w->orderByDesc($last), fn ($w) => self::order($w, $sort, open: false))->orderByDesc('billing_invoices.id');

        return ['claims' => $claims, 'open' => $open, 'tried' => $tried, 'paid' => $qs === '' ? $paid->paginate(50)->withQueryString() : $paid->limit(50)->get()];
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

    /** Числа пилюль — сколько открытого; tones — где есть дело: не прошла по ссылке, пишет, что оплатил, выплатить. */
    private static function counts(Facets $facets): array
    {
        $open = fn (string $t) => self::of($t, $facets)->where('state', InvoiceState::Issued);

        return [
            'counts' => ['links' => MoneyLinks::openCount(), 'owed' => $open('owed')->count(), 'payouts' => $n = $open('payouts')->count()],
            'tones' => array_filter([
                'links' => MoneyLinks::tried() ? 'pill-urgent' : '',
                'owed' => self::of('owed', $facets, links: true)->where('state', InvoiceState::Issued)->whereHas('claims')->exists() ? 'pill-urgent' : '',
                'payouts' => $n ? 'pill-urgent' : '',
            ]),
        ];
    }

    /** Адрес счёта в «Оплатах» — на своей вкладке, с карточкой (уведомления, Telegram). */
    public static function url(Invoice $i): string
    {
        if ($i->isService() && ($link = $i->payLinks()->reorder()->latest('id')->first())) {
            return self::linkUrl($link);
        }

        return '/work/money?tab='.($i->isOwed() ? 'payouts' : 'owed').'&peek='.$i->id;
    }

    /** Ссылка на оплату в «Оплатах» — её карточка на «По ссылкам». */
    public static function linkUrl(PayLink $link): string
    {
        return '/work/money?tab=links&peek='.$link->id;
    }

    /** Откуда деньги в ручной оплате: зачёт вознаграждения ставит только система, руками его не выбрать. */
    private static function sources(): array
    {
        return PaymentSource::manual();
    }

    protected function invoiceView(): string
    {
        return 'admin.money.invoice';
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
            ->orderByRaw('exists (select 1 from deals d where d.offer_id = offers.id and d.state = ?) desc', [DealState::Active->value])
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

    /** Карточка ссылки на «По ссылкам»: кто платит, ТС, откуда, что с ней, QR и текст, попытки, счёт. */
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

    public function reject(Request $request, Payment $payment, RejectPayment $reject)
    {
        $reject($payment, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null);

        return back()->with('toast', 'Отмечено: не поступила');
    }
}
