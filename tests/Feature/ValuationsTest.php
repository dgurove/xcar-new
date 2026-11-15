<?php

namespace Tests\Feature;

use App\Mail\Extraction\Code;
use App\Mail\Extraction\DocumentFields;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\Pricing;
use App\Offers\Valuations;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Оценочная — самая свежая по дате источника (15.11.2026, 0790/046/10760/26: из текста 18.10 — 5 862 856, заявка Альфы
 * 23.10 — 3 033 000; заявку не читали, а оценку с парковки после Мигторга отбрасывали). Молчаливая и дорогая ошибка: закупочная
 * от старой оценочной вдвое выше.
 */
class ValuationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresher_wins_older_does_not_and_floor_follows(): void
    {
        $vendor = Vendor::create(['name' => 'Альфа тест', 'deal_format' => 'supplier', 'floor_by_value' => true]);
        $offer = Offer::create(['vendor_id' => $vendor->id]);
        $update = app(UpdateOffer::class);

        $update($offer, ['value' => 5862856], null, ['source' => 'valuation', 'as_of' => Carbon::parse('2026-10-18 14:01')]);
        $this->assertSame(Pricing::floorFrom(5862856, Pricing::rulesFor($offer->fresh())), $offer->fresh()->floor_price);

        $update($offer->fresh(), ['value' => 3033000], null, ['source' => 'file', 'as_of' => Carbon::parse('2026-10-23')]);
        $fresh = $offer->fresh();
        $this->assertSame([3033000, '2026-10-23'], [$fresh->value, $fresh->value_at->toDateString()]);
        $this->assertSame(Pricing::floorFrom(3033000, Pricing::rulesFor($fresh)), $fresh->floor_price);

        // Та старая СМС ещё раз — не встаёт: заявка свежее.
        $update($fresh, ['value' => 5862856, 'floor_price' => 6063000], null, ['source' => 'valuation', 'as_of' => Carbon::parse('2026-10-18 14:01')]);
        $this->assertSame(3033000, $offer->fresh()->value);
        $this->assertTrue(Valuations::older($offer->fresh(), Carbon::parse('2026-10-20')));
    }

    public function test_lot_after_valuation_makes_it_stale_and_new_one_after_lot_stands(): void
    {
        $vendor = Vendor::create(['name' => 'Альфа тест', 'deal_format' => 'supplier', 'floor_by_value' => true]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '0790/046/11111/26', 'asking_price' => 900000]);
        $update = app(UpdateOffer::class);
        // Заявка на приём 20.10 — оценочная встала.
        $update($offer, ['value' => 800000], null, ['source' => 'file', 'as_of' => Carbon::parse('2026-10-20')]);
        $this->assertSame(800000, $offer->fresh()->value);
        // 25.10 лот на Мигторге — оценочная заявки неактуальна: снимается с закупочной от неё, цена продажи остаётся.
        DB::table('migtorg_lots')->insert(['id' => 900001, 'claim_ref' => '0790/046/11111/26', 'claim_ref_key' => Code::key('0790/046/11111/26'),
            'title' => 'Тест', 'seen_at' => '2026-10-26', 'first_seen_at' => '2026-10-25']);
        $this->assertSame(1, Valuations::sweep());
        $fresh = $offer->fresh();
        $this->assertSame([null, null, 900000], [$fresh->value, $fresh->floor_price, $fresh->asking_price]);
        // Та же заявка от 20.10 снова (пересылка) — не встаёт.
        $update($fresh, ['value' => 800000], null, ['source' => 'file', 'as_of' => Carbon::parse('2026-10-20')]);
        $this->assertNull($offer->fresh()->value);
        // Новая оценка после лота (СМС, момент записи) — встаёт и не снимается.
        $update($offer->fresh(), ['value' => 600000], null, ['source' => 'valuation']);
        $this->assertSame(600000, $offer->fresh()->value);
        $this->assertSame(0, Valuations::sweep());
    }

    public function test_alfa_request_with_vin_glued_to_model_is_read_with_its_date(): void
    {
        $text = "к договору Е240020/06/03/22 от «16» февраля 2022 г.\nЗаявка на Приемку/Выдачу Товара № 0790/046/10760/26 от «23» октября 2026 года\n"
            ."Марка TC модификация VIN TC Цвет автомобиля Год Стоимость в соответствии c п.1.8, руб.\n"
            ."TOYOTA HIGHLANDERLVGEU76A4TG060116 Черный 2026 3.033.000,00 Энтузиастов, д.33";
        $fields = DocumentFields::extract($text);
        $this->assertSame(['LVGEU76A4TG060116', 3033000], [$fields['vin']['value'] ?? null, $fields['value']['value'] ?? null]);
        $this->assertTrue(DocumentFields::isRequest($text));
        $this->assertSame('2026-10-23', DocumentFields::dated($text)?->toDateString());
        $this->assertFalse(DocumentFields::isRequest('Акт приёма-передачи транспортного средства'));
    }
}
