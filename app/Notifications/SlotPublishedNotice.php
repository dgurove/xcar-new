<?php

namespace App\Notifications;

use App\Support\Plural;
use App\Telegram\Offers\Subscriber;
use App\Users\User;

/**
 * Слот вышел (или наступила волна показа слота): одно уведомление на пачку вместо «Нового предложения» на каждое
 * (владелец 03.10.2026). Тема — каталог: одна строка в ленте, гаснет, когда менеджер зашёл в «Предложения».
 */
final class SlotPublishedNotice extends Notice
{
    public function __construct(private int $count) {}

    public function title(): string
    {
        return 'Опубликовано '.$this->count.' '.Plural::of($this->count, ['предложение', 'предложения', 'предложений']);
    }

    public function href(): string
    {
        return '/offers';
    }

    public function category(): string
    {
        return 'offers';
    }

    /** Новое в Telegram с 21.10.2026 (владелец), кроме подписчика бота предложений: тот пишет о нём сам. */
    public function via(User $user): array
    {
        $via = parent::via($user);

        return in_array(TelegramChannel::class, $via, true) && Subscriber::listens($user)
            ? array_values(array_diff($via, [TelegramChannel::class])) : $via;
    }

    /** Карточка и пуш уходят сами, как прежде: Telegram — не повод висеть до нажатия. */
    public function important(): bool
    {
        return false;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [], 'button' => 'Посмотреть'];
    }
}
