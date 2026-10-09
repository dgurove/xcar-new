<?php

namespace App\Offers\Actions;

use App\Billing\Acquiring\Actions\CancelPayLink;
use App\Billing\Acquiring\PayLink;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Счета сделки ставятся сами по её схеме (05.10.2026, владелец: «что такое "Выставите счёт"?» — у каждой схемы известно,
 * кому и какой счёт). Два места:
 * — подбор (`Selection`) менеджеру со ссылкой: у ДКП и «страховой напрямую» — цена − собственнику/страховой −
 *   вознаграждение (850 − 750 − 20 = 80 000) одной строкой; у гаражной «платит менеджер» — наша доля;
 * — ПРАЙМ (`Sale`) без ссылки: «Транспортное средство» = закупочная и «Агентское вознаграждение» = разница (944 000 +
 *   106 000) тому, кого менеджер указал плательщиком; платит менеджер с «удерживает сам» — его вознаграждение зачётом,
 *   платит покупатель — вознаграждение выплачиваем; гаражной «платит менеджер» у ПРАЙМ — менеджеру на закупочную.
 * Счёт, что уже стоит как надо, не трогаем; другой, за который не платили, — аннулируем и ставим заново; оплаченный —
 * история, его не меняем. Не нужен схеме — не трогаем вовсе. Пока сделка согласуется с поставщиком (`Deal::agreed`),
 * счетов нет вовсе (09.10.2026): первые ставит `AgreeDeal`.
 * Разовые оплаты по ссылке с машиной сделки (`Deal::credits`, 09.10.2026, владелец: «засчитывать в сделку») — уже наши
 * деньги: подбор и агентское вознаграждение ПРАЙМ выставляются на них меньше, подбор, покрытый целиком, не выставляется;
 * выставив свой счёт со ссылкой, сделка гасит открытую ручную — ссылка на сделку одна.
 */
