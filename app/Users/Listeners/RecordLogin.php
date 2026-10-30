<?php

namespace App\Users\Listeners;

use App\Users\Impersonation;
use App\Users\User;
use App\Users\Visit;
use Illuminate\Auth\Events\Login;

/** Вход — новое посещение с отметкой «вход» (`Visit`). Вход админа за человека («Войти как») не пишется. */
final class RecordLogin
{
    public function handle(Login $event): void
    {
        if ($event->user instanceof User && ! Impersonation::active() && ! app()->runningInConsole()) {
            Visit::mark($event->user, request(), login: true);
        }
    }
}
