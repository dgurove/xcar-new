<?php

namespace App\Offers;

use Illuminate\Support\Facades\DB;

/**
 * Метки по вендору (11.10.2026, начальник): черновик, которому встал вендор (заведение любым путём или смена вендора),
 * получает его метки (`tag_vendor`); при смене вендора метки прежнего, которых нет у нового, снимаются. В другое время
 * не трогает ничего — снятая у предложения метка сама не вернётся. Зовёт `Offer::saving`.
 */
final class VendorTags
{
    public static function apply(Offer $offer): void
    {
        if (! $offer->isDirty('vendor_id') || ($offer->state ?? OfferState::Draft) !== OfferState::Draft) {
            return;
        }
        $new = self::of($offer->vendor_id);
        $old = $offer->exists ? self::of($offer->getOriginal('vendor_id')) : [];
        $tags = array_values(array_diff($offer->tags ?? [], array_diff($old, $new)));
        $tags = array_values(array_unique([...$tags, ...$new]));
        if ($tags !== array_values($offer->tags ?? [])) {
            $offer->tags = $tags;
        }
    }

    /** @return list<string> */
    private static function of(?int $vendorId): array
    {
        if (! $vendorId) {
            return [];
        }

        return DB::table('tag_vendor')->join('tags', 'tags.id', '=', 'tag_vendor.tag_id')
            ->where('tag_vendor.vendor_id', $vendorId)->orderBy('tags.sort')->pluck('tags.name')->all();
    }
}
