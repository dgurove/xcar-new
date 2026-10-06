<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Actions\ConfirmPayment;
use App\Billing\Actions\IssueServiceInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\RejectPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Money;
use App\Support\Nav;
use App\Users\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * «Оплаты» в CRM — три вкладки по трём вопросам начальника (07.10.2026, владелец: «начальник не догадается вниз
 * пролистать»; до того 06.10 — лента «Надо сделать / Ждём деньги / История»):
 * - «По ссылкам» — разовые оплаты, что мы взяли кнопкой «Взять ссылку на оплату»: ждём (что со ссылкой) и оплачено;
 * - «Нам должны» — счета менеджерам и их покупателям по сделкам и гаражу: пишет, что оплатил; ждём; оплачено;
 * - «Мы должны» — вознаграждения менеджерам: выплатить и выплачено.
 * Выписки СберБизнеса здесь нет — она в Настройки → Банк. Чип менеджера — на двух последних (один выбран — его расчёт
 * строкой сверху), лупа ищет во всех трёх. Карточка строки — счёт рядом, общая со стоянкой.
 */
class MoneyController
{
    public const TABS = ['links' => 'По ссылкам', 'owed' => 'Нам должны', 'payouts' => 'Мы должны'];

    /** Менеджер счёта: покупатель сделки, у гаражной — держатель машины. */
    private const MANAGER = 'coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id or g.payout_invoice_id = billing_invoices.id limit 1))';

    private const WITH = ['party', 'charges', 'payments', 'offer.brand', 'offer.model', 'deal.offer.brand', 'deal.offer.model', 'deal.buyer', 'garageCar.manager', 'garageCar.offer.brand', 'garageCar.offer.model', 'garagePayoutCar.manager', 'garagePayoutCar.offer.brand', 'garagePayoutCar.offer.model'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($invoice = Invoice::find($key)) ? $this->detail($invoice) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-money', Common::manager(self::MANAGER));
        ListPrefs::sync($request, 'crm-money', keep: $facets->keys());
        // Варианты чипа — менеджеры счетов двух вкладок с менеджером («Нам должны», «Мы должны»).
        $facets->apply(Invoice::crmMoney()->where(fn ($w) => $w->where(fn ($x) => $x->where('direction', 'issued')->whereNot(self::oneOff()))
            ->orWhere(fn ($x) => $x->where('direction', 'owed')->where('kind', ChargeKind::AgentFee))));
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'links';
        $qs = trim((string) $request->query('q'));
        $find = self::find($qs);
        // Лупа — по всем трём вкладкам сразу (мимо пилюли), без страниц у оплаченного.
        $tabs = $qs === '' ? [$tab] : array_keys(self::TABS);
        $sections = collect($tabs)->mapWithKeys(fn ($t) => [$t => $this->section($t, $facets, $find, $qs === '')])->all();
        $paid = collect($sections)->flatMap(fn ($s) => $s['paid'] instanceof Collection ? $s['paid'] : $s['paid']->items())->flatMap(fn ($i) => $i->payments->pluck('id'));

        // Выбран один менеджер — его расчёт строкой сверху (бывший экран «Расчёты с менеджерами»).
        $picked = $qs === '' && $tab !== 'links' && count($ids = $facets->selected('manager')) === 1 ? User::find((int) $ids[0]) : null;

        return view('admin.money.index', [
            'detail' => $detail, 'facets' => $facets, 'q' => $qs, 'tab' => $tab, 'sections' => $sections,
            'counts' => self::counts($facets), 'attempts' => AcquiringPayment::whereIn('payment_id', $paid)->get()->keyBy('payment_id'),
            'manager' => $picked ? ['user' => $picked, 'position' => (new ManagerLedger($picked, staff: true))->position()] : null,
        ]);
    }

