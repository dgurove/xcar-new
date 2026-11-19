<?php

namespace App\Notifications;

use App\Live\BannerChannel;
use App\Push\WebPushChannel;
use App\Telegram\Offers\Subscriber;
use App\Users\User;

/**
 * Пачка предложений — «Опубликовано N» (`NewOffersNotice`) и «Приём до 17:00: N непросмотренных»
 * (`UnreadClosingNotice`), «Через 30 минут закончится приём» (`FavoriteClosingNotice`): пуш, кроме тихих часов, карточка
 * со звуком и основной бот Telegram (21.10.2026), кроме подписчика бота предложений — тот пишет о новых сам. Сотруднику
 * категория `offers` выключает всё разом; менеджеру и покупателю — строка ленты всегда (19.11.2026), пуш и Telegram по теме.
 */
trait OffersPack
{
    public function via(User $user): array
    {
        // Менеджеру и покупателю (19.11.2026): строкой в ленте всегда (владелец выбрал «предложения в ленте»), пуш и Telegram —
        // по теме («Новые предложения», «Конец приёма»); карточку в приложении публикует сама строка ленты
        // (`PublishLiveUpdates::notification`), `BannerChannel` не нужен.
        if (Categories::siteOnly($user)) {
            $via = ['database'];
            if ($user->wantsVia($this->topic(), 'push') && ! $user->quietHours()) {
                $via[] = WebPushChannel::class;
            }
            if ($user->wantsTelegram() && $user->wantsVia($this->topic(), 'telegram') && ! ($this->skipsOffersBot() && Subscriber::subscribed($user))) {
                $via[] = TelegramChannel::class;
            }

            return $via;
        }
        if (! $user->wants($this->category())) {
            return [];
        }
        $via = $user->quietHours() ? [BannerChannel::class] : [WebPushChannel::class, BannerChannel::class];
        if ($user->wantsTelegram($this->category()) && ! ($this->skipsOffersBot() && Subscriber::subscribed($user))) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    /** Подписчику бота предложений основной бот о новом не пишет: тот пишет сам. Об избранном бот молчит — пишем. */
    protected function skipsOffersBot(): bool
    {
        return true;
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
