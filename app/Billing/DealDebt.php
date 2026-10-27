<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Support\Money;
use App\Workflow\Position;
use App\Workflow\WaitsFor;
use Carbon\CarbonInterface;

/**
 * Что сделка должна нам — «Сделки» CRM (27.10.2026, владелец: «нам надо понимать, кто сколько должен и какой срок оплаты»;
 * в тот же день: «я всё ещё не понимаю, кто сколько должен заплатить и когда» — на «Оплате» стояли 55 сделок, а счёт был у
 * двух). Долг — открытые наши счета (`DealMoney::payable`: без выплат менеджеру и счёта вендору за вознаграждение) или, пока
 * счёта нет, ожидаемый платёж: сделка на шаге оплаты, ход за менеджером или за нами, сумма — цена сделки, у гаражной
 * Совкомбанка без цены — закупочная (владелец: «закупочную, сразу»), срок — 5 дней от «Забрал» (`Deal::payDue`). Из уже
 * загруженных `invoices.claims` / `invoices.party` — без запросов на строку. SQL-зеркало — `OWES_SQL` (число вкладки «Оплата»).
 */
final class DealDebt
{
    /** Открытый счёт сделки нам: выставлен, не оплачен целиком, не выплата и не вознаграждение от вендора. */
    public const OPEN_SQL = "billing_invoices.deal_id = deals.id and billing_invoices.direction = 'issued' and billing_invoices.state = 'issued'
        and billing_invoices.kind <> 'reward' and billing_invoices.total > billing_invoices.paid";

    /** Сделка должна нам: открытый счёт или ожидаемый платёж (то же правило, что `of`). */
    public const OWES_SQL = "(exists (select 1 from billing_invoices where ".self::OPEN_SQL.")
        or (deals.state = 'active' and coalesce(deals.amount, deals.cost) > 0
            and not exists (select 1 from billing_invoices i where i.deal_id = deals.id and i.direction = 'issued' and i.state <> 'void' and i.kind <> 'reward')
            and exists (select 1 from offer_positions p where p.offer_id = deals.offer_id and p.track = 'sale' and p.waits_for in ('us', 'manager')
                and exists (select 1 from workflow_exits x where x.stage_id = p.stage_id and x.actor = 'manager' and lower(x.label) like 'платёжное поручение%'))))";

    /** Срок «скоро» — как жёлтый светофор счёта (`Invoice::light`). */
    public const SOON_DAYS = 3;

    private function __construct(
        public readonly float $owed,
        public readonly float $overdue,
        public readonly ?CarbonInterface $due,   // ближний срок (счёта или ожидаемого платежа)
        public readonly bool $claimed,           // сообщил об оплате, ждёт нашей проверки
        public readonly ?string $later,          // срока ещё нет: «после договора»
        public readonly string $status = '',     // что с оплатой: «счёта нет: не указал покупателя», «счёт от 24 окт»
        public readonly bool $ours = false,      // мешаем мы: счёт выставить, долю вписать
    ) {}

    /** Нам ничего не должны и не будут — null; всё оплачено — `owed` 0. */
    public static function of(Deal $deal): ?self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims', 'party')->get();
        $open = DealMoney::payable($invoices)->filter(fn (Invoice $i) => $i->remaining() > 0)->values();
        if ($open->isNotEmpty()) {
            $first = $open->first()->setRelation('deal', $deal);
            $owed = round($open->sum(fn (Invoice $i) => $i->remaining()), 2);
            $claimed = $open->contains(fn (Invoice $i) => $i->claimed() > 0);
            $paid = round($open->sum(fn (Invoice $i) => $i->paidMoney()), 2);
            $byBuyer = $deal->isPrime() && $deal->buyer && $first->party_id !== $deal->buyer->party_id;
            $status = match (true) {
                $claimed => 'сообщил об оплате — проверить',
                $paid > 0 => 'оплачено '.Money::nums($paid).' из '.Money::nums($owed + $paid),
                $byBuyer && $first->party => 'платит покупатель '.$first->party->name,
                default => 'счёт от '.$first->issued_at?->translatedFormat('j M'),
            };

            return new self($owed, round($open->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2), $first->due_at,
                $claimed, $first->due_at ? null : $first->laterLabel(), $status, $claimed);
        }
        if ($invoices->contains(fn (Invoice $i) => ! $i->isOwed() && $i->kind !== ChargeKind::Reward)) {
            return new self(0, 0, null, false, null, 'оплачено');
        }

        return self::expected($deal);
    }

    /** Счёта нет, а платёж ждём: шаг оплаты, ход менеджера («Укажите покупателя») или наш («Выставить счёт»). */
    private static function expected(Deal $deal): ?self
    {
        $position = $deal->isActive() ? $deal->offer?->position() : null;
        $amount = (float) $deal->base();
        if (! $position || $amount <= 0 || ! in_array($position->waits_for, [WaitsFor::Us, WaitsFor::Manager], true) || ! $position->stage->isPayStep()) {
            return null;
        }
        $gap = $deal->invoiceGap();
        $due = $deal->payDue();
        $late = $due && $due->copy()->startOfDay()->lt(today());
        [$status, $ours] = match ($gap) {
            'buyer' => ['счёта нет: не указал покупателя', false],
            'passport' => ['счёта нет: нет паспорта покупателя', false],
            'price' => ['гараж: закупочная, счёта нет', false],
            default => ['счёта нет: '.mb_lcfirst(Position::gapAction(in_array($gap, ['share', 'commission', 'ros_requisites'], true) ? $gap : 'invoice', $deal)['button']), true],
        };

        return new self($amount, $late ? $amount : 0, $due, false, null, $status, $ours);
    }

    public function paid(): bool
    {
        return $this->owed <= 0;
    }

    public function late(): bool
    {
        return $this->overdue > 0;
    }

    public function lateDays(): int
    {
        return $this->due ? (int) $this->due->copy()->startOfDay()->diffInDays(today()) : 0;
    }

    /** Срок словом: «просрочено 5 д», «срок сегодня», «до 8 окт», «после договора», «без срока». */
    public function dueWord(): string
    {
        return match (true) {
            $this->late() => "просрочено {$this->lateDays()}\u{00A0}д",
            (bool) $this->due?->isToday() => 'срок сегодня',
            $this->due !== null => 'до '.$this->due->translatedFormat('j M'),
            default => $this->later ?? 'без срока',
        };
    }

    /** Красным — просрочено, оранжевым — срок в три дня. */
    public function dueTone(): string
    {
        return match (true) {
            $this->late() => 'text-danger',
            (bool) $this->due?->lte(now()->addDays(self::SOON_DAYS)) => 'text-urgent',
            default => 'text-ink-muted',
        };
    }

    /** Одним словом для столбца «Оплата»: срок, «сообщил об оплате» или «оплачено». */
    public function word(): string
    {
        return match (true) {
            $this->paid() => 'оплачено',
            $this->claimed => 'сообщил об оплате',
            default => $this->dueWord(),
        };
    }

    public function tone(): string
    {
        return match (true) {
            $this->paid() => 'text-accent-text',
            $this->claimed => 'text-ink-muted',
            default => $this->dueTone(),
        };
    }

    public function amount(): string
    {
        return Money::rub($this->owed);
    }
}
