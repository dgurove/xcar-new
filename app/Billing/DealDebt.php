<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Что сделка должна нам — строкой «Сделок» и «Предложений» CRM (27.10.2026, владелец: «нам надо понимать, кто сколько
 * должен и какой срок оплаты, а то пишется „Оплата“ и непонятно, просрочил он или нет»). Счета — выставленные нам
 * (`DealMoney::payable`: без выплат менеджеру и счёта вендору за вознаграждение) из уже загруженных `invoices.claims` —
 * запросов на строку нет. SQL-зеркала (`OPEN_SQL`, `bucketSql`, `dueSql`, `totals`) — чип «Оплата», сортировка и итог.
 */
final class DealDebt
{
    /** Открытый счёт сделки нам: выставлен, не оплачен целиком, не выплата и не вознаграждение от вендора. */
    public const OPEN_SQL = "billing_invoices.deal_id = deals.id and billing_invoices.direction = 'issued' and billing_invoices.state = 'issued'
        and billing_invoices.kind <> 'reward' and billing_invoices.total > billing_invoices.paid";

    /** Срок «скоро» — как жёлтый светофор счёта (`Invoice::light`). */
    public const SOON_DAYS = 3;

    public const BUCKETS = ['late' => 'Просрочена', 'soon' => 'Срок скоро', 'wait' => 'Ждём оплату', 'paid' => 'Оплачено', 'none' => 'Счёта нет'];

    private function __construct(
        public readonly float $owed,
        public readonly float $overdue,
        public readonly ?CarbonInterface $due,   // ближний срок открытого счёта
        public readonly int $lateDays,
        public readonly bool $claimed,           // сообщил об оплате, ждёт нашей проверки
        public readonly ?string $later,          // срока ещё нет: «после договора»
    ) {}

    /** Счетов нам по сделке нет — null; все оплачены — `owed` 0. */
    public static function of(Deal $deal): ?self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims')->get();
        if (! $invoices->contains(fn (Invoice $i) => ! $i->isOwed() && $i->kind !== ChargeKind::Reward)) {
            return null;
        }
        $open = DealMoney::payable($invoices)->filter(fn (Invoice $i) => $i->remaining() > 0)->values();
        if ($open->isEmpty()) {
            return new self(0, 0, null, 0, false, null);
        }
        $first = $open->first()->setRelation('deal', $deal);
        $late = $open->filter->isOverdue();

        return new self(
            round($open->sum(fn (Invoice $i) => $i->remaining()), 2),
            round($late->sum(fn (Invoice $i) => $i->remaining()), 2),
            $first->due_at,
            $late->isNotEmpty() ? $late->first()->overdueDays() : 0,
            $open->contains(fn (Invoice $i) => $i->claimed() > 0),
            $first->due_at ? null : $first->laterLabel(),
        );
    }

    public function paid(): bool
    {
        return $this->owed <= 0;
    }

    public function late(): bool
    {
        return $this->overdue > 0;
    }

    /** Срок словом: «просрочено 5 д», «до 8 окт», «срок сегодня», «после договора», «сообщил об оплате», «оплачено». */
    public function word(): string
    {
        return match (true) {
            $this->paid() => 'оплачено',
            $this->claimed => 'сообщил об оплате',
            $this->late() => "просрочено {$this->lateDays}\u{00A0}д",
            $this->due?->isToday() => 'срок сегодня',
            $this->due !== null => 'до '.$this->due->translatedFormat('j M'),
            default => (string) $this->later,
        };
    }

    /** Красным — просрочено, оранжевым — срок в три дня, зелёным — оплачено, серым — ждём проверки. */
    public function tone(): string
    {
        return match (true) {
            $this->paid() => 'text-accent-text',
            $this->claimed => 'text-ink-muted',
            $this->late() => 'text-danger',
            $this->due?->lte(now()->addDays(self::SOON_DAYS)) => 'text-urgent',
            default => '',
        };
    }

    public function amount(): string
    {
        return Money::rub($this->owed);
    }

    /** Чип «Оплата» — ключ корзины сделки одним выражением над `deals`. */
    public static function bucketSql(): string
    {
        $open = 'select 1 from billing_invoices where '.self::OPEN_SQL;
        $soon = self::SOON_DAYS;

        return "(case when exists ({$open} and billing_invoices.due_at < current_date) then 'late'
            when exists ({$open} and billing_invoices.due_at <= current_date + {$soon}) then 'soon'
            when exists ({$open}) then 'wait'
            when exists (select 1 from billing_invoices where billing_invoices.deal_id = deals.id and billing_invoices.direction = 'issued'
                and billing_invoices.state = 'paid' and billing_invoices.kind <> 'reward') then 'paid'
            else 'none' end)";
    }

    /** Ближний срок открытого счёта — сортировка «Срок оплаты». */
    public static function dueSql(): string
    {
        return '(select min(billing_invoices.due_at) from billing_invoices where '.self::OPEN_SQL.')';
    }

    /** Должны и просрочено по сделкам выборки — итог над таблицей. @return array{owed: float, overdue: float} */
    public static function totals(Builder $deals): array
    {
        $row = Invoice::query()
            ->whereIn('deal_id', $deals->clone()->reorder()->select('deals.id'))
            ->where('direction', 'issued')->where('state', InvoiceState::Issued)->where('kind', '<>', ChargeKind::Reward)
            ->selectRaw('coalesce(sum(greatest(total - paid, 0)), 0) as owed,
                coalesce(sum(case when due_at < current_date then greatest(total - paid, 0) else 0 end), 0) as overdue')
            ->toBase()->first();

        return ['owed' => (float) $row->owed, 'overdue' => (float) $row->overdue];
    }
}
