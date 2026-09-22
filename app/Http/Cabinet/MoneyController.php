<?php

namespace App\Http\Cabinet;

use App\Billing\Actions\ClaimPayment;
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
use App\Offers\Deal;
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

    /** Страница счёта у менеджера — это расчёт сделки; старые ссылки и уведомления ведут туда. */
    public function invoice(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->isVisibleToManager($request->user()) && $invoice->deal_id, 404);

        return redirect('/account/money/deals/'.$invoice->deal_id, 301);
    }

    /** Расчёт по сделке: цена, счета со строками и оплатами, вознаграждение и выплаты, история. */
    public function deal(Request $request, Deal $deal)
    {
        $me = $request->user();
        abort_unless($deal->buyer_id === $me->id, 404);
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'agentFee.payments.media']);
        $invoices = $deal->issuedInvoices()->with(['party', 'charges', 'allPayments.media'])->get();
        $history = (new ManagerLedger($me))->history()->where('deal', $deal->id)->reverse()->values();

        return view('cabinet.money.deal', [
            'deal' => $deal, 'offer' => $deal->offer, 'invoices' => $invoices, 'fee' => $deal->agentFee, 'state' => $deal->commissionState(), 'history' => $history,
            'claimable' => $invoices->filter(fn (Invoice $i) => $i->state === InvoiceState::Issued && $i->remaining() - $i->claimed() > 0)->values(),
        ]);
    }

    public function claim(Request $request, Invoice $invoice, ClaimPayment $claim)
    {
        abort_unless($invoice->isVisibleToManager($request->user()) && ! $invoice->isOwed(), 404);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['required', 'date'], 'ref' => ['nullable', 'string', 'max:60'], 'slip' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic']]);
        $claim($invoice, $request->user(), (float) $data['amount'], Carbon::parse($data['paid_at']), $request->file('slip'), $data['ref'] ?? null);

        return redirect('/account/money/deals/'.$invoice->deal_id)->with('toast', 'Сообщили, ждём подтверждения');
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

        return response()->download($path, 'sdelki-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.xlsx', [], $request->boolean('inline') ? 'inline' : 'attachment')->deleteFileAfterSend();
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
