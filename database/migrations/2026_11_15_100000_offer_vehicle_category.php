<?php

use App\Offers\Offer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тип ТС предложения колонкой (03.10.2026): фильтр «Тип ТС» в списках CRM и каталоге. Считается тем же правилом,
 * что иконка перед названием (`Offer::guessCategory` — кузов, ТС на парковке, догадка по марке), и хранится, потому что
 * догадку по марке SQL не повторить. Пересчитывает `Offer::saving` и смена категории ТС на парковке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $t) {
            $t->string('vehicle_category', 12)->nullable()->index();
        });

        Offer::withoutGlobalScopes()->with(['brand', 'model', 'parkVehicle'])->chunkById(200, function ($offers) {
            foreach ($offers as $offer) {
                Offer::withoutGlobalScopes()->whereKey($offer->id)->toBase()->update(['vehicle_category' => $offer->guessCategory()->value]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $t) => $t->dropColumn('vehicle_category'));
    }
};
