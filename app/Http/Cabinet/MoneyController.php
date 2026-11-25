<?php

namespace App\Http\Cabinet;

use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\Actions\CreatePayLink;
use App\Billing\Acquiring\Actions\SetPayerEmail;
use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\PayerKind;
use App\Billing\Acquiring\PayLink;
use App\Billing\Actions\SaveRequisites;
use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\MoneyBook;
use App\Billing\Documents\StatementPdf;
use App\Billing\Export\ManagerStatement;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PartyRules;
use App\Billing\Payment;
use App\Garage\Car as GarageCar;
use App\Logistics\Transport;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Support\Money;
use App\Support\OfficePreview;
use App\Support\Portion;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use App\Support\Surface;
use App\Support\TempFile;
use App\Media\PhotosPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «Оплаты» менеджера: его сделки по разделам «Комиссии · ДКП» и заработок за месяц (с 22.11.2026 — тем же складом, что «Оплаты»
 * CRM), оплата сделки (шторка «Оплатить» — на странице сделки), реквизиты, акт сверки и выгрузка в «···».
 * Всё — только своё, одной дверью `Invoice::visibleToManager`; закупочной и «нам» тут нет.
 */
class MoneyController
{
    /** Разделы экрана: как в «Оплатах» CRM — «Комиссии» и «ДКП»; «Выплаты» — его перевозки, когда есть (`ManagerLedger::transports`). */
    public const TABS = ['fees' => 'Комиссии', 'dkp' => 'ДКП', 'payouts' => 'Выплаты'];

    /**
     * «Оплаты» менеджера — один экран на xcar.ru и XCAR Pay (22.11.2026, начальник: «менеджеры видят, что должны по комиссиям и по
     * ДКП и сколько заработали»; владелец: «один экран везде»). Тот же склад, что страница человека в «Оплатах» CRM (`MoneyBook`
     * своим режимом: только отправленные ему счета, `Invoice::shownToManager`): разделы крупными заголовками, группы «К оплате» · «Готовим счёт» · «Оплачены» лентой. Сделки — с «Беру в работу», без отменённых
     * (умолчание чипа CRM, сам чип не нужен: этапы маршрута — наши).
     */
    public function index(Request $request)
    {
        $me = $request->user();
        $transports = (new ManagerLedger($me))->transports();
        $tabs = $transports->isNotEmpty() ? self::TABS : array_diff_key(self::TABS, ['payouts' => 0]);
        $facets = MoneyBook::facets($request, 'account-money');
        $counts = array_filter([
            'fees' => MoneyBook::openDeals('fees', $me, $facets, own: true)->count(),
            'dkp' => MoneyBook::openDeals('dkp', $me, $facets, own: true)->count(),
            'payouts' => $transports->filter(fn ($r) => $r->money->preset === 'payout')->count(),
        ]);
        // Раздел — из адреса; без него — первый, где есть что оплатить.
        $tab = array_key_exists((string) $request->query('tab'), $tabs) ? (string) $request->query('tab')
            : (array_key_first(array_intersect_key($counts, $tabs)) ?? 'fees');
        $view = ['tabs' => $tabs, 'tab' => $tab, 'counts' => $counts, 'party' => Party::forUser($me, false), 'me' => $me];
        if ($tab === 'payouts') {
            return view('cabinet.money.index', $view + ['transports' => $transports, 'rows' => collect(), 'paid' => collect(), 'portion' => null]);
        }
        $open = MoneyBook::open($tab, $me, $facets, own: true);
        $paidQ = MoneyBook::paid($tab, $me, $facets, own: true);
        $portion = Portion::of($request, $paidQ, (clone $paidQ)->reorder()->count());
        $paid = MoneyBook::paidRows($portion->items());
        $attempts = AcquiringPayment::whereIn('payment_id', $paid->flatMap(fn ($r) => $r->invoices->flatMap(fn ($i) => $i->payments->pluck('id'))))->get()->keyBy('payment_id');

        return view('cabinet.money.index', $view + ['rows' => $open, 'paid' => $paid, 'portion' => $portion, 'attempts' => $attempts, 'table' => self::table()]);
    }

