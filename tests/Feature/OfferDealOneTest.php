<?php

namespace Tests\Feature;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\CarLinks;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Предложение и сделка — одна машина (08.11.2026, владелец: «нашёл в поиске — открылось окошко предложения, жми ещё
 * „Сделка“ — бред»): у машины в сделке окошко списка предложений — окошко сделки, ссылки — одним пунктом «Сделка».
 */
class OfferDealOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_in_deal_opens_the_deal(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000040', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван Петров', 'phone' => '79000000041', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $sold = Offer::create(['vin' => 'XTA21099000000778', 'year' => 2024, 'asking_price' => 900000]);
        $sold->forceFill(['state' => OfferState::Sold])->save();
        $deal = Deal::create(['offer_id' => $sold->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 800000, 'amount' => 900000]);
        $draft = Offer::create(['vin' => 'XTA21099000000779', 'year' => 2023]);
        [$sold, $draft] = [$sold->fresh(), $draft->fresh()];
        $crm = 'http://'.config('xcar.crm_host');

        $this->assertSame($deal->id, $sold->fresh()->dealNow()?->id);
        $this->assertNull($draft->dealNow());
        $this->assertSame('/offers/'.$sold->number, $sold->crmPath());
        $this->assertSame('/offers/'.$draft->number, $draft->crmPath());

        // Окошко списка предложений — сделка (то же, что у «Сделок»), без сделки — предложение.
        $this->actingAs($admin)->get($crm.'/offers?preset=all&peek='.$sold->number, ['Turbo-Frame' => 'detail'])->assertOk()->assertSee('/work/deals/'.$deal->id, false)
            ->assertDontSee('tone="open" href="/work/deals/', false);
        $this->actingAs($admin)->get($crm.'/work/deals/'.$deal->id)->assertRedirect('/offers/'.$sold->number);

        // Меню связей у ТС парковки той же машины: один пункт «Сделка» вместо «Продажа» и «Сделка».
        $vehicle = \App\Park\Vehicle::forceCreate(['car_id' => $sold->car_id, 'state' => \App\Park\VehicleState::Expected]);
        $titles = array_column(CarLinks::for($vehicle->fresh()), 'title');
        $this->assertContains('Сделка', $titles);
        $this->assertNotContains('Продажа', $titles);
        $this->assertContains('Продажа', array_column(CarLinks::for(\App\Park\Vehicle::forceCreate(['car_id' => $draft->car_id, 'state' => \App\Park\VehicleState::Expected])->fresh()), 'title'));
    }
}
