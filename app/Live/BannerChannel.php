<?php

namespace App\Live;

use App\Notifications\Notice;
use App\Users\User;

/**
 * Карточка в приложении без строки в ленте: обычно её публикует лента (`PublishLiveUpdates::notification`), а
 * уведомлению без `database` (новые предложения пачкой) — этот канал. Звенит, но уходит сама; в тихие часы — молча.
 */
final class BannerChannel
{
    public function __construct(private Publisher $publish) {}

    public function send(User $user, Notice $notice): void
    {
        $this->publish->notice(Topics::user($user), Banner::of($notice, (string) $notice->id, $user, sound: ! $user->quietHours()));
    }
}
