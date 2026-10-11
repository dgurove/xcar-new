<?php

namespace App\Offers;

use App\Support\Facets\Facet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Подпись пачки в каталоге по сроку (владелец 10.10.2026): слот закрывает приём у пачки предложений в один час —
 * «До 17:00 сегодня», «До 17:00 завтра», «До 17:00 13 октября»; закрытое и «Идёт сделка» — одной пачкой в конце.
 */
final class CloseGroups
{
    /** Галерея (11.10.2026) — пачки по концу окна показа: «До 23:59 сегодня». */
    public static function label(Offer $offer, bool $gallery = false): string
    {
        return $gallery
            ? self::of($offer->gallery_until, true)
            : self::of($offer->bids_close_at, $offer->bidsOpen() && $offer->state === OfferState::Open);
    }

    public static function of(?CarbonInterface $at, bool $open): string
    {
        if (! $open) {
            return 'Приём закрыт';
        }
        if (! $at) {
            return 'Без срока';
        }
        $day = match (true) {
            $at->isToday() => 'сегодня',
            $at->isTomorrow() => 'завтра',
            default => $at->translatedFormat('j F'),
        };

        return 'До '.$at->format('H:i').' '.$day;
    }

    /**
     * Сколько в каждой пачке по всему списку, а не по странице: пачка слота бывает длиннее страницы.
     *
     * @return array<string, int> подпись → число
     */
    public static function sizes(Builder $list, bool $gallery = false): array
    {
        if ($gallery) {
            $out = [];
            foreach (Facet::bare($list->toBase())->selectRaw('offers.gallery_until as at, count(*) as n')->groupBy('offers.gallery_until')->get() as $r) {
                $label = self::of($r->at ? Carbon::parse($r->at) : null, true);
                $out[$label] = ($out[$label] ?? 0) + (int) $r->n;
            }

            return $out;
        }
        $rows = Facet::bare($list->toBase())
            ->selectRaw("offers.bids_close_at as at, (offers.state = ? and (offers.bids_close_at is null or offers.bids_close_at > now())) as open, count(*) as n", [OfferState::Open->value])
            ->groupBy('offers.bids_close_at', 'offers.state')->get();
        $out = [];
        foreach ($rows as $r) {
            $label = self::of($r->at ? Carbon::parse($r->at) : null, (bool) $r->open);
            $out[$label] = ($out[$label] ?? 0) + (int) $r->n;
        }

        return $out;
    }
}
