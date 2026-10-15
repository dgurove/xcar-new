<?php

namespace Tests\Feature;

use App\Cars\Settlement;
use App\Offers\MigtorgFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Город лота Мигторга (15.10.2026, AUT-26-904898 без города): «Кугульта» — село, таких два в разных регионах; без
 * региона его не было, а Мигторг регион отдаёт. «г Челябинск» — с типом.
 */
class SettlementResolveTest extends TestCase
{
    use RefreshDatabase;

    public function test_village_with_region_is_found_in_that_region(): void
    {
        // Справочник ОКТМО в базе тестов целиком: Кугульт две — в Ставропольском крае и в Ростовской области.
        $place = Settlement::find(Settlement::resolve('Кугульта', 'Ставропольский край')['id'] ?? null);
        $this->assertSame(['Кугульта', 26], [$place?->name, (int) $place?->region_code]);
    }

    public function test_village_without_region_is_not_guessed(): void
    {
        $this->assertNull(Settlement::resolve('Кугульта'));
    }

    public function test_typed_city_name(): void
    {
        $this->assertSame('Челябинск', Settlement::resolve('г Челябинск')['name'] ?? null);
    }

    public function test_migtorg_lot_keeps_region(): void
    {
        $fields = MigtorgFields::of(['lot' => ['city' => ['title' => 'Кугульта', 'region' => ['title' => 'Ставропольский край']]]]);

        $this->assertSame(['value' => 'Кугульта', 'region' => 'Ставропольский край'], $fields['city']);
    }
}
