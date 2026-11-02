<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Money;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Точно?» перед ценой продажи под вопросом (02.11.2026, владелец: Lexus RX 2610142042 — закупочная 3 200 000, вписали
 * продажу 1 200 000, опубликовали, менеджеры подтвердили по ней). Ошибка молчалива и стоит денег: цену без согласия не
 * пишем, публикацию не пускаем, подтверждение ниже закупочной — «Вы уверены?», даже по цене продажи.
 */
class PriceGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private User $admin;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000004', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 3200000])->fresh();
    }

    public function test_loss_price_needs_consent(): void
    {
        $n = $this->offer->number;
        $this->actingAs($this->admin)->post(self::CRM."/offers/{$n}/rate", ['asking_price' => '1 200 000'])
            ->assertSessionHasErrors(['asking_price', 'price_check']);
        $this->assertNull($this->offer->fresh()->asking_price);

        $this->actingAs($this->admin)->post(self::CRM."/offers/{$n}/rate", ['asking_price' => '1 200 000', 'price_ok' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1200000, $this->offer->fresh()->asking_price);
    }

    public function test_thin_and_typo_need_consent_normal_does_not(): void
    {
        $n = $this->offer->number;
        foreach (['3 240 000', '7 000 000'] as $price) {
            $this->actingAs($this->admin)->post(self::CRM."/offers/{$n}/rate", ['asking_price' => $price])
                ->assertSessionHasErrors('price_check');
        }
        $this->actingAs($this->admin)->post(self::CRM."/offers/{$n}/rate", ['asking_price' => '3 600 000'])->assertSessionHasNoErrors();
        $this->assertSame(3600000, $this->offer->fresh()->asking_price);
    }

    public function test_check_endpoint_tells_before_submit(): void
    {
        $n = $this->offer->number;
        $this->actingAs($this->admin)->postJson(self::CRM.'/offers/price-check', ['offer' => $n, 'asking_price' => '1 200 000'])
            ->assertOk()->assertJsonPath('warnings.0.title', 'Продать в минус на '.Money::rub(2000000).'?');
        $this->actingAs($this->admin)->postJson(self::CRM.'/offers/price-check', ['offer' => $n, 'asking_price' => '3 600 000'])
            ->assertOk()->assertJsonCount(0, 'warnings');
    }

    public function test_publishing_loss_needs_consent(): void
    {
        $this->offer->forceFill(['asking_price' => 1200000])->save();
        $n = $this->offer->number;
        $this->actingAs($this->admin)->postJson(self::CRM.'/offers/price-check', ['offers' => [$n]])->assertJsonCount(1, 'warnings');
        $this->actingAs($this->admin)->post(self::CRM.'/offers/schedule', ['offers' => [$n], 'when' => 'now'])
            ->assertSessionHasErrors('price_check');
        $this->assertSame(OfferState::Draft, $this->offer->fresh()->state);
        $this->actingAs($this->admin)->post(self::CRM."/offers/{$n}/state", ['state' => 'open'])
            ->assertSessionHasErrors('price_check');
        $this->assertSame(OfferState::Draft, $this->offer->fresh()->state);
    }

    public function test_bid_below_floor_asks_even_at_asking_price(): void
    {
        $this->offer->forceFill(['asking_price' => 1200000])->save();
        $offer = $this->offer->fresh();
        $this->assertSame('low', $offer->bidZone(1200000));
        $this->assertSame('low', $offer->bidZone(3210000));
        $this->assertSame([], $offer->bidDiscounts());

        $offer->forceFill(['asking_price' => 3800000])->save();
        $this->assertSame('ok', $offer->fresh()->bidZone(3800000));
    }
}
