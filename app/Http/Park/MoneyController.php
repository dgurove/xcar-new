<?php

namespace App\Http\Park;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Export\RegistryExport;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\Money;
use App\Support\Nav;
use App\Support\Plural;
use App\Support\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Деньги стоянки: счета с пресетами, карточка строки, карточка счёта, долги по контрагентам, месяц. */
class MoneyController
{
    /** «Отправить» — выставленные страховой стороне, письма с которыми ещё не уходили (`Invoice::toSend`): очередь управляющего. */
    public const PRESETS = ['unpaid' => 'Не оплачены', 'send' => 'Отправить', 'overdue' => 'Просрочены', 'owed' => 'Мы должны', 'paid' => 'Оплачены', 'all' => 'Все'];

    public function index(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => ($invoice = Invoice::find($key)) ? $this->detail($invoice) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'park-money', Common::party('billing_invoices.party_id'));
        ListPrefs::sync($request, 'park-money', keep: $facets->keys());
        $preset = array_key_exists($request->query('preset', ''), self::PRESETS) ? $request->query('preset') : 'unpaid';
        $qs = trim((string) $request->query('q'));
        $q = self::scoped(Invoice::with(['party', 'vehicle.brand', 'vehicle.model']))
            ->when($request->query('car'), fn ($w, $id) => $w->forVehicle((int) $id))
            ->when($qs !== '', fn ($w) => $w->search($qs));
        // Лупа — по всем счетам, мимо пилюли и чипа.
        if ($qs === '') {
            match ($preset) {
                'unpaid' => $q->where('direction', 'issued')->where('state', InvoiceState::Issued),
                'send' => $q->toSend(),
                'overdue' => $q->where('state', InvoiceState::Issued)->whereDate('due_at', '<', now()->toDateString()),
                'owed' => $q->where('direction', 'owed')->where('state', InvoiceState::Issued),
                'paid' => $q->where('state', InvoiceState::Paid),
                default => $q,
            };
        }
        $facets->apply($q);
        $sort = Sort::from($request->query('sort'), Invoice::SORTS, '-fresh');
        $q->sorted($sort);
        $open = $facets->applyTo(self::scoped(Invoice::query()))->where('state', InvoiceState::Issued)->get();
        $parties = Common::ints($facets->selected('party'));

        $all = $facets->applyTo(self::scoped(Invoice::query()));
        $counts = [
            'unpaid' => $open->where('direction', 'issued')->count(),
            'send' => $facets->applyTo(self::scoped(Invoice::query()))->toSend()->count(),
            'overdue' => $open->filter->isOverdue()->count(),
            'owed' => $open->where('direction', 'owed')->count(),
            'paid' => (clone $all)->where('state', InvoiceState::Paid)->count(),
            'all' => (clone $all)->count(),
        ];

