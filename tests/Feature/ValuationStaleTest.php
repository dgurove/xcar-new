<?php

namespace Tests\Feature;

use App\Http\Admin\OfferController;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\Pricing;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Оценочная неактуальна» (15.11.2026, владелец по 0760/046/13173/26: «ждать цены от Альфы»): пункт «⋯» снимает оценочную и
 * закупочную от неё — черновик в «Без закупочной»; старая сумма (заявка из писем) не встаёт обратно, новая — встаёт.
 */
class ValuationStaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_value_moves_draft_to_no_floor_and_old_values_do_not_return(): void
    {
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'АльфаСтрахование тест', 'deal_format' => 'supplier', 'floor_by_value' => true]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'asking_price' => 3500000]);
        app(UpdateOffer::class)($offer, ['value' => 3001100, 'floor_price' => Pricing::floorFrom(3001100, Pricing::rulesFor($offer))], $admin, ['why' => 'тест']);
        $offer = $offer->fresh();
        $this->assertNotNull($offer->floor_price);
        $this->assertFalse(OfferController::scopeFor('nofloor', Offer::whereKey($offer->id))->exists());

        $this->actingAs($admin)->post(Surface::Crm->url('/offers/'.$offer->number.'/value-stale'))->assertRedirect();
        $offer = $offer->fresh();
        $this->assertNull($offer->value);
        $this->assertNull($offer->floor_price);
        $this->assertSame(3500000, (int) $offer->asking_price, 'цена продажи остаётся');
        $this->assertTrue(OfferController::scopeFor('nofloor', Offer::whereKey($offer->id))->exists(), 'в «Без закупочной»');

        // Заявка из писем старше снятия — не встаёт; новая оценочная — встаёт.
        app(UpdateOffer::class)($offer, ['value' => 3001100], null, ['source' => 'file', 'as_of' => now()->subDay()]);
        $this->assertNull($offer->fresh()->value, 'старая сумма не вернулась');
        app(UpdateOffer::class)($offer->fresh(), ['value' => 2700000], $admin, ['why' => 'По СМС Альфы']);
        $this->assertSame(2700000, (int) $offer->fresh()->value);
    }
}