    /** Столбцы экрана: что за машина, что по сделке его словами, что с оплатой, его вознаграждение, когда выставили, сколько. */
    public static function table(): Table
    {
        return Table::of(new Column('title', 'Марка, модель', grow: true, rem: 14),
            new Column('state', 'Статус', from: 'sm', role: ColRole::State, rem: 13),
            new Column('pay', 'Оплата', from: 'sm', role: ColRole::State, rem: 10, fixed: true),
            new Column('commission', 'Вознаграждение', from: 'md', num: true, role: ColRole::When, cell: 'nums', rem: 8, fixed: true),
            new Column('since', 'Выставлено', from: 'sm', num: true, role: ColRole::When, cell: 'nums', rem: 7.5, fixed: true),
            new Column('amount', 'Сумма', num: true, role: ColRole::When, cell: 'nums', rem: 7, fixed: true));
    }

    /** Страница счёта у менеджера — это расчёт сделки (или машина в гараже); старые ссылки и уведомления ведут туда. */
    public function invoice(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->isVisibleToManager($request->user()), 404);
        // Гаражный счёт без сделки — расчёт живёт на машине в гараже; долг за перевозку партнёру — в логистике.
        $transport = $invoice->isTransport() ? Transport::firstWhere('invoice_id', $invoice->id) : null;
        $url = match (true) {
            (bool) $invoice->deal_id => $invoice->deal->href().'#money',
            (bool) $transport => Surface::Logistics->url('/transports/'.$transport->id),
            default => GarageCar::ofInvoice($invoice)?->url(),
        };
        abort_unless($url, 404);

