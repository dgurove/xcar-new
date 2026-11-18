<?php

namespace App\Billing;

use App\Garage\Car as GarageCar;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Ago;
use App\Support\Money;
use App\Users\User;
use Carbon\CarbonInterface;

/**
 * Сделка глазами денег одной строкой: что сейчас важно (фраза), какое число
 * показать справа и каким тоном, в какой пресет она попадает. Одна логика на
 * кабинет менеджера и CRM; закупочной и «нам» тут нет.
 */
final class DealMoney
{
    public const PRESETS = ['all' => 'Все', 'pay' => 'Оплатить', 'payout' => 'Ждут выплаты', 'closed' => 'Закрытые'];

    /**
     * Направление сказано словом (06.10.2026, владелец: «почему мы должны Бородину 1 690 000» — он был должен нам, а
     * число стояло без слов). Менеджеру — «К оплате», сотруднику (`staff`) — третьим лицом: «должен нам». Деньги строки —
     * `toUs` (его счета, что ещё не оплачены), `toHim` (наша выплата за перевозку — логистам), `overdue`, `claimed`: итоги
     * «Денег» и «Расчётов с менеджерами» — сумма строк, а не свой запрос. Вознаграждений менеджерам мы не выплачиваем
     * (18.11.2026): у сделки оно только числом.
     */
    private function __construct(
        public readonly string $phrase,
        public readonly ?float $amount,
        public readonly string $tone,      // urgent | accent | profit | plain | muted
        public readonly string $preset,    // pay | payout | closed | open
        public readonly string $caption = '', // подпись под числом
        public readonly float $toUs = 0,
        public readonly float $toHim = 0,
        public readonly float $overdue = 0,
        public readonly float $claimed = 0,
        public readonly ?CarbonInterface $due = null, // ближний срок его оплаты нам
    ) {}

    /** Счета, что платит менеджер или его покупатель (без наших выплат). */
    public static function payable($invoices)
    {
        return $invoices->filter(fn (Invoice $i) => ! $i->isOwed() && $i->state === InvoiceState::Issued)
            ->sortBy(fn (Invoice $i) => [$i->due_at ? 0 : 1, $i->due_at?->timestamp ?? 0, $i->id])->values();
    }

    public static function of(Deal $deal, bool $staff = false): self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims')->get();
        // Менеджеру — только отправленные ему счета (16.11.2026): вставший, но не отправленный — «Счёт готовится»
        // (`Deal::invoiceNotYet` ниже).
        if (! $staff) {
            $invoices = $invoices->filter(fn (Invoice $i) => $i->isShownToManager())->values();
        }
        $issued = $invoices->reject(fn (Invoice $i) => $i->isOwed());
        $open = self::payable($invoices);
        // Гаражная сделка цены подтверждения не знает: её счёт (Каркаде, платит менеджер) — от закупочной.
        $label = $deal->isGarage() ? 'В гараж' : 'Цена подтверждения';
        // Покупатель с гаражом закупочной не видит нигде (20.10.2026): его число — его цена.
        $base = $deal->forGarageBuyer() ? (float) $deal->garageCar?->buyer_price : (float) $deal->base();
        if ($deal->forGarageBuyer()) {
            $label = 'Цена';
        }
        $date = fn ($at) => Ago::day($at);

        if ($deal->state === DealState::Cancelled) {
            return new self('Сделка отменена', null, 'muted', 'closed');
        }
        // Подбор до шага оплаты срока не имеет (`SyncDealInvoices::dueFor`): он не должник, пока не продал (06.10.2026).
        if ($open->isNotEmpty() && $open->every(fn (Invoice $i) => ! $i->due_at && $i->claimed() == 0)) {
            $later = $open->first()->laterLabel(true);
            $what = $open->contains(fn (Invoice $i) => $i->kind === ChargeKind::Selection) ? 'подбор ' : 'счёт ';

            return new self(($staff ? 'Оплатит ' : 'Оплатите ').$what.$later,
                round($open->sum(fn (Invoice $i) => $i->remaining()), 2), 'plain', 'open', $staff ? 'будет должен' : 'К оплате позже');
        }
        if ($open->isNotEmpty()) {
            // Число — остаток всех его счетов по сделке (у гаражной ПРАЙМ их два: машина и доля), фраза — по ближнему сроку.
            $first = $open->first();
            $left = round($open->sum(fn (Invoice $i) => $i->remaining()), 2);
            $claimed = round($open->sum(fn (Invoice $i) => $i->claimed()), 2);
            $late = round($open->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2);
            $caption = $staff ? 'должен нам' : 'К оплате';
            $money = ['toUs' => $left, 'overdue' => $late, 'claimed' => $claimed, 'due' => $first->due_at];
            if ($claimed > 0) {
                return new self($staff ? 'Сообщил об оплате' : 'Оплата ждёт подтверждения', $left, 'muted', 'pay', $caption, ...$money);
            }
            if ($late > 0) {
                return new self($first->overdueLabel(true), $left, 'urgent', 'pay', $caption, ...$money);
            }
            $byBuyer = $deal->isPrime() && $deal->buyer && $first->party_id !== $deal->buyer->party_id;
            $phrase = match (true) {
                $first->paidMoney() > 0 => 'Оплачено '.Money::rub($first->paidMoney()).', остаток до ',
                $byBuyer => $staff ? 'Платит покупатель, до ' : 'Покупатель оплачивает до ',
                $staff => 'Оплатит до ',
                $deal->isGarageManager() && $first->kind === ChargeKind::Selection => 'Оплатите долю до ',
                $first->kind === ChargeKind::Selection => 'Оплатите подбор до ',
                default => 'Оплатите до ',
            };

            return new self($phrase.$date($first->due_at), $left, $first->light() === 'urgent' ? 'urgent' : 'plain', 'pay', $caption, ...$money);
        }
        // Счёт встал, но менеджеру не отправлен — «Счёт готовится» серым, а не оранжевое «Укажите покупателя».
        if ($issued->isEmpty() && $deal->isActive() && ($notYet = $deal->invoiceNotYet()) !== null) {
            return new self('Счёт '.$notYet, $base, 'plain', 'open', $staff ? mb_strtolower($label) : $label);
        }
        if ($issued->isEmpty() && $deal->isActive() && in_array($gap = $deal->invoiceGap(), ['buyer', 'passport'], true)) {
            return $staff
                ? new self($gap === 'passport' ? 'Не хватает данных покупателя' : 'Ждём покупателя', $base, 'plain', 'open', mb_strtolower($label))
                : new self($gap === 'passport' ? 'Заполнить данные покупателя' : 'Указать покупателя', $base, 'urgent', 'open', $label);
        }
        if ($issued->isEmpty()) {
            return $deal->state === DealState::Done
                ? new self('Сделка закрыта', $base, 'muted', 'closed', $label)
                : new self('Счёт ещё не выставлен', $base, 'muted', 'open', $label);
        }

