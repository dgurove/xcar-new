<?php

namespace App\Billing;

use App\Garage\Car as GarageCar;
use App\Logistics\Transport;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Surface;
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

    /** staff — смотрит сотрудник: фразы третьим лицом («должен нам», «должны ему»), а не «Оплатите». */
    public function __construct(private User $manager, private bool $staff = false) {}

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
            $this->all->each(fn (Deal $d) => $d->setAttribute('money', DealMoney::of($d, $this->staff)));
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
            ->each(fn (GarageCar $c) => $c->setAttribute('money', DealMoney::garage($c, $this->manager, $this->staff)))
            ->filter(fn (GarageCar $c) => $c->money)->values();

        return $preset === 'all' ? $this->cars : $this->cars->filter(fn (GarageCar $c) => $c->money->preset === $preset)->values();
    }

    /** Перевозки, где он партнёр и мы ему должны (логистика, 10.10.2026) — строками рядом со сделками. */
    private ?Collection $transports = null;

    /** @return Collection<int, Transport> с `money` */
    public function transports(string $preset = 'all'): Collection
    {
        $this->transports ??= $this->manager->party_id
            ? Transport::whereHas('invoice', fn ($i) => $i->where('party_id', $this->manager->party_id)->where('direction', 'owed')->where('kind', ChargeKind::Tow)->where('state', '!=', InvoiceState::Void))
                ->with(['invoice', 'offer.brand', 'offer.model', 'offer.media', 'vehicle.brand', 'vehicle.model', 'vehicle.media'])->latest('id')->get()
                ->each(fn (Transport $t) => $t->setAttribute('money', DealMoney::transport($t->invoice, $this->staff)))
            : collect();

        return $preset === 'all' ? $this->transports : $this->transports->filter(fn (Transport $t) => $t->money->preset === $preset)->values();
    }

    /**
     * Строки «Денег»: сделки, машины гаража и перевозки партнёра одним списком — требующие действия первыми, дальше свежие.
     *
     * @return Collection<int, Deal|GarageCar|Transport>
     */
    public function rows(string $preset = 'all'): Collection
    {
        return $this->deals($preset)->concat($this->cars($preset))->concat($this->transports($preset))
            ->sortBy([fn ($a, $b) => ($b->money->needsAction() <=> $a->money->needsAction()) ?: (($b->created_at ?? $b->stage_at) <=> ($a->created_at ?? $a->stage_at))])->values();
    }

    /** Числа для пилюль пресетов, нули не отдаются. */
    public function counts(): array
    {
        $all = $this->deals()->concat($this->cars())->concat($this->transports());

        return array_filter(['all' => $all->count()] + $all->countBy(fn ($r) => $r->money->preset)->all());
    }

    /** Суммы пилюль «Оплатить» и «Ждут выплаты» — ровно то, что в их списке. */
    public function sums(): array
    {
        $rows = $this->rows();

        return ['pay' => round($rows->where('money.preset', 'pay')->sum('money.toUs'), 2), 'payout' => round($rows->where('money.preset', 'payout')->sum('money.toHim'), 2)];
    }

    /**
     * Положение: сколько он должен нам, сколько мы ему, есть ли просрочка. Сумма строк `rows()` —
     * число над списком всегда сходится со списком (06.10.2026: «Оплатить 440 500», а строк на 81 000 — счёт гаража
     * считался, а строкой не показывался).
     *
     * @return array{pay: float, pay_due: ?CarbonInterface, overdue: float, claimed: float, payout: float, paid_out: float}
     */
    public function position(): array
    {
        $rows = $this->rows();
        $fees = $this->manager->party_id ? Invoice::where('party_id', $this->manager->party_id)->where('direction', 'owed')->whereIn('kind', [ChargeKind::AgentFee, ChargeKind::Tow])->where('state', '!=', InvoiceState::Void)->get() : collect();
        $sum = fn (string $key) => round($rows->sum(fn ($r) => $r->money->{$key}), 2);

        return [
            'pay' => $sum('toUs'),
            'pay_due' => $rows->filter(fn ($r) => $r->money->toUs > 0)->map(fn ($r) => $r->money->due)->filter()->min(),
            'overdue' => $sum('overdue'),
            'claimed' => $sum('claimed'),
            'payout' => $sum('toHim'),
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
            // Перевозка партнёра (логистика) — без сделки: ведёт в его перевозки.
            $transport = $i->isTransport() ? Transport::firstWhere('invoice_id', $i->id) : null;
            $offer ??= $transport?->title();
            $href = match (true) {
                (bool) $car => $car->url(),
                (bool) $transport => Surface::Logistics->url('/transports/'.$transport->id),
                default => '/account/money/deals/'.$i->deal_id,
            };
            $rows->push(['at' => $i->issued_at->copy()->setTimeFrom($i->created_at), 'title' => match (true) {
                $i->isAgentFee() => 'Вознаграждение к выплате', $i->isTransport() => 'Перевозка к выплате', default => 'Счёт '.$i->label().' выставлен',
            }, 'amount' => $i->total, 'kind' => $i->isAgentFee() || $i->isTransport() ? 'fee' : 'invoice', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id]);
            foreach ($i->allPayments as $p) {
                $rows->push(match (true) {
                    $p->state === PaymentState::Claimed => ['at' => $p->created_at, 'title' => 'Сообщили об оплате', 'amount' => $p->amount, 'kind' => 'claim', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    $p->state === PaymentState::Rejected => ['at' => $p->decided_at ?? $p->updated_at, 'title' => 'Оплата не поступила', 'amount' => $p->amount, 'kind' => 'rejected', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
                    $p->source === PaymentSource::Credit => ['at' => $p->created_at, 'title' => 'Засчитана оплата по ссылке', 'amount' => $p->amount, 'kind' => 'paid', 'href' => $href, 'offer' => $offer, 'deal' => $i->deal_id],
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
