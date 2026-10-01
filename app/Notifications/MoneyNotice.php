<?php

namespace App\Notifications;

use App\Billing\Acquiring\PayLink;
use App\Billing\Invoice;
use App\Billing\Payment;
use App\Garage\Car;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/**
 * Деньги по сделке: менеджеру — счёт выставлен, оплата принята или не поступила, выплачено;
 * сотрудникам — менеджер сообщил об оплате, вознаграждение к выплате. Менеджеру всегда, сотруднику — категория «Деньги».
 */
final class MoneyNotice extends Notice
{
    public function __construct(private string $title, private ?string $text, private string $path, private ?int $offerNumber = null, private bool $toStaff = false) {}

    /** @var array{title: string, lines: list<mixed>, button: string}|null */
    private ?array $telegram = null;

    private ?string $subject = null;

    /** Строка ленты, которую заменяет уведомление: деньги по сделке — строка сделки, сотрудникам — счёт. */
    private function about(Invoice $i): self
    {
        $this->subject = match (true) {
            $this->toStaff => '/work/money/invoices/'.$i->id,
            $i->deal_id !== null => '/deals/'.$i->deal_id,
            default => null,
        };

        return $this;
    }

    public function subject(): string
    {
        return $this->subject ?? parent::subject();
    }

    /** Менеджеру про его деньги — и в Telegram (вид задаёт фабрика); сотрудникам только лентой. */
    public function toTelegram(): ?array
    {
        return $this->toStaff ? null : $this->telegram;
    }

    /**
     * Telegram-вид: событие с машиной в заголовке («Оплата по Kia Rio, 2021 принята»), VIN, суть, #номер (Telegram\Text).
     * Счёт без машины — заголовок со счётом.
     */
    private function tg(Invoice $i, string $withCar, string $withoutCar, ?string $line, string $button = 'Открыть расчёт'): self
    {
        $offer = $i->deal?->offer ?? Car::ofInvoice($i)?->offer;
        $this->telegram = [
            'title' => $offer ? str_replace(':car', $offer->titleWithYear(), $withCar) : $withoutCar,
            'lines' => Text::lines($offer, $line),
            'button' => $button,
        ];

        return $this;
    }

    private static function rest(Invoice $i): string
    {
        return $i->remaining() > 0 ? 'остаток '.Money::rub($i->remaining()) : 'счёт оплачен';
    }

    public static function invoiceIssued(Invoice $i): self
    {
        return (new self('Счёт '.$i->label().' на '.Money::rub($i->remaining()).', оплатить до '.$i->due_at->translatedFormat('j M'), $i->deal?->offer?->titleWithYear(), '/account/money/deals/'.$i->deal_id, $i->deal?->offer?->number))
            ->tg($i, 'Счёт по :car', 'Счёт '.$i->label(), Money::rub($i->remaining()).', оплатить до '.$i->due_at->translatedFormat('j M'))->about($i);
    }

    /** Расчёт по машине в гараже: счёт к оплате или то, что мы должны ему, — ведёт на машину. */
    public static function garageInvoice(Invoice $i, Car $car): self
    {
        return (new self($i->isOwed() ? 'Вам к выплате '.Money::exact($i->remaining()).' за '.$car->offer->titleWithYear() : 'Счёт '.$i->label().' на '.Money::exact($i->remaining()).', оплатить до '.$i->due_at->translatedFormat('j M'),
            $car->offer->titleWithYear(), $car->url(), $car->offer->number))
            ->tg($i, $i->isOwed() ? 'Вам к выплате по :car' : 'Счёт по :car', 'Счёт '.$i->label(),
                Money::exact($i->remaining()).($i->isOwed() ? '' : ', оплатить до '.$i->due_at->translatedFormat('j M')), 'Открыть');
    }

    public static function paymentConfirmed(Payment $p): self
    {
        $i = $p->invoice;

        return (new self('Оплата '.Money::rub($p->amount).' по счёту '.$i->label().' принята', $i->remaining() > 0 ? 'Остаток '.Money::rub($i->remaining()) : 'Счёт оплачен', self::path($i), $i->deal?->offer?->number))
            ->tg($i, 'Оплата по :car принята', 'Оплата по счёту '.$i->label().' принята', Money::rub($p->amount).', '.self::rest($i))->about($i);
    }

    /** Куда вести менеджера по счёту: расчёт сделки на сайте или машина в гараже. */
    private static function path(Invoice $i): string
    {
        return Car::ofInvoice($i)?->url() ?? '/account/money/deals/'.$i->deal_id;
    }

