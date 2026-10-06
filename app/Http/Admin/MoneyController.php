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
use App\Billing\Bank\Transaction;
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
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * «Оплаты» в CRM — как история операций в банке (06.10.2026, владелец: «даже я ничего не понимаю», начальник не
 * находил оплаты по ссылкам среди счетов). Одна страница без пилюль, три блока:
 * - «Надо сделать» — ждёт нашей руки: менеджер сообщил об оплате, не прошла по ссылке, выплатить менеджеру, в банк
 *   пришло без счёта;
 * - «Ждём деньги» — кто нам должен и что с его ссылкой;
 * - «История» — что пришло и ушло, по дням, со знаком.
 * Чип менеджера сужает всё (его позиция — строкой сверху), лупа ищет во всех блоках. Карточка строки — счёт или
 * поступление выписки (`t{id}`) рядом; карточка счёта — общая со стоянкой.
 */
class MoneyController
{
    /** Менеджер счёта: покупатель сделки, у гаражной — держатель машины. */
    private const MANAGER = 'coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id or g.payout_invoice_id = billing_invoices.id limit 1))';

    private const WITH = ['party', 'charges', 'offer.brand', 'offer.model', 'deal.offer.brand', 'deal.offer.model', 'deal.buyer', 'garageCar.manager', 'garageCar.offer.brand', 'garageCar.offer.model', 'garagePayoutCar.manager', 'garagePayoutCar.offer.brand', 'garagePayoutCar.offer.model'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => str_starts_with($key, 't')
            ? (($tx = Transaction::find((int) substr($key, 1))) ? app(BankController::class)->detail($tx) : null)
            : (($invoice = Invoice::find($key)) ? $this->detail($invoice) : null), '/^t?\d+$/');
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-money', Common::manager(self::MANAGER));
        ListPrefs::sync($request, 'crm-money', keep: $facets->keys());
        $facets->apply(Invoice::crmMoney());
        $qs = trim((string) $request->query('q'));
        $find = self::find($qs);
        // Счета раздела с выбранным менеджером и лупой — основа всех трёх блоков.
        $scope = fn ($i) => $facets->applyTo($i->crmMoney())->when($find, $find);
        $invoices = fn () => $scope(Invoice::query())->with(self::WITH);
        $withInvoice = ['invoice' => fn ($i) => $i->with(self::WITH)];

        $claims = Payment::where('state', PaymentState::Claimed)->whereHas('invoice', $scope)->with($withInvoice)->orderBy('paid_at')->orderBy('id')->get();
        $tried = self::tried($invoices())->with('payLinks.attempts')->orderBy('id')->get();
        $payouts = $invoices()->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued)->orderByRaw('due_at asc nulls last')->orderBy('id')->get();
        // Выписка к менеджеру не относится: выбран менеджер — её нет; лупа ищет и в ней.
        $bank = $qs === '' && $facets->on('manager') ? collect() : Transaction::where('direction', 'in')->where('state', Transaction::UNMATCHED)
            ->when($qs !== '', fn ($w) => $w->where(fn ($s) => $s->where('counterparty', 'ilike', "%{$qs}%")->orWhere('purpose', 'ilike', "%{$qs}%")->orWhere('counterparty_inn', $qs)))
            ->latest('booked_at')->latest('id')->get();

        $waiting = $invoices()->where('direction', 'issued')->where('state', InvoiceState::Issued)
            ->whereDoesntHave('claims')->whereNotIn('id', $tried->pluck('id'))->with('payLinks.attempts')
            ->orderByRaw('due_at asc nulls last')->orderBy('id')->get();

        $history = Payment::where('state', PaymentState::Confirmed)->whereNull('voided_at')->whereHas('invoice', $scope)->with($withInvoice)
            ->orderByDesc('paid_at')->orderByDesc('id')->paginate(50)->withQueryString();
        $attempts = AcquiringPayment::whereIn('payment_id', $history->pluck('id'))->get()->keyBy('payment_id');

        // Выбран один менеджер — его позиция строкой сверху (бывший экран «Расчёты с менеджерами»).
        $picked = $qs === '' && count($ids = $facets->selected('manager')) === 1 ? User::find((int) $ids[0]) : null;

        return view('admin.money.index', [
            'detail' => $detail, 'facets' => $facets, 'q' => $qs,
            'claims' => $claims, 'tried' => $tried, 'payouts' => $payouts, 'bank' => $bank,
            'waiting' => $waiting, 'history' => $history, 'attempts' => $attempts,
            'manager' => $picked ? ['user' => $picked, 'position' => (new ManagerLedger($picked, staff: true))->position()] : null,
        ]);
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

    /** Число у «Оплат»: строки «Надо сделать» — заявки, не прошла по ссылке, выплатить, в банк пришло без счёта. */
    public static function todo(): int
    {
        return Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->crmMoney())->count()
            + self::tried(Invoice::crmMoney())->count()
            + Invoice::crmMoney()->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued)->count()
            + Transaction::where('direction', 'in')->where('state', Transaction::UNMATCHED)->count();
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
