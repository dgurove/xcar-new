<?php

namespace App\Http\Cabinet;

use App\Billing\Actions\ClaimPayment;
use App\Billing\Documents\StatementPdf;
use App\Billing\Export\ManagerStatement;
use App\Billing\Invoice;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PartyRules;
use App\Billing\Payment;
use App\Offers\Deal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * «Деньги» менеджера: счета к оплате с заявкой об оплате, вознаграждение по сделкам,
 * история за период, реквизиты, акт сверки и выгрузка. Всё — только своё, одной
 * дверью `Invoice::visibleToManager`; закупочной и «нам» тут нет.
 */
class MoneyController
{
    public function index(Request $request)
    {
        $me = $request->user();
        $ledger = new ManagerLedger($me);
        $party = Party::forUser($me, false);
        $history = $ledger->history();

        return view('cabinet.money.index', [
            'toPay' => $ledger->toPay(), 'deals' => $ledger->feeDeals(), 'payable' => $ledger->payable(),
            'party' => $party, 'recent' => $history->take(5), 'total' => $history->count(),
            'month' => now()->startOfMonth(),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $me = $request->user();
        abort_unless($invoice->isVisibleToManager($me) && ! $invoice->isOwed(), 404);
        $invoice->load(['party', 'charges', 'payments.media', 'claims.media', 'allPayments', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media']);

        return view('cabinet.money.invoice', ['invoice' => $invoice, 'file' => $invoice->getFirstMedia('file'), 'left' => round($invoice->remaining() - $invoice->claimed(), 2)]);
    }

    /** Расклад по сделке: цена, счета, вознаграждение и выплаты — только когда счёт уже есть. */
    public function deal(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->showsCommission(), 404);
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'agentFee.payments.media']);

        return view('cabinet.money.deal', ['deal' => $deal, 'offer' => $deal->offer, 'invoices' => $deal->issuedInvoices()->with('party')->get(), 'fee' => $deal->agentFee, 'state' => $deal->commissionState()]);
    }

    public function claim(Request $request, Invoice $invoice, ClaimPayment $claim)
    {
        abort_unless($invoice->isVisibleToManager($request->user()) && ! $invoice->isOwed(), 404);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'paid_at' => ['required', 'date'], 'ref' => ['nullable', 'string', 'max:60'], 'slip' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic']]);
        $claim($invoice, $request->user(), (float) $data['amount'], Carbon::parse($data['paid_at']), $request->file('slip'), $data['ref'] ?? null);

        return redirect('/account/money/invoices/'.$invoice->id)->with('toast', 'Сообщили, ждём подтверждения');
    }

    public function history(Request $request)
    {
        $ledger = new ManagerLedger($request->user());
        $month = $request->query('month') && preg_match('/^\d{4}-\d{2}$/', $request->query('month')) ? Carbon::createFromFormat('Y-m', $request->query('month'))->startOfMonth() : null;
        $rows = $ledger->history($month, $month?->copy()->endOfMonth());
        // Месяцы для выбора — те, где что-то было, плюс текущий.
        $months = $ledger->history()->map(fn ($r) => $r['at']->format('Y-m'))->push(now()->format('Y-m'))->unique()->sortDesc()->values()
            ->mapWithKeys(fn ($m) => [$m => mb_convert_case(Carbon::createFromFormat('Y-m', $m)->translatedFormat('F Y'), MB_CASE_TITLE)]);

        return view('cabinet.money.history', ['rows' => $rows, 'totals' => $ledger->totals($rows), 'month' => $month, 'months' => ['' => 'За всё время'] + $months->all()]);
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
