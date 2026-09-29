<?php

use App\Offers\Offer;
use App\Purchases\Car;
use Illuminate\Database\Migrations\Migration;

/**
 * Предложения из закупки (Каркаде) досчитываются тем, что было в карточке закупки: VIN и адрес открыты, ключи,
 * «Ограничения ФССП» меткой, состояние, руль, обременения и город — в описание. Своё вписанное не трогается.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Car::whereNotNull('offer_id')->get() as $car) {
            $offer = Offer::find($car->offer_id);
            if (! $offer) {
                continue;
            }
            $x = $car->offerExtras();
            $offer->forceFill(array_filter([
                'show_vin' => true,
                'show_address' => $x['show_address'] ?? null,
                'has_keys' => $offer->has_keys ?? ($x['has_keys'] ?? null),
                'description' => $offer->description ?: ($x['description'] ?? null),
                'tags' => isset($x['tags']) ? array_values(array_unique([...($offer->tags ?? []), ...$x['tags']])) : null,
            ], fn ($v) => $v !== null))->saveQuietly();
        }
    }

    public function down(): void {}
};
