<?php

namespace App\Telegram\Actions;

use App\Live\Publisher;
use App\Live\Topics;
use App\Telegram\StartLink;
use App\Users\User;

/** Кнопка в чате под «Вход на xcar.ru»: страница входа, которая ждёт, узнаёт исход сразу. */
final class DecideLogin
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(string $token, User $user, bool $allow): void
    {
        StartLink::settle($token, $allow ? 'ok' : 'no', $user->id);
        ($this->publish)(Topics::login($token), 'telegram', ['state' => $allow ? 'login' : 'denied']);
    }
}
