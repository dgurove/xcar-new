<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метки по вендору (11.10.2026, начальник): вендору — метки, которые его предложения получают сами (`Offers\VendorTags`).
 * Стартовые связи — по меткам опубликованного за 14 дней, всё, что чаще половины (владелец); черновикам этих вендоров
 * недостающие дописываются один раз, опубликованные не трогаются.
 */
return new class extends Migration
{
    private const START = [
        'АльфаСтрахование' => ['Оплата по счету', 'Отсрочка оплаты'],
        'Т-Страхование' => ['Прямая сделка', 'Моментальное подтверждение'],
        'Совкомбанк Страхование' => ['Оплата по счету', 'Отсрочка оплаты', 'Моментальное подтверждение'],
        'carcade' => ['Оплата по счету', 'Отсрочка оплаты'],
    ];

    public function up(): void
    {
        Schema::create('tag_vendor', function (Blueprint $t) {
            $t->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $t->primary(['tag_id', 'vendor_id']);
        });

        $tags = DB::table('tags')->pluck('id', 'name');
        foreach (self::START as $vendor => $names) {
            $vendorId = $vendor === 'carcade'
                ? DB::table('vendors')->whereNull('crm_vendor_id')->whereRaw("name ilike '%carcade%' or name ilike '%каркаде%'")->value('id')
                : DB::table('vendors')->whereNull('crm_vendor_id')->where('name', $vendor)->value('id');
            $names = array_values(array_filter($names, fn ($n) => isset($tags[$n])));
            if (! $vendorId || ! $names) {
                continue;
            }
            DB::table('tag_vendor')->insert(array_map(fn ($n) => ['tag_id' => $tags[$n], 'vendor_id' => $vendorId], $names));

            DB::table('offers')->where('vendor_id', $vendorId)->where('state', 'draft')->orderBy('id')->each(function ($offer) use ($names) {
                $had = json_decode($offer->tags ?: '[]', true) ?: [];
                $all = array_values(array_unique([...$had, ...$names]));
                if (count($all) === count($had)) {
                    return;
                }
                DB::table('offers')->where('id', $offer->id)->update(['tags' => json_encode($all, JSON_UNESCAPED_UNICODE)]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_vendor');
    }
};
