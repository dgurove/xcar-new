<?php

namespace App\Http\Admin;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\MoneyStage;
use App\Offers\Deal;
use App\Offers\Offer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Строка «Оплат» у человека (22.11.2026, владелец: «отображались ВСЕ сделки», «если по тачке несколько поступлений — не две
 * строки, а сумма»): сделка со всеми своими счетами раздела или ручная ссылка без сделки. Деньги строки — сумма счетов:
 * открытая — остаток, оплаченная — итог. Группа — что с ней делать: `unsent` (есть неотправленный счёт или счёта ещё нет),
 * `issued` (ждём денег), `paid`. Ключ окна: номер машины, у ручной ссылки — `s{счёт}` (`MoneyController::person`).
 */
final class MoneyRow
{
    /** @param Collection<int, Invoice> $invoices счета раздела, без аннулированных */
    private function __construct(
        public readonly ?Deal $deal,
        public readonly ?Invoice $service,
        public readonly Collection $invoices,
        public readonly ?string $stage,
        public readonly ?string $gap = null,
    ) {}

    public static function deal(Deal $deal, Collection $invoices, ?string $gap = null): self
    {
        return new self($deal, null, $invoices->values(), MoneyStage::label($deal->getAttribute('money_stage')), $gap);
    }

    public static function service(Invoice $invoice): self
    {
        return new self(null, $invoice, collect([$invoice]), 'Без сделки');
    }

    /** Выплата (раздел «Выплаты»): строка — счёт, этапа нет. */
    public static function payout(Invoice $invoice): self
    {
        return new self(null, $invoice, collect([$invoice]), null);
    }

    /** Ключ окна: машина сделки или выплаты — её номер; ручная ссылка и счёт без машины — `s{счёт}` (их карточка). */
    public function key(): string
    {
        $offer = $this->offer();

        return $offer && ($this->deal || $this->service->isOwed()) ? (string) $offer->number : 's'.$this->service->id;
    }

    public function offer(): ?Offer
    {
        return $this->deal?->offer ?? $this->service?->carOffer();
    }

    /** Получатель выплаты — сотрудник по своей стороне (`MoneyController::RECIPIENT`), ставит список людей. */
    private ?int $recipient = null;

    public function setPerson(?int $id): self
    {
        $this->recipient = $id;

        return $this;
    }

    /** Чей: менеджер сделки, у ручной — назначенный, у выплаты — получатель. */
    public function personId(): ?int
    {
        return match (true) {
            $this->deal !== null => $this->deal->buyer_id,
            $this->service->isOwed() => $this->recipient,
            default => $this->service->manager_id,
        };
    }

    /** @return Collection<int, Invoice> */
    public function open(): Collection
    {
        return $this->invoices->filter(fn (Invoice $i) => $i->state === InvoiceState::Issued)->values();
    }

    /** Неотправленные менеджеру счета сделки — «Выставить». */
    public function unsent(): Collection
    {
        return $this->open()->reject(fn (Invoice $i) => $i->isShownToManager())->values();
    }

    /** Счёт, по которому действуют кнопки строки: неотправленный, иначе ближний по сроку. */
    public function first(): ?Invoice
    {
        return $this->unsent()->first() ?? $this->open()->sortBy(fn (Invoice $i) => [$i->due_at ? 0 : 1, $i->due_at?->timestamp ?? 0, $i->id])->first();
    }

    public function group(): string
    {
        return match (true) {
            $this->unsent()->isNotEmpty() || $this->invoices->isEmpty() => 'unsent',
            $this->open()->isNotEmpty() => 'issued',
            default => 'paid',
        };
    }

    public function isPaid(): bool
    {
        return $this->group() === 'paid';
    }

    /** Деньги строки: открытая — сколько осталось по всем её счетам, оплаченная — итог. */
    public function amount(): float
    {
        return round($this->isPaid() ? (float) $this->invoices->sum('total') : $this->open()->sum(fn (Invoice $i) => $i->remaining()), 2);
    }

    /** Сколько пишет, что заплатил (заявки менеджера). */
    public function claimed(): float
    {
        return round((float) $this->open()->sum(fn (Invoice $i) => $i->claims->sum('amount')), 2);
    }

    public function claim(): ?\App\Billing\Payment
    {
        return $this->open()->flatMap(fn (Invoice $i) => $i->claims)->first();
    }

    public function overdue(): float
    {
        return round($this->open()->filter(fn (Invoice $i) => $i->isOverdue())->sum(fn (Invoice $i) => $i->remaining()), 2);
    }

    public function isOverdue(): bool
    {
        return $this->overdue() > 0;
    }

    /** С какого момента ждём: первая отправка открытых; у оплаченной — день последней оплаты. */
    public function since(): ?CarbonInterface
    {
        if ($this->isPaid()) {
            return $this->paidAt();
        }
        $shown = $this->open()->filter(fn (Invoice $i) => $i->isShownToManager());

        return $shown->map(fn (Invoice $i) => $i->shown_at ?? $i->created_at)->filter()->min();
    }

    public function paidAt(): ?CarbonInterface
    {
        return $this->invoices->map(fn (Invoice $i) => $i->paid_at)->filter()->max();
    }
}
