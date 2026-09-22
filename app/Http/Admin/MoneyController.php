<?php

namespace App\Http\Admin;

use App\Billing\Actions\ConfirmPayment;
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
use App\Support\ListPrefs;
use App\Support\ListView;
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
    public const PRESETS = ['claims' => 'Сообщили об оплате', 'payouts' => 'К выплате', 'unpaid' => 'Не оплачены', 'paid' => 'Оплачены', 'all' => 'Все'];

    public const SORTS = ['due' => 'По сроку', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'crm-money');
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'claims';
        $qs = trim((string) $request->query('q'));
        $q = Invoice::whereNotNull('deal_id')->with(['party', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media', 'deal.buyer', 'claims'])
            ->when($request->query('manager'), fn ($w, $id) => $w->whereHas('deal', fn ($d) => $d->where('buyer_id', $id)))
            ->when($qs !== '', fn ($w) => $w->where(fn ($s) => $s
                ->when(ctype_digit($qs), fn ($x) => $x->orWhere('number', (int) $qs)->orWhereHas('deal.offer', fn ($o) => $o->where('number', (int) $qs)))
                ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('deal.buyer', fn ($u) => $u->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('deal.offer.brand', fn ($b) => $b->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('deal.offer.model', fn ($m) => $m->where('name', 'ilike', "%{$qs}%"))));
        match ($preset) {
            'claims' => $q->whereHas('claims'),
            'payouts' => $q->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued),
            'unpaid' => $q->where('direction', 'issued')->where('state', InvoiceState::Issued),
            'paid' => $q->where('state', InvoiceState::Paid),
            default => $q,
        };
        $sort = $request->query('sort') === 'fresh' ? 'fresh' : 'due';
        $sort === 'fresh' ? $q->latest('issued_at')->latest('id') : $q->orderBy('due_at')->orderBy('id');

        return view('admin.money.index', [
            'invoices' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'preset' => $preset, 'sort' => $sort, 'q' => $qs, 'counts' => array_filter(self::counts()),
        ]);
    }

    /** Числа пилюль: заявки, к выплате, не оплачены. */
    public static function counts(): array
    {
        return [
            'claims' => Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereNotNull('deal_id'))->count(),
            'payouts' => Invoice::whereNotNull('deal_id')->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', InvoiceState::Issued)->count(),
            'unpaid' => Invoice::whereNotNull('deal_id')->where('direction', 'issued')->where('state', InvoiceState::Issued)->count(),
        ];
    }

    public function peek(Invoice $invoice)
    {
        $invoice->load(['party', 'charges', 'payments', 'claims.media', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media', 'deal.buyer']);

        return view('admin.money.peek', ['invoice' => $invoice, 'sources' => PaymentSource::options()]);
    }

    /** Взаиморасчёты по менеджерам: нам, мы должны, просрочено, заявки — строка ведёт в карточку на «Деньги». */
    public function managers(Request $request)
    {
        $managers = User::where('role', Role::Manager)->orderBy('name')->get()
            ->map(fn (User $u) => ['user' => $u, 'position' => (new ManagerLedger($u))->position()])
            ->filter(fn ($m) => $m['position']['pay'] > 0 || $m['position']['payout'] > 0 || $m['position']['paid_out'] > 0 || $m['position']['claimed'] > 0)
            ->sortByDesc(fn ($m) => [$m['position']['overdue'] > 0, $m['position']['claimed'] > 0, $m['position']['pay'] + $m['position']['payout']])->values();

        return view('admin.money.managers', ['managers' => $managers]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges', 'payments.media', 'claims.media', 'allPayments', 'deal.offer', 'deal.buyer', 'creator', 'media']);

        return view('admin.money.invoice', ['invoice' => $invoice, 'sources' => PaymentSource::options(), 'file' => $invoice->getFirstMedia('file')]);
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

    public function print(Invoice $invoice)
    {
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'charges', 'payments', 'deal.offer']);

        return view('billing.docs.invoice', ['invoice' => $invoice, 'self' => Party::self(), 'pdf' => false]);
    }
}
