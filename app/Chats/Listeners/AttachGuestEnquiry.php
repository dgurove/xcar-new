<?php

namespace App\Chats\Listeners;

use App\Chats\GuestEnquiry;
use Illuminate\Auth\Events\Login;

/** Гость вошёл или зарегистрировался из того же браузера — его обращение становится чатом аккаунта. */
final class AttachGuestEnquiry
{
    public function __construct(private GuestEnquiry $guest) {}

    public function handle(Login $event): void
    {
        if (! app()->bound('request')) {
            return;
        }
        $chat = $this->guest->chat(request());
        if ($chat && $chat->user_id === null) {
            $chat->update(['user_id' => $event->user->getAuthIdentifier(), 'guest_token' => null]);
        }
        if ($chat) {
            $this->guest->forget();
        }
    }
}
