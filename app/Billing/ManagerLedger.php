<?php

namespace App\Billing;

use App\Garage\Car as GarageCar;
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
    /** Все сделки-расчёты с раскладом: экран берёт и список пресета, и числа пилюль — считаем раз. */
    private ?Collection $all = null;

    public function __construct(private User $manager) {}

    /** Наши счета к оплате: его контрагенту или по его сделкам (платит его покупатель). */
    public function toPay(): Collection
    {
        return Invoice::visibleToManager($this->manager)->where('direction', 'issued')->where('state', InvoiceState::Issued)
            // Для положения нужны суммы, сроки и заявки — ТС и фото сделки не рисуются.
            ->with(['claims'])->orderBy('due_at')->orderBy('id')->get();
    }

    /** Все счета, что ему видны, — для истории и акта. */
    public function invoices(): Collection
    {
        return Invoice::visibleToManager($this->manager)->where('state', '!=', InvoiceState::Void)->with(['party', 'deal.offer', 'allPayments'])->orderBy('issued_at')->orderBy('id')->get();
    }

    /**
     * Сделки-расчёты по пресету: все (идущие и со счётом), оплатить, ждут выплаты, закрытые.
     * Требующие действия — первыми, дальше по дате. Считается в памяти: сделок у менеджера десятки.
     *
     * @return Collection<int, Deal>
     */
    public function deals(string $preset = 'all'): Collection
    {
        if (! $this->all) {
            // Гаражная сделка без счёта — не расчёт (там страховая, деньги — в гараже); со счётом (Каркаде, платит
            // менеджер) — как любая.
            $this->all = Deal::where('buyer_id', $this->manager->id)
                ->where(fn ($q) => $q->where('state', DealState::Active)->orWhereHas('invoices'))
                ->where(fn ($q) => $q->whereNull('garage_payer')->orWhereHas('invoices'))
                ->with(['offer.brand', 'offer.model', 'offer.media', 'invoices.claims', 'invoices.payLinks', 'agentFee'])->latest()->get();
            $this->all->each(fn (Deal $d) => $d->setAttribute('money', DealMoney::of($d)));
        }
        $deals = $this->all->filter(fn (Deal $d) => match ($preset) {
            'pay', 'payout', 'closed' => $d->money->preset === $preset,
            default => true,
        });

        return $deals->sortBy([fn (Deal $a, Deal $b) => ($b->money->needsAction() <=> $a->money->needsAction()) ?: ($b->created_at <=> $a->created_at)])->values();
    }

    /** Машины гаража с его счётом или выплатой — строками рядом со сделками (`DealMoney::garage`). */
    private ?Collection $cars = null;

    /** @return Collection<int, GarageCar> с `money` */
    public function cars(string $preset = 'all'): Collection
    {
        $this->cars ??= GarageCar::where('manager_id', $this->manager->id)
            ->where(fn ($q) => $q->whereNotNull('invoice_id')->orWhereNotNull('payout_invoice_id'))
            ->with(['offer.brand', 'offer.model', 'offer.media', 'invoice.claims', 'payoutInvoice'])->latest('stage_at')->get()
            ->each(fn (GarageCar $c) => $c->setAttribute('money', DealMoney::garage($c, $this->manager)))
            ->filter(fn (GarageCar $c) => $c->money)->values();

        return $preset === 'all' ? $this->cars : $this->cars->filter(fn (GarageCar $c) => $c->money->preset === $preset)->values();
    }

    /**
     * Строки «Денег»: сделки и машины гаража одним списком — требующие действия первыми, дальше свежие.
     *
     * @return Collection<int, Deal|GarageCar>
     */
    public function rows(string $preset = 'all'): Collection
    {
        return $this->deals($preset)->concat($this->cars($preset))
            ->sortBy([fn ($a, $b) => ($b->money->needsAction() <=> $a->money->needsAction()) ?: (($b->created_at ?? $b->stage_at) <=> ($a->created_at ?? $a->stage_at))])->values();
    }

    /** Числа для пилюль пресетов, нули не отдаются. */
    public function counts(): array
    {
        $all = $this->deals()->concat($this->cars());

        return array_filter(['all' => $all->count()] + $all->countBy(fn ($r) => $r->money->preset)->all());
    }

    /**
     * Положение: сколько платить нам и до какого числа, сколько причитается ему и до какого, есть ли просрочка.
     *
     * @return array{pay: float, pay_due: ?CarbonInterface, overdue: float, claimed: float, payout: float, payout_due: ?CarbonInterface, paid_out: float}
     */
    public function position(): array
    {
        $toPay = $this->toPay();
        $fees = $this->manager->party_id ? Invoice::where('party_id', $this->manager->party_id)->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', '!=', InvoiceState::Void)->get() : collect();
        $due = $fees->where('state', InvoiceState::Issued);

        return [
            'pay' => round($toPay->sum(fn (Invoice $i) => $i->remaining()), 2),
            'pay_due' => $toPay->min('due_at'),
            'overdue' => round($toPay->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2),
            'claimed' => round($toPay->sum(fn (Invoice $i) => $i->claimed()), 2),
            'payout' => round($due->sum(fn (Invoice $i) => $i->remaining()), 2),
            'payout_due' => $due->min('due_at'),
            'paid_out' => round($fees->sum('paid'), 2),
        ];
    }

    /**
     * Лента операций: счёт выставлен, сообщили об оплате, оплата принята или не поступила, удержано,
     * вознаграждение к выплате, выплачено. Каждая — дата, слова, сумма, куда вести.
     *
     * @return Collection<int, array{at: CarbonInterface, title: string, amount: float, kind: string, href: string, offer: ?string, deal: ?int}>
     */
    public function history(?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $rows = collect();
        $invoices = $this->invoices();
        // Гаражные счета и выплаты — без сделки: ведут на машину в гараже, а не в пустой расчёт сделки.
        $cars = GarageCar::with(['offer.brand', 'offer.model'])->where(fn ($q) => $q->whereIn('invoice_id', $invoices->whereNull('deal_id')->pluck('id'))
            ->orWhereIn('payout_invoice_id', $invoices->whereNull('deal_id')->pluck('id')))->get();
        foreach ($invoices as $i) {
            $car = $i->deal_id ? null : $cars->first(fn ($c) => $c->invoice_id === $i->id || $c->payout_invoice_id === $i->id);
            $offer = $i->deal?->offer?->titleWithYear() ?? $car?->offer->titleWithYear();
            $href = $car ? $car->url() : '/account/money/deals/'.$i->deal_id;
            $rows->push(['at' => $i->issued_at->copy()->setTimeFrom($i->created_at), 'title' => $i->isAgentFee() ? 'Вознаграждение к выплате' : 'Счёт '.$i->label().' выставлен', 'amount' => $i->total, 'kind' => $i->isAgentFee() ? 'fee' : 'invoice', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id]);
            foreach ($i->allPayments as $p) {
                $rows->push(match (true) {
                    $p->state === PaymentState::Claimed => ['at' => $p->created_at, 'title' => 'Сообщили об оплате', 'amount' => $p->amount, 'kind' => 'claim', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    $p->state === PaymentState::Rejected => ['at' => $p->decided_at ?? $p->updated_at, 'title' => 'Оплата не поступила', 'amount' => $p->amount, 'kind' => 'rejected', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    $p->source === PaymentSource::Offset => ['at' => $p->created_at, 'title' => 'Удержано агентское вознаграждение', 'amount' => $p->amount, 'kind' => 'offset', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    $i->isOwed() => ['at' => $p->paid_at->copy()->setTimeFrom($p->decided_at ?? $p->created_at), 'title' => 'Выплачено', 'amount' => $p->amount, 'kind' => 'payout', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    default => ['at' => $p->paid_at->copy()->setTimeFrom($p->decided_at ?? $p->created_at), 'title' => 'Оплата принята', 'amount' => $p->amount, 'kind' => 'paid', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
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
