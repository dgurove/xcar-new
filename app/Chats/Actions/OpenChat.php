<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Чат по офферу для человека: заводится один раз. Менеджеру — с площадкой, с приветствием от неё;
 * покупателю — со своим менеджером (manager_id; сменился менеджер — чат переходит к новому), без приветствия.
 */
final class OpenChat
{
    public const GREETING = 'Добрый день! Спрашивайте про ТС — ответим здесь.';

    /** @param  bool  $greet  приветствие площадки; сотрудник, что пишет менеджеру первым (05.10.2026), обходится без него */
    public function __invoke(Offer $offer, User $user, bool $greet = true): Chat
    {
        return DB::transaction(function () use ($offer, $user, $greet) {
            $manager = $user->isBuyer() ? $user->manager_id : null;
            $chat = Chat::firstOrCreate(['offer_id' => $offer->id, 'user_id' => $user->id], ['manager_id' => $manager]);
            if ($chat->wasRecentlyCreated && ! $manager && $greet) {
                $chat->messages()->create(['seq' => 1, 'author_kind' => AuthorKind::System, 'text' => self::GREETING]);
                $chat->update(['messages_count' => 1, 'last_message_at' => now()]);
            } elseif ($manager && $chat->manager_id !== $manager) {
                $chat->update(['manager_id' => $manager]);
            }

            return $chat;
        });
    }
}
