<?php

namespace App\Notifications;

use App\Users\User;

/** Менеджеру: человек прошёл по его ссылке. */
final class BuyerJoinedNotice extends Notice
{
    public function __construct(private User $buyer) {}

    public function title(): string
    {
        return $this->buyer->name.' принял приглашение';
    }

    public function text(): ?string
    {
        // Видит всё открытое менеджеру (12.10.2026, по умолчанию) — делать ничего не нужно; «Выбранные» — ждут менеджера.
        return $this->buyer->buyerSeesAll() ? 'Видит все ваши предложения' : 'Откройте ему предложения';
    }

    public function href(): string
    {
        return "/buyers/{$this->buyer->id}";
    }

    public function actor(): ?User
    {
        return $this->buyer;
    }

    public function category(): string
    {
        return 'people';
    }
}
