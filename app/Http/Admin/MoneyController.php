<?php

namespace App\Http\Admin;

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
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Nav;
use App\Users\Role;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Деньги по сделкам в CRM: заявленные менеджерами оплаты, вознаграждения к
 * выплате, счета покупателям; карточка счёта — общая со стоянкой.
 */
class MoneyController
{
    // «Выплатить» — наш ход (выплата менеджеру), «Ждём оплату» — их (06.10.2026: «к выплате» не говорило, кто кому).
    public const PRESETS = ['claims' => 'Сообщили об оплате', 'tried' => 'Не прошла по ссылке', 'payouts' => 'Выплатить', 'unpaid' => 'Ждём оплату', 'paid' => 'Оплачены', 'all' => 'Все'];

    public const SORTS = ['due' => 'По сроку', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($invoice = Invoice::find($key)) ? $this->detail($invoice) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-money',
            // Менеджер — покупатель сделки, у гаражной — держатель машины; вендор — по ТС счёта.
            Common::manager('coalesce((select buyer_id from deals where deals.id = billing_invoices.deal_id), (select g.manager_id from garage_cars g where g.invoice_id = billing_invoices.id or g.payout_invoice_id = billing_invoices.id limit 1))'),
            Common::vendor('(select o.vendor_id from offers o where o.id = billing_invoices.offer_id)'),
            Facet::column('kind', 'Вид счёта', ['вида', 'вида', 'видов'], 'billing_invoices.kind')->enum(ChargeKind::class),
        );
        ListPrefs::sync($request, 'crm-money', keep: $facets->keys());
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'claims';
        $qs = trim((string) $request->query('q'));
        $q = Invoice::crmMoney()->with(['party', 'offer.brand', 'offer.model', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media', 'deal.buyer', 'garageCar.manager', 'garagePayoutCar.manager', 'claims', 'payLinks.attempts', 'charges'])
            ->when($qs !== '', fn ($w) => $w->where(fn ($s) => $s
                ->when(ctype_digit($qs), fn ($x) => $x->orWhere('number', (int) $qs)->orWhereHas('offer', fn ($o) => $o->where('number', (int) $qs)))
                ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Service->value)->where('title', 'ilike', "%{$qs}%"))
                ->orWhereHas('deal.buyer', fn ($u) => $u->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('offer.brand', fn ($b) => $b->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('offer.model', fn ($m) => $m->where('name', 'ilike', "%{$qs}%"))));
        // Лупа — по всем счетам, мимо пилюли и чипов.
        if ($qs === '') {
            match ($preset) {
                'claims' => $q->whereHas('claims'),
                'tried' => self::tried($q),
                'payouts' => $q->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued),
                'unpaid' => $q->where('direction', 'issued')->where('state', InvoiceState::Issued),
                'paid' => $q->where('state', InvoiceState::Paid),
                default => $q,
            };
        }
        $facets->apply($q);
        $sort = $request->query('sort') === 'fresh' ? 'fresh' : 'due';
        $sort === 'fresh' ? $q->latest('issued_at')->latest('id') : $q->orderByRaw('due_at asc nulls last')->orderBy('id');

        return view('admin.money.index', [
            'detail' => $detail,
            'invoices' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset, 'sort' => $sort, 'q' => $qs, 'counts' => array_filter(self::counts($facets)), 'facets' => $facets,
        ]);
    }

    /** Откуда деньги в ручной оплате: зачёт вознаграждения ставит только система, руками его не выбрать. */
    private static function sources(): array
    {
        return array_diff_key(PaymentSource::options(), [PaymentSource::Offset->value => true]);
    }

    /** Числа пилюль: заявки, к выплате, не оплачены. */
    public static function counts(?Facets $facets = null): array
    {
        $f = fn ($q) => $facets ? $facets->applyTo($q) : $q;

        return [
            // Заявки — оплаты, а не счета: то же число, что на табе «Работа» (`Nav::totals`), но с выбранными чипами.
            'claims' => Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $f($i->crmMoney()))->count(),
            'payouts' => $f(Invoice::crmMoney()->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued))->count(),
            'tried' => $f(self::tried(Invoice::crmMoney()))->count(),
            'unpaid' => $f(Invoice::crmMoney()->where('direction', 'issued')->where('state', InvoiceState::Issued))->count(),
        ];
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

        return redirect('/work/money?preset=all&peek='.$invoice->id)->with('toast', 'Счёт '.$invoice->label().' выставлен, ссылка готова');
    }

    /** Расчёты с менеджерами: должен нам, должны ему, просрочил, сообщил об оплате — строка ведёт в карточку на «Деньги». */
    public function managers(Request $request)
    {
        $managers = User::withRole(Role::Manager)->orderBy('name')->get()
            ->map(fn (User $u) => ['user' => $u, 'position' => (new ManagerLedger($u, staff: true))->position()])
            ->filter(fn ($m) => $m['position']['pay'] > 0 || $m['position']['payout'] > 0 || $m['position']['paid_out'] > 0 || $m['position']['claimed'] > 0)
            ->sortByDesc(fn ($m) => [$m['position']['overdue'] > 0, $m['position']['claimed'] > 0, $m['position']['pay'] + $m['position']['payout']])->values();

        return view('admin.money.managers', ['managers' => $managers]);
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
