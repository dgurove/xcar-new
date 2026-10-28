<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Support\Money;
use App\Telegram\Text;
use App\Users\User;

/**
 * Менеджеру: при переоценке его закупочную обновили (28.10.2026, владелец: писать только тем, кому обновили; кому
 * оставили прежнюю — молчим). Закупочной на xcar нет ни у кого, поэтому уведомление говорит только то, что человек и так
 * видит (`for`):
 * - подтверждение — цена «от» (заявленная от закупочной, `Offer::declaredFrom`); вписана заявленная рукой — для него
 *   ничего не поменялось, не пишем;
 * - сделка — закупочная числом только там, где она у него на экране: «Расчёт» ДКП и «страховой напрямую», гараж
 *   менеджера (`Car::managerPrice`); у остальных — «счёт и договор обновлены»;
 * - супер-покупателю и покупателю с гаражом — никогда: ему закупочная не видна вовсе.
 */
final class CostChangedNotice extends Notice
{
    private function __construct(private Bid|Deal $on, private ?int $from, private ?int $to) {}

    /** @return array{0: User, 1: self}|null кому и что; null — не пишем */
    public static function for(?User $user, Bid|Deal $on, int $from, int $to): ?array
    {
        if (! $user || $user->isSuperBuyer() || $user->holdsAsBuyer()) {
            return null;
        }
        if ($on instanceof Bid) {
            if ($on->offer->publish_price !== null || Offer::declaredFrom($from) === Offer::declaredFrom($to)) {
                return null;
            }

            return [$user, new self($on, Offer::declaredFrom($from), Offer::declaredFrom($to))];
        }
        $shown = $on->paysSelection() || $on->isGarage();

        return [$user, new self($on, $shown ? $from : null, $shown ? $to : null)];
    }

    public function title(): string
    {
        return ($this->on instanceof Deal ? 'Деньги сделки обновлены: ' : 'Цена «от» изменилась: ').$this->on->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $change = $this->from !== null ? Money::nums($this->from).' → '.Money::rub((int) $this->to) : null;
        if ($this->on instanceof Bid) {
            return 'от '.$change;
        }

        return ($change ? 'Закупочная '.$change.', ' : '').($this->on->isGarage() ? 'расчёт обновлён' : 'счёт и договор обновлены');
    }

    public function href(): string
    {
        return $this->on instanceof Deal ? $this->on->href() : '/offers/'.$this->on->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->on->offer->number;
    }

    public function category(): string
    {
        return $this->on instanceof Deal ? 'deals' : 'bids';
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->on->offer, $this->text()), 'button' => 'Открыть'];
    }
}
