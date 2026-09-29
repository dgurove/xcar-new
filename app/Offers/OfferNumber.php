<?php

namespace App\Offers;

use Illuminate\Support\Facades\DB;

/**
 * Номер предложения: дата и порядковый слитно — 2609291066, как на старом сайте. Выдаётся один раз, при первом
 * выходе наружу (в продажу или в галерею, `ChangeOfferState`), и больше не меняется: ссылка, которой поделились,
 * переживает смену состояния. До этого у черновика временный порядковый номер из той же последовательности — мусорный
 * черновик из письма боевого номера не съедает. Прежний номер остаётся в `offer_number_aliases`: ссылки на него
 * открывают то же предложение (`Offer::resolveRouteBinding`).
 */
final class OfferNumber
{
    public static function next(): int
    {
        return (int) (now()->format('ymd').DB::selectOne("SELECT nextval('offer_numbers') AS n")->n);
    }

    /** Выдать публичный номер, если его ещё не было; прежний — в псевдонимы. Вызывать под замком строки. */
    public static function issue(Offer $offer): void
    {
        if ($offer->published_at !== null || $offer->is_demo) {
            return;
        }
        DB::table('offer_number_aliases')->insertOrIgnore(['number' => $offer->number, 'offer_id' => $offer->id]);
        $offer->number = self::next();
    }

    /** Предложение по номеру — нынешнему или прежнему. */
    public static function find(int|string $number): ?Offer
    {
        if (! ctype_digit((string) $number)) {
            return null;
        }

        return Offer::where('number', $number)->first()
            ?? Offer::whereIn('id', DB::table('offer_number_aliases')->where('number', $number)->select('offer_id'))->first();
    }
}
