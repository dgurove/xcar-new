<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Деньги глазами менеджера: счета, которые ему платить, вознаграждение по сделкам,
 * лента операций и итоги за период. Только чтение — базу не трогает; закупочную и
 * «нам» не знает.
 */
final class ManagerLedger
{
    public function __construct(private User $manager) {}

    /** Наши счета к оплате: его контрагенту или по его сделкам (платит его покупатель). */
    public function toPay(): Collection
    {
        return Invoice::visibleToManager($this->manager)->where('direction', 'issued')->where('state', InvoiceState::Issued)
            ->with(['party', 'deal.offer.brand', 'deal.offer.model', 'deal.offer.media', 'claims'])->orderBy('due_at')->orderBy('id')->get();
    }

    /** Все счета, что ему видны, — для истории и акта. */
    public function invoices(): Collection
    {
        return Invoice::visibleToManager($this->manager)->where('state', '!=', InvoiceState::Void)->with(['party', 'deal.offer', 'allPayments'])->orderBy('issued_at')->orderBy('id')->get();
    }

    /** Сделки с вознаграждением, которое ему уже открыто: есть живой счёт. */
    public function feeDeals(): Collection
    {
        return Deal::where('buyer_id', $this->manager->id)->whereNotNull('commission')->where('commission', '>', 0)
            ->whereHas('invoices')->with(['offer.brand', 'offer.model', 'offer.media', 'agentFee'])->latest()->get();
    }

    /** К выплате сейчас — остаток по обязательствам перед ним. */
    public function payable(): float
    {
        return $this->manager->party_id ? round((float) Invoice::where('party_id', $this->manager->party_id)->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)
            ->where('state', InvoiceState::Issued)->selectRaw('coalesce(sum(total - paid), 0) as s')->value('s'), 2) : 0;
    }

    /**
     * Лента операций: счёт выставлен, сообщили об оплате, оплата принята или не поступила, удержано,
     * вознаграждение к выплате, выплачено. Каждая — дата, слова, сумма, куда вести.
     *
     * @return Collection<int, array{at: CarbonInterface, title: string, amount: float, kind: string, href: string, offer: ?string}>
     */
    public function history(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $rows = collect();
        foreach ($this->invoices() as $i) {
            $offer = $i->deal?->offer?->titleWithYear();
            $href = $i->isOwed() && $i->deal_id ? '/account/money/deals/'.$i->deal_id : '/account/money/invoices/'.$i->id;
            $rows->push(['at' => $i->issued_at->copy()->setTimeFrom($i->created_at), 'title' => $i->isAgentFee() ? 'Вознаграждение к выплате' : 'Счёт '.$i->label().' выставлен', 'amount' => $i->total, 'kind' => $i->isAgentFee() ? 'fee' : 'invoice', 'href' => $href, 'offer' => $offer]);
            foreach ($i->allPayments as $p) {
                $rows->push(match (true) {
                    $p->state === PaymentState::Claimed => ['at' => $p->created_at, 'title' => 'Сообщили об оплате', 'amount' => $p->amount, 'kind' => 'claim', 'href' => $href, 'offer' => $offer],
                    $p->state === PaymentState::Rejected => ['at' => $p->decided_at ?? $p->updated_at, 'title' => 'Оплата не поступила', 'amount' => $p->amount, 'kind' => 'rejected', 'href' => $href, 'offer' => $offer],
                    $p->source === PaymentSource::Offset => ['at' => $p->created_at, 'title' => 'Удержано агентское вознаграждение', 'amount' => $p->amount, 'kind' => 'offset', 'href' => $href, 'offer' => $offer],
                    $i->isOwed() => ['at' => $p->paid_at->copy()->setTimeFrom($p->decided_at ?? $p->created_at), 'title' => 'Выплачено', 'amount' => $p->amount, 'kind' => 'payout', 'href' => $href, 'offer' => $offer],
                    default => ['at' => $p->paid_at->copy()->setTimeFrom($p->decided_at ?? $p->created_at), 'title' => 'Оплата принята', 'amount' => $p->amount, 'kind' => 'paid', 'href' => $href, 'offer' => $offer],
                });
            }
        }

        return $rows->filter(fn ($r) => (! $from || $r['at']->gte($from)) && (! $to || $r['at']->lte($to)))->sortByDesc('at')->values();
    }

    /** Итоги ленты: оплачено нам, выплачено ему. */
    public function totals(Collection $history): array
    {
        return [
            'paid' => round($history->where('kind', 'paid')->sum('amount'), 2),
            'payouts' => round($history->where('kind', 'payout')->sum('amount'), 2),
            'offset' => round($history->where('kind', 'offset')->sum('amount'), 2),
        ];
    }

    /** Сделки за период для выгрузки: цена, счёт, оплачено, вознаграждение, выплачено. */
    public function dealsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Deal::where('buyer_id', $this->manager->id)->whereBetween('created_at', [$from, $to])->whereIn('state', [DealState::Active, DealState::Done])
            ->with(['offer', 'invoices.allPayments', 'agentFee'])->orderBy('created_at')->get();
    }
}
