<?php

namespace Tests\Feature;

use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Вкладки CRM — по тому, когда предложение в них пришло (15.10.2026, владелец: оценил закупочную — и ищи машину внизу
 * «Без продажной», список шёл по заведению).
 */
class OfferTabOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_offer_that_just_got_a_floor_price_is_first_in_unpriced(): void
    {
        Queue::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->travel(-7)->days();
        $old = Offer::create(['claim_ref' => 'AUT-26-700001']);
        $this->travelBack();
        $this->travel(-1)->days();
        Offer::create(['claim_ref' => 'AUT-26-700002', 'floor_price' => 300000]);
        $this->travelBack();

        $this->assertSame($old->created_at->getTimestamp(), $old->fresh()->tab_at->getTimestamp(), 'новое — с заведения');
        app(UpdateOffer::class)($old->fresh(), ['description' => 'без смены шага'], $admin);
        $this->assertTrue($old->fresh()->tab_at->lt(now()->subDays(6)), 'правка без смены шага время не трогает');

        app(UpdateOffer::class)($old->fresh(), ['floor_price' => 500000], $admin);
        $this->assertTrue($old->fresh()->tab_at->gt(now()->subMinute()));

        $this->actingAs($admin)->get('http://crm.'.parse_url(config('app.url'), PHP_URL_HOST).'/?preset=unpriced&vid=table')
            ->assertOk()->assertSeeInOrder(['AUT-26-700001', 'AUT-26-700002'])->assertSee('Во вкладке');
    }
}
