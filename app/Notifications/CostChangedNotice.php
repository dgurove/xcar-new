<?php

namespace App\Notifications;

use App\Offers\Bid;
use App\Offers\Deal;
use App\Support\Money;
use App\Telegram\Text;

/**
 * Менеджеру: при переоценке его закупочную обновили (28.10.2026, владелец: писать только тем, кому обновили; кому
 * оставили прежнюю — молчим, он ничего не узнаёт). У подтверждения — ведёт на предложение, у сделки — на сделку, где
 * счёт и ДКП уже перевыставлены.
 */
final class CostChangedNotice extends Notice
{
    public function __construct(private Bid|Deal $on, private int $from, private int $to) {}

    public function title(): string
    {
        return 'Закупочная изменилась: '.$this->on->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return Money::plain($this->from).' → '.Money::rub($this->to).($this->on instanceof Deal ? ', счёт и договор обновлены' : '');
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