        // Счета оплачены: у менеджера — его вознаграждение числом (зелёным — его деньги), без слов о том, как он его получает.
        return $deal->commission
            ? new self('Вознаграждение', (float) $deal->commission, 'profit', 'closed')
            : new self('Счёт оплачен', $base, 'muted', 'closed', $label);
    }

    /**
     * Расчёт машины в гараже строкой «Денег»: счёт «отдать нам» — только его; счёт его покупателю менеджеру не платить.
     * Нечего показать — null. Старые выплаты (`owed`, до 18.11.2026) — не его счёт, их тут нет.
     */
    public static function garage(GarageCar $car, User $manager, bool $staff = false): ?self
    {
        $mine = fn (?Invoice $i) => $i && $i->state !== InvoiceState::Void && $manager->party_id && $i->party_id === $manager->party_id;
        $invoice = $mine($car->invoice) ? $car->invoice : null;
        $invoice = $invoice?->isOwed() ? null : $invoice;
        $date = fn ($at) => Ago::day($at);
        if ($invoice && $invoice->state === InvoiceState::Issued) {
            $left = $invoice->remaining();
            $caption = $staff ? 'должен нам' : 'Отдать нам';
            $money = ['toUs' => $left, 'overdue' => $invoice->isOverdue() ? $left : 0, 'claimed' => $invoice->claimed(), 'due' => $invoice->due_at];

            return match (true) {
                $invoice->claimed() > 0 => new self($staff ? 'Сообщил об оплате' : 'Оплата ждёт подтверждения', $left, 'muted', 'pay', $caption, ...$money),
                $invoice->isOverdue() => new self($invoice->overdueLabel(true), $left, 'urgent', 'pay', $caption, ...$money),
                default => new self(($staff ? 'Оплатит до ' : 'Оплатите до ').$date($invoice->due_at), $left, $invoice->light() === 'urgent' ? 'urgent' : 'plain', 'pay', $caption, ...$money),
            };
        }
        return $invoice ? new self('Оплачено', (float) $invoice->total, 'muted', 'closed', $staff ? 'оплатил' : 'Отдали нам') : null;
    }

    /** Перевозка партнёра (логистика, 10.10.2026) строкой «Денег»: наш долг ему за эвакуацию. */
    public static function transport(Invoice $invoice, bool $staff = false): self
    {
        return self::payout($invoice, $staff);
    }

    /** Наша выплата за перевозку (`owed`): к выплате до срока — или выплачено. */
    private static function payout(Invoice $payout, bool $staff): self
    {
        $date = fn ($at) => Ago::day($at);

        return $payout->state === InvoiceState::Issued
            ? new self(($staff ? 'Выплатить до ' : 'К выплате до ').$date($payout->due_at), $payout->remaining(), 'accent', 'payout', $staff ? 'должны ему' : 'Вам к выплате', toHim: $payout->remaining())
            : new self(($staff ? 'Выплатили ' : 'Выплачено ').$date($payout->paid_at), (float) $payout->total, 'muted', 'closed', $staff ? 'выплатили' : 'Вам выплачено');
    }

    public function needsAction(): bool
    {
        // «Укажите покупателя» — тоже его ход, наверх вместе с «Оплатить».
        return ($this->preset === 'pay' && $this->tone !== 'muted') || ($this->preset === 'open' && $this->tone === 'urgent');
    }

    /** Классы числа справа по тону. */
    public function amountClass(): string
    {
        return match ($this->tone) {
            'urgent' => 'font-semibold text-urgent',
            'accent' => 'font-semibold text-accent-text',
            'profit' => 'font-semibold text-open',
            'muted' => 'text-ink-muted',
            default => 'font-semibold',
        };
    }

    public function phraseClass(): string
    {
        return match ($this->tone) {
            'urgent' => 'text-urgent',
            'accent' => 'text-accent-text',
            'profit' => 'text-open',
            default => 'text-ink-muted',
        };
    }
}
