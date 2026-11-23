<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Offers\Offer;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Строка «Оплат» у человека (22.11.2026, владелец: «отображались ВСЕ сделки», «если по тачке несколько поступлений — не две
 * строки, а сумма»): сделка со всеми своими счетами раздела или ручная ссылка без сделки. Деньги строки — сумма счетов:
 * открытая — остаток, оплаченная — итог. Группа — что с ней делать: `unsent` (есть неотправленный счёт или счёта ещё нет),
 * `issued` (ждём денег), `paid`. Ключ окна: номер машины, у ручной ссылки — `s{счёт}` (`MoneyController::person`).
 * С разделом (`$tab`, только «Оплаты» сотрудника, 23.11.2026) — деньги строки по доле раздела (`InvoiceSplit`): счёт ПРАЙМ / Р.О.С.
 * машиной в «ДКП» и подбором в «Комиссиях», ручная ссылка — тем, что не легло в счета сделки.
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
        public readonly ?string $tab = null,
    ) {}

    /** [сумма, оплачено] счёта в строке: без раздела — целиком (`InvoiceSplit`). @var array<int, array{0: float, 1: float}> */
    private array $parts = [];

    /** `$tab` — делить по разделу («Оплаты» сотрудника); без него — как было (экран менеджера, «Оплата за подбор»). */
    public static function deal(Deal $deal, Collection $invoices, ?string $gap = null, ?string $tab = null): self
    {
        $row = new self($deal, null, $invoices->values(), MoneyStage::label($deal->getAttribute('money_stage')), $gap, $tab);
        if ($tab) {
            foreach ($row->invoices as $i) {
                $row->parts[$i->id] = InvoiceSplit::part($i, $deal, $tab);
            }
        }

        return $row;
    }

    /** `$car` — доля машины ссылки (`InvoiceSplit::carShares`), с разделом. */
    public static function service(Invoice $invoice, ?string $tab = null, float $car = 0.0): self
    {
        // Засчитанная в сделку — что с этим зачётом: ждёт денег, сделку отменили, оплачено сверх неё.
        $deal = $invoice->creditDeal;
        $stage = match (true) {
            ! $deal => 'Без сделки',
            $deal->state === \App\Offers\DealState::Cancelled => 'Сделка отменена',
            $invoice->state === InvoiceState::Issued => 'В зачёт сделки',
            // Оплачена, а в счета сделки ничего не легло — их ещё нет; легло частью — остальное сверх.
            (float) $invoice->getAttribute('credited') <= 0 => 'Ждёт счёта сделки',
            default => 'Сверх сделки',
        };

        $row = new self(null, $invoice, collect([$invoice]), $stage, null, $tab);
        if ($tab) {
            $row->parts[$invoice->id] = InvoiceSplit::servicePart($invoice, $tab, $car);
        }

        return $row;
    }

    /** У строки есть деньги в своём разделе (доля ручной ссылки бывает нулевой — тогда строки нет). */
    public function hasPart(): bool
    {
        return $this->invoices->contains(fn (Invoice $i) => $this->part($i)[0] > 0.005);
    }

    /** @return array{0: float, 1: float} */
    private function part(Invoice $i): array
    {
        return $this->parts[$i->id] ?? [(float) $i->total, (float) $i->paid];
    }

    /** Подбор внутри счёта за ТС (раздел «Комиссии» у ПРАЙМ / Р.О.С.): строка говорит «в счёте за ТС». */
    public function inCarInvoice(): bool
    {
        return $this->tab === 'fees' && $this->deal !== null && $this->invoices->isNotEmpty()
            && $this->invoices->every(fn (Invoice $i) => InvoiceSplit::splits($i, $this->deal));
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
            default => $this->service->creditDeal?->buyer_id ?? $this->service->manager_id,
        };
    }

    /** @return Collection<int, Invoice> */
    public function open(): Collection
    {
        // Доля раздела закрыта раньше счёта: машина ПРАЙМ оплачена, а подбор ещё нет — в «ДКП» строка оплачена.
        return $this->invoices->filter(fn (Invoice $i) => $i->state === InvoiceState::Issued
            && (! isset($this->parts[$i->id]) || $this->parts[$i->id][0] - $this->parts[$i->id][1] > 0.005))->values();
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
        return round($this->isPaid() ? $this->invoices->sum(fn (Invoice $i) => $this->part($i)[0]) : $this->open()->sum(fn (Invoice $i) => $this->left($i)), 2);
    }

    /** Сколько осталось по счёту в доле строки. */
    private function left(Invoice $i): float
    {
        return isset($this->parts[$i->id]) ? max(0.0, $this->parts[$i->id][0] - $this->parts[$i->id][1]) : $i->remaining();
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
        return round($this->open()->filter(fn (Invoice $i) => $i->isOverdue())->sum(fn (Invoice $i) => $this->left($i)), 2);
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
        // Машина ПРАЙМ оплачена, а счёт ещё нет — день последней оплаты.
        return $this->invoices->map(fn (Invoice $i) => $i->paid_at ?? ($this->left($i) <= 0.005 ? $i->payments->max('paid_at') : null))->filter()->max();
    }
}