        return redirect($url, 301);
    }

    /**
     * Расчёт по сделке жил отдельной страницей — с 19.10.2026 он часть страницы сделки (владелец: «менеджер путает страницу
     * оплаты счёта и страницу сделки»). Старые ссылки (уведомления, Telegram, закладки) ведут туда; `?pay=1` открывает
     * «Оплатить», у гаражной — машина.
     */
    public function deal(Request $request, Deal $deal)
    {

        return redirect($deal->href().($request->boolean('pay') ? '?pay=1' : '').'#money', 301);
    }

    /** «Оплатить»: ссылкой, по счёту или наличными — одной шторкой. */
    public function pay(Request $request, Deal $deal, PayChoice $choice)
    {
        $me = $request->user();
        $invoice = $deal->issuedInvoices()->whereKey((int) $request->input('invoice'))->firstOrFail();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed(), 404);
        // Чек по счёту и наличные — с городом подписания (25.11.2026); ссылку на оплату картой не держим.
        if ($request->input('way') !== 'link' && ($gap = $deal->signingCityGap($deal->offer->position(\App\Workflow\Track::Sale)?->stage))) {
            return redirect($deal->href().'#money')->with('toast', $gap);
        }

        [$toast, $link] = $choice($request, $invoice, $me);
        // «Оплатить» есть и в задаче сделки, и на машине в гараже (гаражная сделка ещё идёт) — ответ туда, откуда платили.
        $back = parse_url(url()->previous(), PHP_URL_PATH);
        $car = $deal->isGarage() ? $deal->garageCar : null;
        $from = match (true) {
            $back === '/deals/'.$deal->id => $back,
            $car && $back === parse_url($car->url(), PHP_URL_PATH) => $back,
            default => $deal->href(),
        };

        return redirect($from)->with('toast', $toast)->with('open-link', $link?->id);
    }

    /**
     * «Приложить платёжное поручение» к нашему счёту одним документом (24.11.2026, `x-ui.photo-pdf`): фото и файлы собраны
     * в один (`PhotosPdf`) и идут чеком «я оплатил» на остаток — как файл `slip` у «По счёту» (`PayChoice`).
     */
    public function payDoc(Request $request, Deal $deal, PayChoice $choice, PhotosPdf $photos)
    {
        $me = $request->user();
        $invoice = $deal->issuedInvoices()->whereKey((int) $request->input('invoice'))->firstOrFail();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed(), 404);
        // Город подписания — до документа об оплате (25.11.2026).
        if ($gap = $deal->signingCityGap($deal->offer->position(\App\Workflow\Track::Sale)?->stage)) {
            return response()->json(['message' => $gap], 422);
        }
        $doc = $photos->fromRequest($request, $deal->offer->title());
        $pay = $request->duplicate($request->query->all(), ['way' => 'transfer', 'invoice' => $invoice->id], null, null, ['slip' => $doc['file']]);
        [$toast, $link] = $choice($pay, $invoice, $me);
        session()->flash('toast', $toast);
        $link && session()->flash('open-link', $link->id);

        return response()->json(['reload' => true, 'name' => $doc['name'], 'pages' => $doc['pages']]);
    }

    /**
     * Новая ссылка своего счёта: без суммы — на остаток (как при выставлении), с суммой — на неё (часть, лимит
     * одного платежа). Плательщик — контрагент счёта, почту спросит `/pay`. Прежняя открытая гаснет.
     */
    public function link(Request $request, Invoice $invoice, CreatePayLink $create)
    {
        $me = $request->user();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed(), 404);
        abort_unless(app(Gateway::class)->configured(), 422, 'Оплата по ссылке не подключена');
        $request->merge(['amount' => $request->filled('amount') ? Money::parse($request->input('amount')) : null]);
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0.01']]);
        $party = $invoice->party;
        $link = $create($invoice, $me, isset($data['amount']) ? (float) $data['amount'] : PayLink::defaultAmount($invoice), PayerKind::Other, null, $party->name, $party->phone, $party->email);

        return back()->with('toast', 'Ссылка готова')->with('open-link', $link->id);
    }

    /** Почта для чека из шторки ссылки своего счёта. */
    public function linkEmail(Request $request, PayLink $link, SetPayerEmail $set)
    {
        abort_unless($link->invoice->isVisibleToManager($request->user()) && ! $link->invoice->isOwed(), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:120']], ['email.required' => 'Впишите почту', 'email.email' => 'Проверьте почту']);
        $set($link, $data['email']);

        return back()->with('toast', 'Почта для чека сохранена')->with('open-link', $link->id);
    }

    public function cancelLink(Request $request, PayLink $link, CancelPayLink $cancel)
    {
        // Отменить можно любую ссылку своего счёта, и ту, что завёл сотрудник.
        abort_unless($link->invoice->isVisibleToManager($request->user()) && ! $link->invoice->isOwed(), 404);
        $cancel($link, $request->user());

        return back()->with('toast', 'Ссылка отменена');
    }

    public function details(Request $request)
    {
        return view('cabinet.money.details', ['party' => Party::forUser($request->user(), false), 'kinds' => PartyKind::options()]);
    }

    public function saveDetails(Request $request)
    {
        PartyRules::squeezePassport($request);
        $data = $request->validate(PartyRules::rules());
        app(SaveRequisites::class)($request->user(), $data);
        // «Я сам» с паспортом и сканом — счёт и договор его сделок встают сами.
        PassportScanController::resyncFor($request->user(), $request->user(), app(SyncDealInvoices::class));

        return redirect('/account/money')->with('toast', 'Реквизиты сохранены');
    }

    /** PDF счёта — плательщику или менеджеру сделки. */
    public function pdf(Request $request, Invoice $invoice)
    {
        // Аннулированный — нет: в перевыставленном счёте могли быть прежние суммы.
        abort_unless($invoice->isVisibleToManager($request->user()) && $invoice->state !== InvoiceState::Void, 404);
        abort_unless($invoice->hasPdf(), 404);

        return app(\App\Billing\Documents\InvoicePdf::class)->response($invoice);
    }

    /** Скан платёжки — своей заявки или своей выплаты. */
    public function slip(Request $request, Invoice $invoice, Payment $payment)
    {
        abort_unless($invoice->isVisibleToManager($request->user()) && $payment->invoice_id === $invoice->id && ($media = $payment->slip()), 404);

        return response()->file($media->getPath(), ['Content-Type' => $media->mime_type, 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
    }

    public function statement(Request $request, StatementPdf $pdf)
    {
        [$from, $to] = $this->period($request);
        $name = 'akt-sverki-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.pdf';

        return response($pdf->render($request->user(), $from, $to), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('inline') ? 'inline' : 'attachment').'; filename="'.$name.'"']);
    }

    public function export(Request $request, ManagerStatement $xlsx)
    {
        [$from, $to] = $this->period($request);
        $path = $xlsx->write($request->user(), $from, $to, TempFile::path('sdelki-', 'xlsx'));
        $name = 'sdelki-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.xlsx';
        // Шторка документов просит Excel HTML-фрагментом.
        if ($request->boolean('preview')) {
            try {
                return OfficePreview::response($path, $name);
            } finally {
                @unlink($path);
            }
        }

        return response()->download($path, $name, [], $request->boolean('inline') ? 'inline' : 'attachment')->deleteFileAfterSend();
    }

    /** @return array{Carbon, Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }
}
