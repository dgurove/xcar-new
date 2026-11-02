<?php

namespace Tests\Feature;

use App\Http\Cabinet\DealController;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\PlaceBid;
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
use Tests\TestCase;

/**
 * «Ждут решения» в «Сделках» сайта (02.11.2026, владелец: «из подтверждённых пропало»): пока идёт приём — машина в
 * «Предложениях»; срок вышел — у подтвердивших в «Сделках», отдали другому — там же; отказ, завершение, архив — пропала.
 */
class DealsPendingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $roman;

    private User $denis;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->roman = User::create(['name' => 'Роман', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->denis = User::create(['name' => 'Денис', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        foreach ([$this->roman, $this->denis] as $m) {
            $offer->viewers()->firstOrCreate(['user_id' => $m->id], ['opens_at' => now()]);
        }
        $this->offer = $offer->fresh();
    }

    private function pending(User $u): array
    {
        return DealController::pending($u)->pluck('offer_id')->all();
    }

    private function onSale(User $u): bool
    {
        return Offer::siteList($u)->whereKey($this->offer->id)->exists();
    }

    public function test_after_deadline_bidders_see_it_in_deals_until_decided(): void
    {
        app(PlaceBid::class)($this->offer, $this->roman, 1000000);
        $denis = app(PlaceBid::class)($this->offer->fresh(), $this->denis, 990000);

        $this->assertTrue($this->onSale($this->roman));
        $this->assertSame([], $this->pending($this->roman));

        $this->travel(2)->days();
        $this->assertFalse($this->onSale($this->roman));
        $this->assertSame([$this->offer->id], $this->pending($this->roman));
        $this->assertSame([$this->offer->id], $this->pending($this->denis));
        $this->actingAs($this->roman)->get('http://xcar.localhost/deals')->assertOk()->assertSee('Ждут решения');

        // Роману — сделка, Денис — резерв: у него та же строка.
        $roman = $this->offer->bids()->where('user_id', $this->roman->id)->sole();
        app(AcceptBid::class)($roman, $this->admin);
        $this->assertSame([], $this->pending($this->roman));
        $this->assertSame([$this->offer->id], $this->pending($this->denis));

        // Отказ — пропало.
        app(DeclineBid::class)($denis->fresh(), $this->admin);
        $this->assertSame([], $this->pending($this->denis));
    }

    public function test_archive_drops_it(): void
    {
        app(PlaceBid::class)($this->offer, $this->roman, 1000000);
        $this->travel(2)->days();
        $this->assertSame([$this->offer->id], $this->pending($this->roman));
        app(ChangeOfferState::class)($this->offer->fresh(), OfferState::Archived, $this->admin);
        $this->assertSame([], $this->pending($this->roman));
    }
}
