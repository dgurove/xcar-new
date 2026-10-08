<?php

namespace App\Offers\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Notifications\DealOpenedNotice;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Facades\Notification;

/**
 * Сделку открыли менеджерам: вновь добавленным — «Предложение в сделке открыто вам»
 * (как о новом: лента, пуш, почта по настройкам категории `offers`), а карточка
 * въезжает им в каталог живым потоком. Кто уже был в списке — молча.
 */
final class NotifyDealAudience
{
    public function __construct(private Publisher $publish) {}

    /** @param  list<int>  $userIds */
    public function added(Offer $offer, array $userIds): int
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        // Демо-предложения скрыты от всех, кроме демо-пользователя, — писать о них некому.
        if (! $userIds || $offer->is_demo) {
            return 0;
        }
        Notification::send(User::whereIn('id', $userIds)->get(), new DealOpenedNotice($offer));
        $this->publish->card($offer->number, Topics::CATALOG);

        return count($userIds);
    }
}
