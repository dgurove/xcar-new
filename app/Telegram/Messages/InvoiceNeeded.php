<?php

namespace App\Telegram\Messages;

use App\Offers\Deal;
use App\Support\Money;
use App\Telegram\Text;

/** Владельцу: сделка дошла до оплаты, счёта нет — кнопка ведёт прямо в «Выставить счёт». */
final class InvoiceNeeded extends Message
{
    public function __construct(private Deal $deal, private ?string $gap = null) {}

    public function offer(): ?\App\Offers\Offer
    {
        return $this->deal->offer;
    }

    protected function title(): string
    {
        return \App\Workflow\Position::gapAction($this->gap, $this->deal)['title'].': '.$this->deal->offer->titleWithYear();
    }

    protected function lines(): array
    {
        // Отправить менеджеру — что именно и на сколько, а не цена сделки (17.11.2026, владелец: «кажется, что это счёт на машину»).
        if ($this->gap === 'send') {
            return Text::lines($this->deal->offer, ...[
                ...\App\Billing\PayOffer::unsent($this->deal)->map(fn ($i) => \App\Billing\PayOffer::line($i))->all(),
                $this->deal->buyer ? 'Менеджер '.$this->deal->buyer->name : null,
            ]);
        }

        return Text::lines($this->deal->offer,
            $this->deal->buyer ? 'Менеджер '.$this->deal->buyer->name : null,
            $this->deal->amount ? 'Цена подтверждения '.Money::rub($this->deal->amount) : null);
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        $action = \App\Workflow\Position::gapAction($this->gap, $this->deal);

        return ['text' => $action['button'], 'url' => $action['url']];
    }
}
