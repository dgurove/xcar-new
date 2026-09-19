<?php

namespace App\Http\Park;

use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Park\Scope;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Деньги стоянки: счета с пресетами, окошко строки, карточка счёта, долги по контрагентам, месяц. */
class MoneyController
{
    public const PRESETS = ['unpaid' => 'Не оплачены', 'overdue' => 'Просрочены', 'owed' => 'Мы должны', 'paid' => 'Оплачены', 'all' => 'Все'];

    public const SORTS = ['due' => 'По сроку', 'fresh' => 'Сначала новые'];

    public function index(Request $request)
    {
        ListPrefs::sync($request, 'park-money');
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'unpaid';
        $q = self::scoped($request->user(), Invoice::with(['party', 'vehicle.brand', 'vehicle.model']))
            ->when($request->query('party'), fn ($w, $id) => $w->where('party_id', $id))
            ->when($request->query('car'), fn ($w, $id) => $w->where('vehicle_id', $id));
        match ($preset) {
            'unpaid' => $q->where('direction', 'issued')->where('state', InvoiceState::Issued),
            'overdue' => $q->where('state', InvoiceState::Issued)->whereDate('due_at', '<', now()->toDateString()),
            'owed' => $q->where('direction', 'owed')->where('state', InvoiceState::Issued),
            'paid' => $q->where('state', InvoiceState::Paid),
            default => $q,
        };
        $request->query('sort') === 'fresh' ? $q->latest('issued_at')->latest('id') : $q->orderBy('due_at')->orderBy('id');
        $open = self::scoped($request->user(), Invoice::query())->where('state', InvoiceState::Issued)->get();

        return view('park.money.index', [
            'invoices' => ListView::paginate($request, $q),
            'preset' => $preset, 'presets' => self::PRESETS,
            'sort' => $request->query('sort', 'due'),
            'counts' => [
                'unpaid' => $open->where('direction', 'issued')->count(),
                'overdue' => $open->filter->isOverdue()->count(),
                'owed' => $open->where('direction', 'owed')->count(),
            ],
            'party' => $request->query('party') ? Party::find($request->query('party')) : null,
        ]);
    }

    /** Своя площадка — только её счета (и счета без ТС: по сделкам). */
    private static function scoped(User $user, Builder $q): Builder
    {
        if ($yard = Scope::yardId($user)) {
            $q->where(fn ($w) => $w->whereNull('vehicle_id')->orWhereHas('vehicle', fn ($v) => $v->where('yard_id', $yard)->orWhereNull('yard_id')));
        }

        return $q;
    }

    private static function guard(Invoice $invoice): void
    {
        abort_unless(! $invoice->vehicle || Scope::allows(request()->user(), $invoice->vehicle), 403);
    }

    public function peek(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'charges', 'payments']);

        return view('park.money.peek', ['invoice' => $invoice, 'sources' => PaymentSource::options()]);
    }

    public function show(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges', 'payments', 'deal.offer', 'creator', 'media']);

        return view('park.money.show', ['invoice' => $invoice, 'sources' => PaymentSource::options(), 'file' => $invoice->getFirstMedia('file')]);
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $record)
    {
        self::guard($invoice);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['nullable', 'date'], 'source' => ['required', Rule::enum(PaymentSource::class)], 'ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255']]);
        $record($invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, PaymentSource::from($data['source']), $data['ref'] ?? null, $data['note'] ?? null);

        $paid = $invoice->fresh()->state === InvoiceState::Paid;

        return back()->with('toast', $invoice->isOwed() ? ($paid ? 'Перечислено' : 'Перечисление записано') : ($paid ? 'Оплачен' : 'Оплата записана'));
    }

    public function unpay(Request $request, Invoice $invoice, Payment $payment, VoidPayment $void)
    {
        self::guard($invoice);
        abort_unless($payment->invoice_id === $invoice->id, 404);
        $void($payment, $request->user(), $request->input('reason'));

        return back()->with('toast', 'Оплата отменена');
    }

    public function void(Request $request, Invoice $invoice, VoidInvoice $void)
    {
        self::guard($invoice);
        $void($invoice, $request->user(), $request->input('reason'));

        return back()->with('toast', 'Счёт аннулирован');
    }

    public function file(Invoice $invoice)
    {
        self::guard($invoice);
        $media = $invoice->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    public function print(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'charges', 'deal.offer']);

        return view('billing.docs.invoice', ['invoice' => $invoice, 'self' => Party::self(), 'pdf' => false]);
    }

    public function act(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges']);

        return view('billing.docs.storage-act', ['invoice' => $invoice, 'self' => Party::self()]);
    }

    public function debts()
    {
        return view('park.money.debts', ['debts' => Ledger::debts()]);
    }

    public function summary(Request $request)
    {
        $month = $request->query('month') && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? Carbon::createFromFormat('Y-m', $request->query('month')) : now();

        return view('park.money.summary', ['month' => $month, 'stats' => Ledger::month($month), 'debts' => Ledger::debts()]);
    }
}