    /** Счета вкладки: разовые по ссылке, нам по сделкам и гаражу (с чипом менеджера), выплаты менеджерам (с чипом). */
    private static function of(string $tab, ?Facets $facets = null)
    {
        $q = Invoice::crmMoney();

        return match ($tab) {
            'links' => $q->where('direction', 'issued')->where(self::oneOff()),
            'owed' => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'issued')->whereNot(self::oneOff()),
            default => ($facets ? $facets->applyTo($q) : $q)->where('direction', 'owed')->where('kind', ChargeKind::AgentFee),
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
    private function section(string $tab, Facets $facets, ?Closure $find, bool $paged): array
    {
        $q = fn () => self::of($tab, $facets)->when($find, $find)->with(self::WITH);
        $claims = $tab === 'owed'
            ? Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereIn('billing_invoices.id', self::of('owed', $facets)->when($find, $find)->select('billing_invoices.id')))
                ->with(['invoice' => fn ($i) => $i->with(self::WITH)])->orderBy('paid_at')->orderBy('id')->get()
            : collect();
        $tried = $tab === 'payouts' ? collect() : self::tried(self::of($tab, $facets)->when($find, $find))->pluck('billing_invoices.id');
        $open = $q()->where('state', InvoiceState::Issued)->with('payLinks.attempts')
            ->when($tab === 'owed', fn ($w) => $w->whereDoesntHave('claims'))
            ->when($tried->isNotEmpty(), fn ($w) => $w->orderByRaw('billing_invoices.id in ('.$tried->map(fn ($id) => (int) $id)->implode(',').') desc'))
            ->when($tab === 'links', fn ($w) => $w->orderByDesc('issued_at'), fn ($w) => $w->orderByRaw('due_at asc nulls last'))
            ->orderBy('billing_invoices.id')->get();
        $last = Payment::select('paid_at')->whereColumn('invoice_id', 'billing_invoices.id')->where('state', PaymentState::Confirmed)->whereNull('voided_at')->latest('paid_at')->limit(1);
        $paid = $q()->where('state', InvoiceState::Paid)->orderByDesc($last)->orderByDesc('billing_invoices.id');

        return ['claims' => $claims, 'open' => $open, 'tried' => $tried, 'paid' => $paged ? $paid->paginate(50)->withQueryString() : $paid->limit(50)->get()];
    }

    /** Числа пилюль — сколько открытого; tones — где есть дело: не прошла по ссылке, пишет, что оплатил, выплатить. */
    private static function counts(Facets $facets): array
    {
        $open = fn (string $t) => self::of($t, $facets)->where('state', InvoiceState::Issued);

        return [
            'counts' => ['links' => $open('links')->count(), 'owed' => $open('owed')->count(), 'payouts' => $n = $open('payouts')->count()],
            'tones' => array_filter([
                'links' => self::tried($open('links'))->exists() ? 'pill-urgent' : '',
                'owed' => self::tried($open('owed'))->exists() || $open('owed')->whereHas('claims')->exists() ? 'pill-urgent' : '',
                'payouts' => $n ? 'pill-urgent' : '',
            ]),
        ];
    }

    /** Адрес счёта в «Оплатах» — на своей вкладке, с карточкой (уведомления, Telegram). */
    public static function url(Invoice $i): string
    {
        $tab = $i->isService() ? null : ($i->isOwed() ? 'payouts' : 'owed');

        return '/work/money?'.($tab ? 'tab='.$tab.'&' : '').'peek='.$i->id;
    }

