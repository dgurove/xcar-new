<?php

namespace Tests\Feature;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Сделка и расчёт — одна страница (19.10.2026, владелец: «менеджер путает страницу оплаты счёта и страницу сделки»):
 * старый адрес расчёта ведёт на сделку, `?pay=1` сохраняется; чужому — 404.
 */
class DealMoneyOnePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_url_redirects_to_deal(): void
    {
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $other = User::create(['name' => 'Другой', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer = Offer::create(['vin' => 'XTA21099000000777', 'year' => 2024, 'asking_price' => 900000]);
        $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 800000, 'amount' => 900000]);

        $this->actingAs($manager)->get('http://xcar.localhost/account/money/deals/'.$deal->id)->assertRedirect('/deals/'.$deal->id.'#money')->assertStatus(301);
        $this->actingAs($manager)->get('http://xcar.localhost/account/money/deals/'.$deal->id.'?pay=1')->assertRedirect('/deals/'.$deal->id.'?pay=1#money');
        $this->actingAs($manager)->get('http://xcar.localhost/deals/'.$deal->id)->assertOk()->assertSee('id="money"', false);
        $this->actingAs($other)->get('http://xcar.localhost/account/money/deals/'.$deal->id)->assertNotFound();
    }
}
