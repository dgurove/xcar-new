<?php

namespace App\Workflow\Presets;

/**
 * Машина уже наша и стоит на нашей парковке (выкуп у лизинга, Каркаде): поставщика в сделке нет, писем
 * тоже. Выбрали подтверждение — менеджер соглашается, платит по счёту, забирает машину с парковки.
 * Оплата по счёту и выдача с парковки двигают этапы сами (AdvanceOnPayment, SyncOffer).
 */
final class Stock extends Route
{
    public function blocks(): array
    {
        $sale = $this->saleBlocks();

        return [
            'sale' => $sale['sale'],
            'confirm' => ['name' => 'Согласие на покупку', 'text' => 'Автомобиль Ваш по Вашей цене. Подтвердите покупку.'],
            'payment' => ['name' => 'Оплата', 'text' => 'Счёт выставлен. После оплаты передадим автомобиль.'],
            'handover' => ['name' => 'Передача автомобиля', 'text' => 'Автомобиль на нашей парковке, его можно забирать.'],
            'won' => $sale['won'],
            'nobody' => $sale['nobody'],
        ];
    }

    public function stages(): array
    {
        $head = $this->head();

        return [
            'draft' => $head['draft'],
            'bidding' => ['exits' => [['Подтверждение принято', 'staff', 'manager_confirm'], ['Срок приёма истёк', 'timer', 'choosing']]] + $head['bidding'],
            'choosing' => ['exits' => [['Подтверждение принято', 'staff', 'manager_confirm'], ['Подтверждений нет', 'staff', 'no_bids'], ['Возобновить приём', 'staff', 'bidding']]] + $head['choosing'],
            'manager_confirm' => [
                'name' => 'Согласие менеджера', 'block' => 'confirm', 'waits_for' => 'manager', 'limit_minutes' => 240, 'offer_state' => 'sold',
                'ask_title' => 'Подтвердите покупку', 'ask_text' => 'Автомобиль Ваш по Вашей цене. Подтвердите покупку или откажитесь от неё.',
                // Отказ — обычный исход в приём: вход туда отменяет сделку, остальные подтверждения ждут в резерве.
                'exits' => [['Покупаю', 'manager', 'invoice'], ['Отказываюсь', 'manager', 'bidding']],
            ],
            'invoice' => [
                'name' => 'Счёт выставлен менеджеру', 'block' => 'payment', 'waits_for' => 'manager', 'limit_minutes' => 3 * self::DAY, 'asks' => 'document',
                'ask_title' => 'Оплатите счёт', 'ask_text' => 'Оплатите счёт и приложите платёжное поручение.',
                'exits' => [['Платёжное поручение приложено', 'manager', 'payment_check']],
            ],
            'payment_check' => [
                'name' => 'Проверка оплаты', 'block' => 'payment', 'waits_for' => 'us', 'limit_minutes' => self::DAY,
                'exits' => [['Оплата получена', 'staff', 'release'], ['Оплата не поступила', 'staff', 'invoice']],
            ],
            ...$this->releaseSegment('closed_won'),
            'closed_won' => $this->tail()['closed_won'],
            'no_bids' => $this->tail()['no_bids'],
        ];
    }
}