    /** Лупа по счёту: номер счёта или предложения, плательщик, менеджер, марка, модель, текст услуги. */
    private static function find(string $qs): ?Closure
    {
        if ($qs === '') {
            return null;
        }
        $like = "%{$qs}%";

        return fn ($q) => $q->where(fn ($s) => $s
            ->when(ctype_digit($qs), fn ($x) => $x->orWhere('billing_invoices.number', (int) $qs)->orWhereHas('offer', fn ($o) => $o->where('number', (int) $qs)))
            ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', $like))
            ->orWhereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Service->value)->where('title', 'ilike', $like))
            ->orWhereHas('deal.buyer', fn ($u) => $u->where('name', 'ilike', $like))
            ->orWhereHas('garageCar.manager', fn ($u) => $u->where('name', 'ilike', $like))
            ->orWhereHas('garagePayoutCar.manager', fn ($u) => $u->where('name', 'ilike', $like))
            ->orWhereHas('offer.brand', fn ($b) => $b->where('name', 'ilike', $like))
            ->orWhereHas('offer.model', fn ($m) => $m->where('name', 'ilike', $like)));
    }

    /** Откуда деньги в ручной оплате: зачёт вознаграждения ставит только система, руками его не выбрать. */
    private static function sources(): array
    {
        return array_diff_key(PaymentSource::options(), [PaymentSource::Offset->value => true]);
    }

    /** Число у «Оплат»: менеджер пишет, что оплатил; не прошла по ссылке; выплатить. Выписки в счёте нет (07.10.2026). */
    public static function todo(): int
    {
        return Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->crmMoney())->count()
            + self::tried(Invoice::crmMoney())->count()
            + Invoice::crmMoney()->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued)->count();
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
        $invoice->load(['party', 'charges', 'payments', 'claims.media', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media', 'deal.buyer']);

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
        ], ['amount.required' => 'Сколько платят', 'title.required' => 'Назовите услугу', 'party_name.required_if' => 'Кто платит',
            'party_email.email' => 'Проверьте почту']);
        $payer = $data['party_id'] === 'new'
            ? ['name' => $data['party_name'], 'email' => $data['party_email'] ?? null]
            : Party::where('is_self', false)->findOrFail($data['party_id']);
        $invoice = $issue($payer, $request->user(), (float) $data['amount'], $data['title']);

        return redirect('/work/money?peek='.$invoice->id)->with('toast', 'Счёт '.$invoice->label().' выставлен, ссылка готова');
    }

    /** Бывший экран «Расчёты с менеджерами»: теперь чип менеджера в «Оплатах» (06.10.2026), адрес жив для закладок. */
    public function managers()
    {
        return redirect('/work/money');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges', 'payments.media', 'claims.media', 'allPayments', 'deal.offer', 'deal.buyer', 'creator', 'media']);

        return view('admin.money.invoice', ['invoice' => $invoice, 'sources' => self::sources(), 'file' => $invoice->getFirstMedia('file')]);
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $record)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['nullable', 'date'], 'source' => ['required', Rule::enum(PaymentSource::class)], 'ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255'], 'slip' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic']]);
        $payment = $record($invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, PaymentSource::from($data['source']), $data['ref'] ?? null, $data['note'] ?? null);
        if ($request->hasFile('slip')) {
            $payment->addMediaFromRequest('slip')->toMediaCollection('slip');
        }
        $paid = $invoice->fresh()->state === InvoiceState::Paid;

        return back()->with('toast', $invoice->isOwed() ? ($paid ? 'Выплачено' : 'Выплата записана') : ($paid ? 'Оплачен' : 'Оплата записана'));
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

    public function slip(Invoice $invoice, Payment $payment)
    {
        abort_unless($payment->invoice_id === $invoice->id && ($media = $payment->slip()), 404);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    public function update(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->state === InvoiceState::Issued, 404);
        $data = $request->validate(['due_at' => ['required', 'date'], 'external_no' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $invoice->update(['due_at' => Carbon::parse($data['due_at'])->toDateString(), 'external_no' => $data['external_no'] ?: null, 'notes' => $data['notes'] ?: null, 'overdue_at' => Carbon::parse($data['due_at'])->isFuture() ? null : $invoice->overdue_at]);
        Nav::forgetStaffCounts();

        return back()->with('toast', 'Сохранено');
    }

    public function unpay(Request $request, Invoice $invoice, Payment $payment, VoidPayment $void)
    {
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $void($payment, $request->user(), $request->input('reason'));

        return back()->with('toast', 'Отменено');
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $void)
    {
        $void($invoice, $request->user(), $request->input('reason'));

        return back()->with('toast', 'Аннулирован');
    }

    public function file(Invoice $invoice)
    {
        $media = $invoice->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    /** Акт хранения, как у парковки: PDF, если выставлен закрытием месяца, иначе страница на печать. */
    public function act(Invoice $invoice)
    {
        if ($media = $invoice->getFirstMedia('act')) {
            return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
        }
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges']);

        return view('billing.docs.storage-act', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }

    public function print(Invoice $invoice)
    {
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'charges', 'payments', 'deal.offer']);

        return view('billing.docs.invoice', ['invoice' => $invoice, 'self' => $invoice->seller->party(), 'pdf' => false]);
    }
}
