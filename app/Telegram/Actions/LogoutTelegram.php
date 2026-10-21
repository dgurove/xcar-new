<?php

namespace App\Telegram\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Telegram\Offers\Subscriber;
use App\Users\Access\Lock;
use App\Users\User;

/**
 * `/logout` в любом из ботов (21.10.2026, владелец: «у меня один бот на одном аккаунте, другой на другом получился»):
 * этот Telegram выходит из аккаунта в обоих ботах сразу — основной отвязывается (`UnlinkChat`), подписка на бот
 * предложений удаляется. Менеджеру под полной защитой входа — нельзя: Telegram — его единственный вход.
 */
final class LogoutTelegram
{
    public const LOCKED = 'locked';

    public function __construct(private UnlinkChat $unlink, private Publisher $publish) {}

    /** @return User|string|null кто вышел; `LOCKED` — нельзя; null — этот Telegram ни к кому не привязан */
    public function __invoke(int $chatId): User|string|null
    {
        $main = User::where('telegram_chat_id', $chatId)->first();
        $sub = Subscriber::with('user')->where('chat_id', $chatId)->first();
        $user = $main ?? $sub?->user;
        if (! $user) {
            return null;
        }
        if (collect([$main, $sub?->user])->filter()->contains(fn (User $u) => Lock::enabled() && $u->underLock())) {
            return self::LOCKED;
        }
        if ($main) {
            ($this->unlink)($main);
            ($this->publish)(Topics::user($main), 'telegram', ['state' => 'unlinked']);
        }
        if ($sub) {
            Subscriber::where('chat_id', $chatId)->delete();
            ($this->publish)(Topics::user($sub->user), 'offers-bot', ['state' => 'unlinked']);
        }

        return $user;
    }
}
