<?php

namespace App\Users\Jobs;

use App\Users\Actions\TakeTelegramAvatar as Take;
use App\Users\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** После привязки Telegram — фото профиля в аватар, если своего нет (`TakeTelegramAvatar`). */
final class TakeTelegramAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $userId) {}

    public function handle(Take $take): void
    {
        if ($user = User::find($this->userId)) {
            $take($user);
        }
    }
}
