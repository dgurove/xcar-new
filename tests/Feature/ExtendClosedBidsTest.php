<?php

namespace Tests\Feature;

use App\Http\Admin\OfferController;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TickStages;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Продлили срок приёма у закрытого (17.10.2026, начальник: машина висела в «Приём закрыт», надо было обновлять страницу и
 * жать «Возобновить приём»): маршрут сам снова в приёме, предложение — в «Опубликованных».
 */
class ExtendClosedBidsTest extends TestCase
{
    use RefreshDatabase;

    public function test_extending_closed_bids_resumes_bidding(): void
    {
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addHour()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $stages = Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id);
        app(EnterStage::class)($offer, (clone $stages)->where('name', 'Приём подтверждений')->firstOrFail());

        $this->travel(2)->hours();
        app(TickStages::class)();
        $this->assertSame('Выбор подтверждения', $offer->fresh()->position(Track::Sale)->stage->name);
        $this->assertTrue(OfferController::scopeFor('closed', Offer::whereKey($offer->id))->exists());

        app(UpdateOffer::class)($offer->fresh(), ['bids_close_at' => now()->addDay()], $admin);

        $this->assertSame('Приём подтверждений', $offer->fresh()->position(Track::Sale)->stage->name);
        $this->assertTrue(OfferController::scopeFor('published', Offer::whereKey($offer->id))->exists());
        $this->assertFalse(OfferController::scopeFor('closed', Offer::whereKey($offer->id))->exists());
    }
}