        return view('park.money.index', [
            'detail' => $detail,
            'invoices' => $q->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            // Пилюля есть, пока в ней есть счета (как в «Заявках», 10.10.2026): у парковки, где счетов ещё не было, ряд
            // из шести пустых пилюль ничего не говорил. Выбранная остаётся и с нулём; счетов нет вовсе — пилюль нет.
            'preset' => $preset, 'presets' => $counts['all'] ? array_filter(self::PRESETS, fn ($label, $key) => $key === $preset || $counts[$key] > 0, ARRAY_FILTER_USE_BOTH) : [],
            'sort' => $sort,
            'counts' => array_diff_key($counts, ['all' => 0, 'paid' => 0]),
            // Один контрагент выбран (пришли из «Долгов») — экран называется им.
            'party' => count($parties) === 1 ? Party::find($parties[0]) : null,
            'closing' => Closing::pending(),
            'q' => $qs,
            'facets' => $facets,
        ]);
    }

    /** Счета парковки — только ИП (ПРАЙМ со сделками — в CRM). */
    private static function scoped(Builder $q): Builder
    {
        return $q->ofPark();
    }

    private static function guard(Invoice $invoice): void
    {
        abort_unless($invoice->park, 404);
    }

    public function detail(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'charges', 'payments']);

        return view('park.money.detail', ['invoice' => $invoice, 'sources' => array_diff_key(PaymentSource::options(), array_flip(PaymentSource::soft()))]);
    }

    public function show(Invoice $invoice)
    {
        self::guard($invoice);
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges', 'payments.media', 'claims.media', 'allPayments', 'deal.offer', 'deal.buyer', 'creator', 'media']);

        return view('park.money.show', ['invoice' => $invoice, 'sources' => array_diff_key(PaymentSource::options(), array_flip(PaymentSource::soft())), 'file' => $invoice->getFirstMedia('file')]);
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

        return view('billing.docs.invoice', ['invoice' => $invoice, 'self' => $invoice->seller->party(), 'pdf' => false]);
    }

    /** Акт хранения: PDF, если выставлен закрытием месяца, иначе страница на печать. */
    public function act(Invoice $invoice)
    {
        self::guard($invoice);
        if ($media = $invoice->getFirstMedia('act')) {
            return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
        }
        $invoice->load(['party', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'charges.vehicle.brand', 'charges.vehicle.model', 'charges.vehicle.yard']);

        return view($invoice->isRegistry() ? 'billing.docs.storage-registry' : 'billing.docs.storage-act', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }

    /** Акты хранения по каждой ТС реестра одним документом — лист на машину. */
    public function acts(Invoice $invoice)
    {
        self::guard($invoice);
        abort_unless($invoice->isRegistry(), 404);
        $invoice->load(['party', 'charges.vehicle.brand', 'charges.vehicle.model', 'charges.vehicle.yard', 'charges.vehicle.vendor']);

        return view('billing.docs.storage-acts', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }

    /** Отчёт-акт реестра файлом Excel — той же формой, что PDF: вендор правит его и присылает назад. */
    public function registryXlsx(Invoice $invoice)
    {
        self::guard($invoice);
        abort_unless($invoice->isRegistry(), 404);

        return response()->streamDownload(fn () => RegistryExport::write($invoice, 'php://output'), 'Отчёт-акт '.$invoice->label().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public const DEBT_SORTS = ['overdue' => ['Просрочено', 'desc'], 'owed_to_us' => ['Нам должны', 'desc'], 'we_owe' => ['Мы должны', 'desc'], 'unbilled' => ['Не выставлено', 'desc']];

    public function debts(Request $request)
    {
        $sort = Sort::from($request->query('sort'), self::DEBT_SORTS, '-overdue');
        $qs = mb_strtolower(trim((string) $request->query('q')));
        $debts = Ledger::debts()
            ->when($qs !== '', fn ($c) => $c->filter(fn ($d) => str_contains(mb_strtolower($d['party']->name), $qs)))
            ->sortBy(fn ($d) => [$d[$sort->key], $d['overdue'], $d['owed_to_us']], SORT_REGULAR, $sort->desc)->values();
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;

        return view('park.money.debts', [
            'debts' => new LengthAwarePaginator($debts->forPage($page, $per), $debts->count(), $per, $page, ['path' => '/money/debts', 'query' => $request->query()]),
            'sort' => $sort, 'q' => $request->query('q', ''),
        ]);
    }

    /** Закрытие месяца: строки «кому и за что выставить хранение» с галочками; «Выставить выбранные» — счета с актами. */
    public function closing(Request $request)
    {
        $month = self::month($request);
        $items = Closing::items($month);
        // Уже выставленное за этот месяц — счета, чьё хранение кончается в нём.
        $issued = Invoice::where('direction', 'issued')->whereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Storage)->whereBetween('period_to', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()]))
            ->where('state', '!=', InvoiceState::Void)->ofPark()->with(['party', 'vehicle.brand', 'vehicle.model'])->latest('id')->get();

        return view('park.money.closing', [
            'month' => $month, 'items' => $items->groupBy(fn ($i) => Closing::groupOf($i)),
            'issued' => $issued,
            'months' => collect(range(0, 5))->map(fn ($n) => now()->subMonths($n)->startOfMonth()),
        ]);
    }

    public function close(Request $request, IssueInvoice $issue, StorageActPdf $act)
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'items' => ['required', 'array'], 'items.*' => ['string', 'max:20']]);
        $result = Closing::issue(Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth(), $data['items'], $request->user(), $issue, $act);
        $sum = Money::rub($result['issued']->sum('total'));
        $toast = $result['issued']->isNotEmpty() ? 'Выставлено '.$result['issued']->count().' '.Plural::of($result['issued']->count(), ['счёт', 'счёта', 'счетов']).' на '.$sum : 'Ничего не выставлено';

        return redirect('/money/closing?month='.$data['month'])->with('toast', $toast)->withErrors($result['errors'] ? ['closing' => implode('; ', $result['errors'])] : []);
    }

    private static function month(Request $request): Carbon
    {
        return $request->query('month') && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? Carbon::createFromFormat('!Y-m', $request->query('month'))->startOfMonth() : now()->subMonth()->startOfMonth();
    }
}
