<?php

namespace App\Chats\Actions;

use App\Chats\Chat;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Обращение с сайта: чат без предложения. У вошедшего — одно на аккаунт,
 * у гостя — по токену из cookie; новому гостю токен выдаётся здесь.
 */
final class OpenEnquiry
{
    public function __invoke(?User $user, ?string $name, ?string $token): Chat
    {
        return DB::transaction(function () use ($user, $name, $token) {
            if ($user) {
                return Chat::firstOrCreate(['offer_id' => null, 'user_id' => $user->id]);
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
