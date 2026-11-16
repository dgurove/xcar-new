<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CarPlace;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
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
 * Шаг «Забрать автомобиль», а машина у нас (15.11.2026, 2610131943: привезли на нашу парковку, а менеджеру горело «Свяжитесь
 * с владельцем и заберите», «просрочено на 239д» по сгоревшему обязывающему). Шаг проходит сам; срок шага не берётся из
 * прошедшего срока обязывающего. Ошибка здесь молчаливая: менеджер копит ложную просрочку, сделка стоит.
 */
class PickupWithUsTest extends TestCase
{
    use RefreshDatabase;

    private function deal(?CarPlace $place, bool $burnt = false)
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Макаров Дмитрий', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open] + ($burnt ? ['binding_until' => now()->subMonths(8)] : []))->save();
        if ($place) {
            $offer->car_place = $place;
            $offer->save();
        }
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, CommissionMode::Payout, scheme: DealScheme::Prime)->fresh();
    }

    public function test_pickup_step_passes_itself_when_car_is_with_us(): void
    {
        $deal = $this->deal(CarPlace::Ours);
        $position = $deal->offer->fresh()->position(Track::Sale);
        $this->assertNotSame('Забрать автомобиль', $position->stage->name, 'машина у нас — шаг получения пройден');
        $this->assertFalse($deal->fresh()->requirements()->whereNull('done_at')->where('title', 'Забрать ТС')->exists());
    }

    public function test_pickup_step_waits_for_manager_without_burnt_binding_deadline(): void
    {
        $deal = $this->deal(null, burnt: true);
        $position = $deal->offer->fresh()->position(Track::Sale);
        $this->assertSame('Забрать автомобиль', $position->stage->name);
        $this->assertTrue($position->deadline_at === null || $position->deadline_at->isFuture(), 'срок не из прошедшего обязывающего');
    }
}
