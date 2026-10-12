<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Обращение с сайта: чат без предложения. У вошедшего — одно на аккаунт,
 * у гостя — по токену из cookie; новому гостю токен выдаётся здесь. У покупателя менеджера (12.10.2026, владелец:
 * «покупатель пишет менеджеру, площадку убрать») — со своим менеджером: прежнее обращение к площадке остаётся отдельным.
 */
final class OpenEnquiry
{
    public function __invoke(?User $user, ?string $name, ?string $token): Chat
    {
        return DB::transaction(function () use ($user, $name, $token) {
            if ($user?->isBuyer() && $user->manager_id) {
                return Chat::firstOrCreate(['offer_id' => null, 'user_id' => $user->id, 'manager_id' => $user->manager_id]);
            }
            if ($user) {
                return Chat::firstOrCreate(['offer_id' => null, 'user_id' => $user->id, 'manager_id' => null]);
            }
            if ($token && ($chat = Chat::whereNull('offer_id')->where('guest_token', hash('sha256', $token))->first())) {
                return $chat;
            }
            $plain = Str::random(40);
            $chat = Chat::create(['guest_name' => $name, 'guest_token' => hash('sha256', $plain)]);
            $chat->plainToken = $plain;

            return $chat;
        });
    }
}
