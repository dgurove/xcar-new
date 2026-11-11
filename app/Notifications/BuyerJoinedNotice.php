<?php

namespace App\Notifications;

use App\Users\User;

/** Менеджеру: человек прошёл по его ссылке. */
final class BuyerJoinedNotice extends Notice
{
    /** @param  bool  $transferred  админ передал покупателя от другого менеджера (`TransferBuyer`) */
    public function __construct(private User $buyer, private bool $transferred = false) {}

    public function label(): string
    {
        return 'Покупатель по ссылке';
    }

    public function title(): string
    {
        // Админ завёл за менеджера (без ссылки) или передал от другого — «в ваших покупателях», а не «принял приглашение».
        return $this->buyer->name.($this->transferred || $this->buyer->invite?->manual ? ' в ваших покупателях' : ' принял приглашение');
    }

    public function text(): ?string
    {
        // Без доступа (заведён по имени) — ничего не видит (11.11.2026); с доступом: всё открытое менеджеру (по умолчанию) —
        // делать ничего не нужно, «Выбранные» — ждут менеджера.
        return match (true) {
            ! $this->buyer->hasBuyerAccess() => 'Без доступа к предложениям',
            $this->buyer->buyerSeesAll() => 'Видит все ваши предложения',
            default => 'Откройте ему предложения',
        };
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
