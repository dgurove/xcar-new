<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Личная заметка к ТС на xcar.ru (18.10.2026): одна на человека и предложение, видна только автору. Пишется обычным
 * соединением, не `pgsql_async`: это набранное человеком, терять нельзя.
 */
final class OfferNote
{
    public const MAX = 5000;

    public static function of(?User $user, Offer $offer): string
    {
        if (! $user?->keepsNotes()) {
            return '';
        }

        return (string) DB::table('offer_notes')->where('user_id', $user->id)->where('offer_id', $offer->id)->value('text');
    }

    /** Пустая — строки нет. */
    public static function put(User $user, Offer $offer, string $text): void
    {
        $key = ['user_id' => $user->id, 'offer_id' => $offer->id];
        if (trim($text) === '') {
            DB::table('offer_notes')->where($key)->delete();

            return;
        }
        DB::table('offer_notes')->updateOrInsert($key, ['text' => $text, 'updated_at' => now()]);
    }
}