final class SyncDealInvoices
{
    private const DAYS = 3;

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private RecordPayment $record, private InvoicePdf $pdf, private CancelPayLink $cancel) {}

    public function __invoke(Deal $deal, User $by): void
    {
        $this->adopt($deal);
        if (! $deal->agreed()) {
            return;
        }
        $deal->loadMissing(['offer', 'buyer', 'contract.buyer']);
        $deal->unsetRelation('credits');
        DB::transaction(function () use ($deal, $by) {
            $this->slot($deal, $by, ChargeKind::Selection, $this->selection($deal));
            $this->slot($deal, $by, ChargeKind::Sale, $this->prime($deal));
        });
    }

    /**
     * Ручная ссылка с машиной этой сделки, взятая до сделки или без привязки (Тужиков 07.10.2026: оплатил 53 000 ручной
     * ссылкой, сделка назавтра выставила ему 58 000), — засчитывается в неё. Привязанную к другой сделке не трогаем.
     */
    private function adopt(Deal $deal): void
    {
        if (! $deal->isActive()) {
            return;
        }
        Invoice::where('offer_id', $deal->offer_id)->where('kind', ChargeKind::Service)->whereNull('deal_id')->whereNull('vehicle_id')
            ->whereNull('credit_deal_id')->where('state', '!=', InvoiceState::Void)->update(['credit_deal_id' => $deal->id]);
    }

    /** @return array{party: Party, lines: list<array>, offset: float}|array{covered: true}|null */
    private function selection(Deal $deal): ?array
    {
        $full = $deal->selectionDue();
        $due = (int) round($full - $deal->credited());
        if ($full > 0 && $due <= 0) {
            return ['covered' => true];
        }
        if ($due <= 0 || ! $deal->buyer) {
            return null;
        }

        return ['party' => Party::forUser($deal->buyer), 'offset' => 0.0,
            'lines' => [['title' => 'Подбор ТС '.$deal->offer->titleWithYear(), 'qty' => 1, 'unit' => 'pc', 'price' => (float) $due, 'kind' => ChargeKind::Selection->value]]];
    }

    /** @return array{party: Party, lines: list<array>, offset: float}|null */
    private function prime(Deal $deal): ?array
    {
        if (! $deal->isPrime() || $deal->cost === null || ! $deal->buyer) {
            return null;
        }
        $car = 'Транспортное средство '.$deal->offer->titleWithYear();
        if ($deal->isGarage()) {
            return $deal->isGarageManager()
                ? ['party' => Party::forUser($deal->buyer), 'offset' => 0.0, 'lines' => [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->cost, 'kind' => ChargeKind::Sale->value]]]
                : null;
        }
        $contract = $deal->contract;
        if (! $contract?->buyer_user_id || $deal->amount === null) {
            return null;
        }
        // Платит покупатель — удерживать не из чего, `Deal::withholds` тогда ложь: вознаграждение выплачиваем после оплаты.
        $managerPays = $contract->managerPays();
        $party = Party::forUser($managerPays ? $deal->buyer : $contract->buyer);
        // Разовая оплата по ссылке с этой машиной — в счёт вознаграждения; строка ТС — закупочная, её не трогаем.
        $fee = max(0, (int) $deal->amount - (int) $deal->cost - (int) round($deal->credited()));
        $lines = [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->cost, 'kind' => ChargeKind::Sale->value]];
        if ($fee > 0) {
            $lines[] = ['title' => 'Агентское вознаграждение', 'qty' => 1, 'unit' => 'pc', 'price' => (float) $fee, 'kind' => ChargeKind::AgentFee->value];
        }

        return ['party' => $party, 'lines' => $lines, 'offset' => $managerPays && $deal->withholds() ? (float) min((int) $deal->commission, $fee) : 0.0];
    }

    private function slot(Deal $deal, User $by, ChargeKind $kind, ?array $want): void
    {
        // Схеме этот счёт сейчас не нужен — что выставили руками («Ещё счёт»), не трогаем; при смене схемы прежний
        // гасит `UpdateDealMoney`.
        if (! $want) {
            return;
        }
        if ($want['covered'] ?? false) {
            // Подбор оплачен разовой ссылкой целиком: неоплаченный счёт подбора сделке больше не нужен.
            Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', $kind)->where('state', InvoiceState::Issued)->where('paid', 0)
                ->whereDoesntHave('claims')->get()->each(fn (Invoice $i) => ($this->void)($i, $by, 'Оплачено разовой ссылкой'));

            return;
        }
        $current = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', $kind)->where('state', '!=', InvoiceState::Void)
            ->with(['payments', 'claims', 'charges'])->get();
        $total = $want ? round(array_sum(array_map(fn ($l) => $l['price'] * $l['qty'], $want['lines'])), 2) : null;
        // Сверяем строки, а не итог: у плательщика с «НДС сверху» итог больше строк.
        $fits = fn (Invoice $i) => $want && $i->party_id === $want['party']->id && abs((float) $i->charges->whereNull('voided_at')->sum(fn ($c) => $c->price * $c->qty) - $total) < 0.01
            && abs($i->payments->where('source', PaymentSource::Offset)->sum('amount') - $want['offset']) < 0.01;
        $paid = fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => $p->source !== PaymentSource::Offset);

        $keep = $current->first(fn (Invoice $i) => $fits($i) || $paid($i));
        foreach ($current as $invoice) {
            if ($invoice->isNot($keep) && ! $paid($invoice)) {
                ($this->void)($invoice, $by, 'Деньги сделки изменены');
            }
        }
        if ($keep) {
            return;
        }
        $invoice = ($this->issue)($want['party'], $by, 'issued', $kind, $this->dueFor($deal, $kind), lines: $want['lines'], dealId: $deal->id, offerId: $deal->offer_id);
        if ($want['offset'] > 0) {
            ($this->record)($invoice, $by, $want['offset'], null, PaymentSource::Offset, null, 'Удержано агентское вознаграждение');
            $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
        }
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).' — '.$want['party']->name]);
        if (PayLink::eligible($invoice)) {
            $this->retireCredits($deal, $by);
        }
    }

    /**
     * Сделка выставила свой счёт со ссылкой — открытая ручная по этой машине гаснет: ссылка на сделку одна. Неоплаченный
     * ручной счёт аннулируется, частично оплаченный остаётся (его деньги уже засчитаны) без ссылки.
     */
    private function retireCredits(Deal $deal, User $by): void
    {
        foreach ($deal->credits()->where('state', InvoiceState::Issued)->with(['payLinks', 'claims', 'party'])->get() as $credit) {
            if ($credit->paid == 0 && $credit->claims->isEmpty()) {
                ($this->void)($credit, $by, 'Сделка выставила свой счёт');
            } elseif ($open = $credit->openLink()) {
                ($this->cancel)($open);
            }
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::rub($credit->total).' ('.$credit->party?->name.') погашена: по сделке выставлен свой счёт']);
        }
    }

    /**
     * Срок счёта. Подбор не гаражной сделки — без срока, пока маршрут не дошёл до шага оплаты (06.10.2026, владелец: до
     * договора с покупателем менеджер нам не должник): срок поставит сам шаг (`StartSelectionDue`). На шаге оплаты — его
     * часы; маршрут без шага оплаты — три дня, как было.
     */
    private function dueFor(Deal $deal, ChargeKind $kind): ?Carbon
    {
        $default = Carbon::now()->addDays(self::DAYS);
        if ($kind !== ChargeKind::Selection || $deal->isGarage()) {
            return $default;
        }
        $deal->offer->unsetRelation('positions');
        $position = $deal->offer->position(Track::Sale);
        if (! $position) {
            return $default;
        }
        if ($position->stage->isPayStep()) {
            return $position->deadline_at ?? $default;
        }
        $stages = Stage::where('workflow_id', $position->stage->workflow_id)->with('exits')->get();

        return $stages->contains(fn (Stage $s) => $s->isPayStep()) ? null : $default;
    }
}