    public static function paymentRejected(Payment $p): self
    {
        $i = $p->invoice;

        return (new self('Оплата '.Money::rub($p->amount).' по счёту '.$i->label().' не поступила', $p->reject_reason ?: 'Проверьте платёж и сообщите снова', self::path($i), $i->deal?->offer?->number))
            ->tg($i, 'Оплата по :car не поступила', 'Оплата по счёту '.$i->label().' не поступила', Money::rub($p->amount).', '.mb_lcfirst($p->reject_reason ?: 'Проверьте платёж и сообщите снова'))->about($i);
    }

    public static function payout(Payment $p): self
    {
        $i = $p->invoice;

        return (new self('Выплачено '.Money::rub($p->amount).($i->remaining() > 0 ? ', осталось '.Money::rub($i->remaining()) : ''), $i->deal?->offer?->titleWithYear() ?? Car::ofInvoice($i)?->offer->titleWithYear(), self::path($i), $i->deal?->offer?->number))
            ->tg($i, 'Вознаграждение по :car выплачено', 'Вознаграждение выплачено', Money::rub($p->amount).($i->remaining() > 0 ? ', осталось '.Money::rub($i->remaining()) : ''))->about($i);
    }

    public static function claimed(Payment $p): self
    {
        $i = $p->invoice;

        return (new self(($i->deal?->buyer?->shortName() ?? $i->party->name).' сообщил об оплате '.Money::rub($p->amount).' по счёту '.$i->label(), $i->deal?->offer?->titleWithYear() ?? Car::ofInvoice($i)?->offer->titleWithYear(), Car::ofInvoice($i)?->url() ?? '/work/money', $i->deal?->offer?->number, true))->about($i);
    }

    /** Оплатили по ссылке — менеджеру: его счёт закрылся или уменьшился сам. */
    public static function paidOnline(PayLink $link, Payment $p): self
    {
        $i = $p->invoice;

        return (new self('Оплачено по ссылке '.Money::rub($p->amount).', счёт '.$i->label(), $i->remaining() > 0 ? 'Остаток '.Money::rub($i->remaining()) : ($i->deal?->offer?->titleWithYear() ?? Car::ofInvoice($i)?->offer->titleWithYear()),
            self::path($i), $i->deal?->offer?->number))
            ->tg($i, 'Оплачено по ссылке: :car', 'Оплачено по ссылке, счёт '.$i->label(), Money::rub($p->amount).', '.self::rest($i))->about($i);
    }

    /** Оплатили по ссылке — сотрудникам в «Деньги». */
    public static function paidOnlineStaff(PayLink $link, Payment $p): self
    {
        $i = $p->invoice;

        return (new self('По ссылке оплачено '.Money::rub($p->amount).', счёт '.$i->label().', платил '.$link->payerLabel(), $i->deal?->offer?->titleWithYear() ?? Car::ofInvoice($i)?->offer->titleWithYear(),
            Car::ofInvoice($i)?->url() ?? '/work/money?preset=paid', $i->deal?->offer?->number, true))->about($i);
    }

    /** Из выписки пришли деньги, которые сами к счёту не легли. */
    public static function bankUnmatched(int $count, float $sum): self
    {
        return new self($count === 1 ? 'Поступление без счёта на '.Money::rub($sum) : 'Поступления без счёта: '.$count.' на '.Money::rub($sum), null, '/work/money/bank', null, true);
    }

    /** Просрочен счёт ПРАЙМ (сделка, гараж) — сотрудникам в «Деньги» CRM; счета парковки идут `ParkNotice`. */
    public static function overdue(Invoice $i): self
    {
        return new self(($i->isOwed() ? 'Мы просрочили ' : 'Просрочен счёт ').$i->label().', '.$i->party->name, Money::rub($i->remaining()), '/work/money/invoices/'.$i->id, $i->deal?->offer?->number, true);
    }

    public static function feeDue(Invoice $fee): self
    {
        return (new self('К выплате '.Money::rub($fee->total).' — '.$fee->party->name, $fee->deal?->offer?->titleWithYear().', до '.$fee->due_at->translatedFormat('j M'), '/work/money?preset=payouts', $fee->deal?->offer?->number, true))->about($fee);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): ?string
    {
        return $this->text ?: null;
    }

    public function href(): string
    {
        // Абсолютный адрес — гаражная машина: хост уже выбран, CRM к нему не приклеивать.
        return $this->toStaff && ! str_starts_with($this->path, 'http') ? Surface::Crm->url($this->path) : $this->path;
    }

    public function offerNumber(): ?int
    {
        return $this->offerNumber;
    }

    public function category(): string
    {
        return $this->toStaff ? 'money' : 'deals';
    }

    public function critical(): bool
    {
        return ! $this->toStaff;
    }
}
