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
        return 'Теперь можно открывать ему предложения.';
    }

    public function href(): string
    {
        return "/lk/pokupateli/{$this->buyer->id}";
    }
}
