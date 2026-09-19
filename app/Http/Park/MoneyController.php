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
use App\Support\Nav;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $qs = trim((string) $request->query('q'));
        $q = self::scoped($request->user(), Invoice::with(['party', 'vehicle.brand', 'vehicle.model']))
            ->when($request->query('party'), fn ($w, $id) => $w->where('party_id', $id))
            ->when($request->query('car'), fn ($w, $id) => $w->where('vehicle_id', $id))
            ->when($qs !== '', fn ($w) => $w->where(fn ($s) => $s->where('external_no', 'ilike', "%{$qs}%")
                ->when(ctype_digit($qs), fn ($x) => $x->orWhere('number', (int) $qs))
                ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$qs}%"))
                ->orWhereHas('vehicle', fn ($v) => $v->where('ref', 'ilike', "%{$qs}%")->orWhere('vin', 'ilike', "%{$qs}%")->orWhere('plate', 'ilike', '%'.mb_strtoupper(str_replace(' ', '', $qs)).'%'))));
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
            'q' => $qs,
            'parties' => Party::where('is_self', false)->whereHas('invoices')->orderBy('name')->pluck('name', 'id'),
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
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges', 'payments.media', 'deal.offer', 'creator', 'media']);

        return view('park.money.show', ['invoice' => $invoice, 'sources' => PaymentSource::options(), 'file' => $invoice->getFirstMedia('file')]);
    }

    public function pay(Request $request, Invoice $invoice, RecordPayment $record)
    {
        self::guard($invoice);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['nullable', 'date'], 'source' => ['required', Rule::enum(PaymentSource::class)], 'ref' => ['nullable', 'string', 'max:60'], 'note' => ['nullable', 'string', 'max:255'], 'slip' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic']]);
        $payment = $record($invoice, $request->user(), (float) $data['amount'], isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null, PaymentSource::from($data['source']), $data['ref'] ?? null, $data['note'] ?? null);
        if ($request->hasFile('slip')) {
            $payment->addMediaFromRequest('slip')->toMediaCollection('slip');
        }

        $paid = $invoice->fresh()->state === InvoiceState::Paid;

        return back()->with('toast', $invoice->isOwed() ? ($paid ? 'Перечислено' : 'Перечисление записано') : ($paid ? 'Оплачен' : 'Оплата записана'));
    }

    /** Скан платёжки — с закрытого диска, только своим. */
    public function slip(Invoice $invoice, Payment $payment)
    {
        self::guard($invoice);
        abort_unless($payment->invoice_id === $invoice->id && ($media = $payment->slip()), 404);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    /** Срок, чужой номер и заметка правятся у выставленного счёта; суммы и строки — нет. */
    public function update(Request $request, Invoice $invoice)
    {
        self::guard($invoice);
        abort_unless($invoice->state === InvoiceState::Issued, 404);
        $data = $request->validate(['due_at' => ['required', 'date'], 'external_no' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $invoice->update(['due_at' => Carbon::parse($data['due_at'])->toDateString(), 'external_no' => $data['external_no'] ?: null, 'notes' => $data['notes'] ?: null, 'overdue_at' => Carbon::parse($data['due_at'])->isFuture() ? null : $invoice->overdue_at]);
        Nav::forgetStaffCounts();

        return back()->with('toast', 'Сохранено');
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

    public const DEBT_SORTS = ['overdue' => 'Просрочено', 'owed_to_us' => 'Нам должны', 'we_owe' => 'Мы должны', 'unbilled' => 'Не выставлено'];

    public function debts(Request $request)
    {
        $sort = array_key_exists($request->query('sort', ''), self::DEBT_SORTS) ? $request->query('sort') : 'overdue';
        $qs = mb_strtolower(trim((string) $request->query('q')));
        $debts = Ledger::debts()
            ->when($qs !== '', fn ($c) => $c->filter(fn ($d) => str_contains(mb_strtolower($d['party']->name), $qs)))
            ->sortByDesc(fn ($d) => [$d[$sort], $d['overdue'], $d['owed_to_us']])->values();
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;

        return view('park.money.debts', [
            'debts' => new LengthAwarePaginator($debts->forPage($page, $per), $debts->count(), $per, $page, ['path' => '/money/debts', 'query' => $request->query()]),
            'sort' => $sort, 'q' => $request->query('q', ''),
            'totals' => ['owed_to_us' => round($debts->sum('owed_to_us'), 2), 'we_owe' => round($debts->sum('we_owe'), 2), 'overdue' => round($debts->sum('overdue'), 2), 'unbilled' => round($debts->sum('unbilled'), 2)],
        ]);
    }

    public function summary(Request $request)
    {
        $month = $request->query('month') && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? Carbon::createFromFormat('Y-m', $request->query('month')) : now();

        return view('park.money.summary', ['month' => $month, 'stats' => Ledger::month($month), 'debts' => Ledger::debts()]);
    }
}
