<?php

namespace Tests\Feature;

use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidKind;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Закупочная — за каждым менеджером на момент его подтверждения (28.10.2026, владелец: 91720 — закупочную снизили
 * 529 206 → 333 333, а подтверждение Романа после этого взяло прежнюю и стало «ниже закупочной на 29 206»). При
 * принятии админ может вписать закупочную менеджера в сделке сам. Ошибка молчалива и стоит денег: счёт и ДКП — от неё.
 */
class BidCostTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private User $admin;

    private User $early;

    private User $late;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->early = User::create(['name' => 'Денис', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->late = User::create(['name' => 'Роман', 'phone' => '79000000017', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 529206, 'asking_price' => 670000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->offer = $offer->fresh();
    }

    public function test_cost_is_floor_at_each_managers_bid(): void
    {
        $early = PlaceBid::record($this->offer, $this->early, $this->early, 560000, null, BidKind::Buyer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 333333], $this->admin);
        $late = PlaceBid::record($this->offer->fresh(), $this->late, $this->admin, 500000, null, BidKind::Buyer);

        $this->assertSame(529206, $early->refresh()->cost);
        $this->assertSame(333333, $late->refresh()->cost);
        $this->assertSame(334000, $this->offer->fresh()->declaredPrice());

        // У Романа 500 000 выше своей закупочной — красного нет; у Дениса 560 000 при своей 529 206 — тоже нет.
        $page = $this->actingAs($this->admin)->get(self::CRM.'/offers/'.$this->offer->number)->assertOk()->getContent();
        $this->assertStringNotContainsString('ниже закупочной', $page);

        // Принятие Дениса кладёт в сделку его закупочную.
        $this->actingAs($this->admin)->post(self::CRM.'/confirmations/'.$early->id.'/accept', ['to' => 'deal'])->assertRedirect();
        $this->assertSame(529206, $this->offer->fresh()->deal->cost);
    }

    public function test_admin_sets_managers_cost_on_accept(): void
    {
        $bid = PlaceBid::record($this->offer, $this->early, $this->early, 560000, null, BidKind::Buyer);

        $this->actingAs($this->admin)->post(self::CRM.'/confirmations/'.$bid->id.'/accept', ['to' => 'deal', 'cost' => '400 000'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $deal = $this->offer->fresh()->deal;
        $this->assertSame(400000, $deal->cost);
        $this->assertSame(529206, $bid->refresh()->cost);
        $this->assertStringContainsString('закупочная менеджера 529', $this->offer->events()->where('type', 'bid_accepted')->first()->text());
    }

    public function test_cost_above_bid_needs_consent(): void
    {
        $bid = PlaceBid::record($this->offer, $this->early, $this->early, 560000, null, BidKind::Buyer);

        $this->actingAs($this->admin)->post(self::CRM.'/confirmations/'.$bid->id.'/accept', ['to' => 'deal', 'cost' => '600 000'])
            ->assertSessionHasErrors('give');
        $this->assertNull($this->offer->fresh()->deal);
    }
}
