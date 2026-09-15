<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Users\User;

/** У менеджера появился покупатель: просто знать, решать нечего. */
final class BuyerJoined extends Message
{
    public function __construct(private User $buyer) {}

    protected function title(): string
    {
        return 'Новый покупатель';
    }

    protected function lines(): array
    {
        $manager = $this->buyer->manager;

        return [
            $this->buyer->name.' — покупатель '.($manager?->shortName() ?? '—'),
            $this->buyer->phone ? $this->buyer->phoneFormatted() : null,
            $this->buyer->email,
            self::moment($this->buyer->created_at),
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Покупатели в CRM', 'url' => Surface::Crm->url('/nastroyki/polzovateli?preset=buyers')];
    }
}
