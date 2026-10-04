<?php

namespace App\Http\Cabinet;

use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\ChargeKind;
use App\Billing\DealMoney;
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
use App\Offers\Deal;
use App\Support\OfficePreview;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «Деньги» менеджера: положение (оплатить / к выплате), сделки-расчёты по пресетам,
 * расчёт сделки с заявкой об оплате, реквизиты, акт сверки и выгрузка в «···».
 * Всё — только своё, одной дверью `Invoice::visibleToManager`; закупочной и «нам» тут нет.
 */
class MoneyController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $ledger = new ManagerLedger($me);
        $preset = array_key_exists($request->query('preset', ''), DealMoney::PRESETS) ? $request->query('preset') : 'all';

        return view('cabinet.money.index', [
            'deals' => $ledger->deals($preset), 'counts' => $ledger->counts(), 'preset' => $preset,
            'position' => $ledger->position(), 'party' => Party::forUser($me, false),
        ]);
    }

    /** Страница счёта у менеджера — это расчёт сделки (или машина в гараже); старые ссылки и уведомления ведут туда. */
    public function invoice(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->isVisibleToManager($request->user()), 404);
        // Гаражный счёт без сделки — расчёт живёт на машине в гараже.
        $url = $invoice->deal_id ? '/account/money/deals/'.$invoice->deal_id : GarageCar::ofInvoice($invoice)?->url();
        abort_unless($url, 404);

        return redirect($url, 301);
    }

    /** Расчёт по сделке: цена, счета со строками и оплатами, вознаграждение и выплаты, история. */
    public function deal(Request $request, Deal $deal)
    {
        $me = $request->user();
        abort_unless($deal->buyer_id === $me->id, 404);
        // Счета сделки с заявками и ссылками — сразу: расклад (`DealMoney`) и состояние вознаграждения читают их по
        // нескольку раз за страницу, без загрузки каждый раз шли в базу.
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'agentFee.payments.media', 'invoices.claims', 'invoices.payLinks']);
        $invoices = $deal->issuedInvoices()->with(['party', 'charges', 'allPayments.media', 'claims', 'payLinks'])->get();
        $links = PayLink::whereIn('invoice_id', $invoices->pluck('id'))->where('state', PayLinkState::Open)->with(['invoice', 'payerUser', 'creator'])->get();

        return view('cabinet.money.deal', [
            'deal' => $deal, 'offer' => $deal->offer, 'invoices' => $invoices, 'fee' => $deal->agentFee, 'state' => $deal->commissionState(),
            // Счёт вендору (вознаграждение от поставщика) платит не менеджер: его в «Оплатить» нет.
            'claimable' => $invoices->filter(fn (Invoice $i) => $i->state === InvoiceState::Issued && $i->kind !== ChargeKind::Reward && $i->remaining() - $i->claimed() > 0)->values(),
            'links' => $links, 'buyers' => $me->buyers()->with(User::withAvatar())->orderBy('name')->get(),
        ]);
    }

    /** «Оплатить»: ссылкой, по счёту или наличными — одной шторкой. */
    public function pay(Request $request, Deal $deal, PayChoice $choice)
    {
        $me = $request->user();
        abort_unless($deal->buyer_id === $me->id, 404);
        $invoice = $deal->issuedInvoices()->whereKey((int) $request->input('invoice'))->firstOrFail();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed() && $invoice->kind !== ChargeKind::Reward, 404);

        [$toast, $link] = $choice($request, $invoice, $me);

        return redirect('/account/money/deals/'.$deal->id)->with('toast', $toast)->with('open-link', $link?->id);
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
        $data = $request->validate(PartyRules::rules());
        $party = Party::forUser($request->user());
        $party->update($data + ['card' => isset($data['card']) ? preg_replace('/\D/', '', $data['card']) : null]);

        return redirect('/account/money')->with('toast', 'Реквизиты сохранены');
    }

    /** PDF счёта — плательщику или менеджеру сделки. */
    public function pdf(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->isVisibleToManager($request->user()), 404);
        $media = $invoice->getFirstMedia('file');
        abort_unless($media, 404);

        return response()->file($media->getPath(), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$media->file_name.'"']);
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
        $path = $xlsx->write($request->user(), $from, $to, tempnam(sys_get_temp_dir(), 'sdelki-').'.xlsx');
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
