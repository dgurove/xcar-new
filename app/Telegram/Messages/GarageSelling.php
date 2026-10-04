<?php

namespace App\Telegram\Messages;

use App\Garage\Car;
use App\Garage\Payer;
use App\Support\Money;
use App\Telegram\Text;

/** Менеджер продаёт машину из гаража: цена его, нам — назначить вознаграждение и выставить счёт. */
final class GarageSelling extends Message
{
    public function __construct(private Car $car) {}

    protected function title(): string
    {
        return 'Продаёт из гаража: '.$this->car->offer->titleWithYear();
    }

    protected function lines(): array
    {
        $car = $this->car;
        $spent = $car->spent(Payer::Manager);

        return Text::lines($car->offer,
            ($car->manager?->shortName() ?? 'Взяли под себя').' за '.Money::rub($car->sold_price),
            $spent > 0 ? 'Его расходы '.Money::exact($spent) : null,
        );
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'ТС в CRM', 'url' => $this->car->crmUrl()];
    }
}
