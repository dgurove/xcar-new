<?php

namespace App\Http\Park;

use App\Billing\Actions\FixClosingDocument;
use App\Billing\Acts\RegistryAct;
use App\Billing\Closing;
use App\Billing\ClosingDoc;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Ledger;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Http\Billing\InvoiceActions;
use App\Park\Vehicle;
use App\Search\Term;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Support\OfficePreview;
use App\Support\Sort;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Деньги стоянки: счета с пресетами, карточка строки, карточка счёта, долги по контрагентам, месяц. */
class MoneyController
{
    use InvoiceActions { show as invoiceShow; }

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
        $q = self::scoped(Invoice::with(['party', 'vehicle']))
            ->when($request->query('car'), fn ($w, $id) => $w->forVehicle((int) $id))
            ->search($qs);
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
            'closing' => ClosingDoc::summary(),
            // Счетов парковки нет вовсе — ни тулбара, ни «Счетов нет»: только «Счета и акты», долги, реквизиты.
            'any' => $qs !== '' || $counts['all'] > 0 || $parties,
            'q' => $qs,
            'facets' => $facets,
        ]);
    }

    /** Счета парковки — только ИП (ПРАЙМ со сделками — в CRM). */
    private static function scoped(Builder $q): Builder
    {
        return $q->ofPark();
    }

    /** Счета ИП, а не ПРАЙМ: чужие — 404. */
    protected function guardInvoice(Invoice $invoice): void
    {
        abort_unless($invoice->park, 404);
    }

    protected function invoiceView(): string
    {
        return 'park.money.show';
    }

    /** Долг парковки вендору — «перечисление». */
    protected function invoiceToasts(): array
    {
        return ['owedPaid' => 'Перечислено', 'owedRecorded' => 'Перечисление записано', 'unpaid' => 'Оплата отменена', 'voided' => 'Счёт аннулирован'];
    }

    public function detail(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        $invoice->load(['party', 'vehicle', 'charges', 'payments']);

        return view('park.money.detail', ['invoice' => $invoice, 'sources' => PaymentSource::manual()]);
    }

    /** Акты хранения по каждой ТС реестра одним документом — лист на машину. */
    public function acts(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        abort_unless($invoice->isRegistry(), 404);
        $invoice->load(['party', 'charges.vehicle', 'charges.vehicle.yard', 'charges.vehicle.vendor']);

        return view('billing.docs.storage-acts', ['invoice' => $invoice, 'self' => $invoice->seller->party()]);
    }

    public const DEBT_SORTS = ['overdue' => ['Просрочено', 'desc'], 'owed_to_us' => ['Нам должны', 'desc'], 'we_owe' => ['Мы должны', 'desc'], 'unbilled' => ['Не отправлено', 'desc']];

    public function debts(Request $request)
    {
        $sort = Sort::from($request->query('sort'), self::DEBT_SORTS, '-overdue');
        // Долги считаются в памяти — лупа тем же разбором запроса (`Term::matches`): имя и ИНН контрагента.
        $term = Term::of((string) $request->query('q'));
        $debts = Ledger::debts()
            ->when(! $term->blank(), fn ($c) => $c->filter(fn ($d) => $term->matches($d['party']->name, $d['party']->inn)))
            ->sortBy(fn ($d) => [$d[$sort->key], $d['overdue'], $d['owed_to_us']], SORT_REGULAR, $sort->desc)->values();
        $page = max(1, (int) $request->query('page', 1));
        $per = 30;

        return view('park.money.debts', [
            'debts' => new LengthAwarePaginator($debts->forPage($page, $per), $debts->count(), $per, $page, ['path' => '/money/debts', 'query' => $request->query()]),
            'sort' => $sort, 'q' => $request->query('q', ''),
        ]);
    }

    /**
     * Счета и акты (05.11.2026, владелец: «не разделять по месяцам, а группировать; логотипов не хватает»; 06.11.2026 —
     * «табличный вид не подходит, блоки со строками, подписи месяцев вне блоков»): блок `.list` на месяц (`ClosingDoc::window`); у строки — логотип, что за документ и состояние словом; нажатие — сразу
     * страница документа (`closingPage`; окошка рядом нет — владелец: «здесь оно вообще неуместно»). Старые `?month=` —
     * якорем своего месяца, `?peek=` — на страницу документа.
     */
    public function closing(Request $request)
    {
        if (is_string($key = $request->query('peek')) && preg_match(ClosingDoc::KEY, $key)) {
            return redirect('/money/closing/'.$key, 301);
        }
        if (is_string($m = $request->query('month')) && preg_match('/^\d{4}-\d{2}$/', $m)) {
            return redirect('/money/closing#m'.$m, 301);
        }
        $months = min(24, max(6, (int) $request->query('months', 6)));

        return view('park.money.closing', ['docs' => ClosingDoc::window($months), 'months' => $months, 'table' => self::closingTable()]);
    }

    /**
     * Счёт: документ «Счетов и актов» (реестр месяца, счёт покупателю за хранение) — на своей странице со всеми ТС
     * (`closingPage`), двух экранов у одного документа нет; остальные — прежняя страница счёта.
     */
    public function show(Invoice $invoice)
    {
        $this->guardInvoice($invoice);
        if ($invoice->direction === 'issued' && ($invoice->isRegistry() || ($invoice->vehicle_id && $invoice->kind === ChargeKind::Storage))) {
            return redirect('/money/closing/i'.$invoice->id);
        }

        return $this->invoiceShow($invoice);
    }

    /**
     * Документ на всю страницу (05.11.2026, владелец: «все тачки — ставка, даты приёма и выдачи, посмотреть акт»; 06.11.2026 —
     * «бери таблицу „Выданы“ и используй ровно такую же»): в строке заголовка сумма и действия, ниже — ТС документа тем же
     * списком, что «Наличие» (`VehicleController::listing`: чипы, сортировка, лупа, виды, окошко ТС), и «Сумма» —
     * деньги ТС в этом документе; акты — в окошке ТС.
     */
    public function closingPage(Request $request, string $key, VehicleController $vehicles)
    {
        abort_unless(preg_match(ClosingDoc::KEY, $key) && ($doc = ClosingDoc::find($key)), 404);
        $data = $this->closingData($doc);
        $cars = $data['composition']['cars'];
        $ids = $cars->pluck('vehicle.id')->unique()->values()->all();
        $detail = Detail::of($request, fn (string $id) => in_array((int) $id, $ids, true) && ($v = Vehicle::find($id)) ? $vehicles->detail($request, $v) : null);
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = $vehicles->facets($request, 'park-closing');
        ListPrefs::sync($request, 'park-closing', keep: $facets->keys(), sort: false);
        if (! $request->query->has(ListView::PARAM)) {
            $request->query->set(ListView::PARAM, ListView::TABLE);
        }
        $list = $vehicles->listing($request, $facets, fn ($q) => $q->whereIn('park_vehicles.id', $ids), [
            'amounts' => $cars->groupBy('vehicle.id')->map->sum('amount')->all(),
            'rates' => $cars->groupBy('vehicle.id')->map(fn ($c) => (float) ($c->pluck('rates')->flatten()->last() ?? 0))->all(),
        ]);
        if ($request->header('X-List')) {
            return response()->view('park.vehicles.list', $list);
        }

        return view('park.money.closing-page', $data + $list + ['facets' => $facets, 'detail' => $detail]);
    }

    /** @return array<string, mixed> данные страницы документа: живой — счёт на лету с номером, который получит; отправленный — счёт с оплатами и историей */
    private function closingData(ClosingDoc $doc): array
    {
        $draft = $doc->live() && $doc->state !== 'nobody' ? Closing::draft($doc->month, $doc->group) : null;
        $invoice = $doc->invoice?->loadMissing(['party', 'charges.vehicle.vendor', 'payments', 'sender', 'sentMessage.addresses']);
        $charges = ($invoice ?? $draft)?->charges ?? collect();

        return [
            'doc' => $doc, 'draft' => $draft, 'invoice' => $invoice, 'files' => $doc->files($draft),
            'composition' => ClosingDoc::composition($charges, $doc->month),
            'skipped' => $draft?->getRelation('skipped')->all() ?? [],
            'history' => $invoice ? ClosingDoc::history($invoice) : [],
            'sources' => PaymentSource::manual(),
        ];
    }

    /** Прежний адрес живого документа — его страница. Исправленный — страница отправленного. */
    public function draft(Request $request)
    {
        [$month, $group, $replaces] = $this->draftKey($request);

        return redirect('/money/closing/'.($replaces ? 'i'.$replaces->id : ClosingDoc::liveKey($month, $group)));
    }

    /**
     * Таблица «Счетов и актов» — одно описание на шапку, заголовки месяцев и строки `x-billing.closing-row` (06.11.2026,
     * владелец: пустую полосу на ПК — делом): плательщик, за что (хранение, эвакуация), состояние, когда отправлен и до
     * какого оплатить, сколько строк в акте, сумма; файлов в строке нет — они на странице документа. Порядок парковочный (`Table::park`: когда → сколько) — сумма крайней справа, итог
     * месяца в заголовке группы встаёт над ней.
     */
    public static function closingTable(): Table
    {
        return Table::park(
            new Column('title', 'Плательщик', grow: true),
            new Column('what', 'За что', from: 'sm', role: ColRole::Code),
            new Column('state', 'Состояние', from: 'sm', role: ColRole::State),
            new Column('sent', 'Отправлен', from: 'md', cell: 'nums', role: ColRole::When),
            new Column('due', 'Оплатить до', from: 'md', cell: 'nums', role: ColRole::When),
            new Column('cars', 'Строк', from: 'sm', num: true, cell: 'nums'),
            new Column('amount', 'Сумма', num: true, cell: 'nums'),
        );
    }

    public function draftPdf(Request $request, InvoicePdf $pdf)
    {
        [$month, $group, $replaces] = $this->draftKey($request);
        abort_unless($draft = Closing::draft($month, $group, $replaces), 404);

        return response($pdf->render($draft), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode('Счет '.$draft->fileNo().'.pdf')]);
    }

    /** Акт живого документа — файл формы страховой (`?n=` — второй у РГС), у счёта на ТС — PDF акта хранения. */
    public function draftAct(Request $request, StorageActPdf $act, RegistryAct $registry)
    {
        [$month, $group, $replaces] = $this->draftKey($request);
        abort_unless($draft = Closing::draft($month, $group, $replaces), 404);
        if (! $draft->isRegistry()) {
            return response($act->render($draft), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="akt.pdf"']);
        }
        // Заполненные файлы — временные: отдать нужный и стереть все (у РГС их два), иначе копятся в /tmp при каждом просмотре.
        $files = $registry->files($draft);
        $name = array_keys($files)[(int) $request->query('n', 0)] ?? null;
        try {
            abort_unless($name !== null, 404);
            $response = $request->boolean('preview')
                ? OfficePreview::response($files[$name], $name)
                : response(file_get_contents($files[$name]), 200, ['Content-Type' => mime_content_type($files[$name]) ?: 'application/octet-stream',
                    'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($name)]);
        } finally {
            array_map(fn ($p) => @unlink($p), $files);
        }

        return $response;
    }

    /** «Отправили сами» — документ ушёл не нашим письмом (на бумаге, в мессенджере): фиксируется как отправленный. */
    public function fix(Request $request, FixClosingDocument $fix)
    {
        [$month, $group, $replaces] = $this->draftKey($request);
        abort_unless($request->user()->canManagePark(), 403);
        // Прошлый документ ушёл по-старому — свой номер из архива и день отправки (`FixClosingDocument`).
        $own = $request->validate(['code' => ['nullable', 'string', 'max:40'], 'sent_on' => ['nullable', 'date', 'before_or_equal:today']]);
        try {
            $invoice = $fix($month, $group, $request->user(), $replaces, $own['code'] ?? null, isset($own['sent_on']) ? Carbon::parse($own['sent_on']) : null);
        } catch (ValidationException $e) {
            if ($e->validator->errors()->has('code')) {
                return back()->withErrors($e->errors())->withInput();
            }

            return redirect('/money/closing/'.ClosingDoc::liveKey($month, $group))->with('toast', implode(', ', $e->validator->errors()->all()));
        }

        return redirect('/money/closing/i'.$invoice->id)->with('toast', 'Отмечен отправленным, '.$invoice->label().($fix->skipped ? '. Не вошли: '.implode('; ', $fix->skipped) : ''));
    }

    /** @return array{0: Carbon, 1: string, 2: ?Invoice} */
    private function draftKey(Request $request): array
    {
        // Ключ строки в пути (`/money/draft/{key}/pdf`) — месяц и группа из него.
        if (is_string($key = $request->route('key')) && ($live = ClosingDoc::parseLive($key))) {
            $request->merge(['month' => $live[0]->format('Y-m'), 'group' => $live[1]]);
        }
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'group' => ['required', 'string', 'max:60'], 'replaces' => ['nullable', 'integer']]);
        $replaces = isset($data['replaces']) ? Invoice::ofPark()->where('state', InvoiceState::Issued)->findOrFail($data['replaces']) : null;
        abort_if($replaces && $replaces->paid > 0, 422);

        return [Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth(), $data['group'], $replaces];
    }

    private static function month(Request $request): Carbon
    {
        return $request->query('month') && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? Carbon::createFromFormat('!Y-m', $request->query('month'))->startOfMonth() : now()->subMonth()->startOfMonth();
    }

    /** Таблица счетов парковки — одно описание на шапку и строки `x-billing.table-row`. */
    public static function table(): Table
    {
        return Table::of(
            new Column('title', 'Контрагент', grow: true),
            new Column('number', '№', from: 'sm', sort: 'fresh', role: ColRole::Code, rem: 6),
            new Column('what', 'За что', from: 'lg', aside: true, role: ColRole::State, rem: 12),
            new Column('amount', 'Сумма', num: true, sort: 'amount', cell: 'nums', rem: 6.5),
            new Column('rest', 'Остаток', from: 'sm', num: true, sort: 'rest', cell: 'nums', rem: 6.5),
            new Column('due', 'Срок', from: 'sm', sort: 'due', role: ColRole::When, rem: 6),
        );
    }
}
