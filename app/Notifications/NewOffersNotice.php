<?php

namespace App\Notifications;

use App\Live\BannerChannel;
use App\Offers\Offer;
use App\Push\WebPushChannel;
use App\Support\Plural;
use App\Users\User;

/**
 * Новые предложения пачкой после 5 минут тишины (`NotifyNewOffers`, 16.10.2026): «3 новых предложения», ниже «Haval F7,
 * Tenet T4 и ещё 1»; одно — «Новое предложение: Tenet T4, 2023» и ведёт на него, пачка — в каталог на пилюлю «Новые».
 * В ленту и колокольчик не идёт (владелец: «не захламлять раздел уведомлений»): пуш, кроме тихих часов, и карточка в
 * приложении со звуком, которая уходит сама. Пуши заменяют друг друга: в шторке телефона висит один.
 */
final class NewOffersNotice extends Notice
{
    /** @param  list<string>  $names  первые два названия */
    public function __construct(private int $count, private array $names, private ?Offer $offer = null) {}

    public function via(User $user): array
    {
        if (! $user->wants($this->category())) {
            return [];
        }

        return $user->quietHours() ? [BannerChannel::class] : [WebPushChannel::class, BannerChannel::class];
    }

    public function title(): string
    {
        if ($this->offer) {
            return 'Новое предложение: '.$this->offer->titleWithYear();
        }

        return $this->count.' '.Plural::of($this->count, ['новое предложение', 'новых предложения', 'новых предложений']);
    }

    public function text(): ?string
    {
        if ($this->offer) {
            return null;
        }
        $rest = $this->count - count($this->names);

        return implode(', ', $this->names).($rest > 0 ? ' и ещё '.$rest : '');
    }

    public function href(): string
    {
        return $this->offer ? "/offers/{$this->offer->number}" : '/offers?view=fresh';
    }

    /** Одна тема на все: новая карточка и новый пуш заменяют прежние. */
    public function subject(): string
    {
        return '/offers';
    }

    public function offerNumber(): ?int
    {
        return $this->offer?->number;
    }

    public function category(): string
    {
        return 'offers';
    }
}
