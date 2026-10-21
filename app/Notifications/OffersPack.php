<?php

namespace App\Notifications;

use App\Live\BannerChannel;
use App\Push\WebPushChannel;
use App\Telegram\Offers\Subscriber;
use App\Users\User;

/**
 * Пачка предложений без ленты — «Опубликовано N» (`NewOffersNotice`) и «Приём до 17:00: N непросмотренных»
 * (`UnreadClosingNotice`): пуш, кроме тихих часов, карточка со звуком, что уходит сама, и основной бот Telegram (21.10.2026),
 * кроме подписчика бота предложений — тот пишет о новых сам. Категория `offers` выключает всё разом.
 */
trait OffersPack
{
    public function via(User $user): array
    {
        if (! $user->wants($this->category())) {
            return [];
        }
        $via = $user->quietHours() ? [BannerChannel::class] : [WebPushChannel::class, BannerChannel::class];
        if ($user->wantsTelegram($this->category()) && ! Subscriber::listens($user)) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    public function category(): string
    {
        return 'offers';
    }

    /** Telegram у него — не повод висеть до нажатия: карточка и пуш уходят сами. */
    public function important(): bool
    {
        return false;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => array_values(array_filter([$this->text()])), 'button' => 'Посмотреть'];
    }
}
