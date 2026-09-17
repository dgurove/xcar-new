<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Чат по офферу для человека: заводится один раз, с приветствием от площадки. */
final class OpenChat
{
    public const GREETING = 'Добрый день! Спрашивайте про ТС — ответим здесь.';

    public function __invoke(Offer $offer, User $user): Chat
    {
        return DB::transaction(function () use ($offer, $user) {
            $chat = Chat::firstOrCreate(['offer_id' => $offer->id, 'user_id' => $user->id]);
            if ($chat->wasRecentlyCreated) {
                $chat->messages()->create(['seq' => 1, 'author_kind' => AuthorKind::System, 'text' => self::GREETING]);
                $chat->update(['messages_count' => 1, 'last_message_at' => now()]);
            }

            return $chat;
        });
    }
}
