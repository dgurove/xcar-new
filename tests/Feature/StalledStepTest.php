<?php

namespace Tests\Feature;

use App\Notifications\StalledStepNotice;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RemindStalled;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Шаг ждёт менеджера без срока (Совкомбанк «Менеджер забирает автомобиль», 18.10.2026: 15 сделок по 8 дней, никто не
 * напоминал) — мягкое напоминание раз в трое суток, днём. Не ушло бы — сделки висят молча; ушло бы чаще — спам.
 */
class StalledStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_is_reminded_every_three_days(): void
    {
        Storage::fake('private');
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00', 'Europe/Moscow'));
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Филипп Бородин', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission', 'pickup_on_arrival' => true]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = app(UpdateOffer::class)(Offer::create(['claim_ref' => '473700/2026', 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]), ['vendor_id' => $vendor->id], $admin);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer->fresh(), Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $manager, 1100000);
        $this->actingAs($admin)->post('http://'.config('xcar.crm_host').'/confirmations/'.$bid->id.'/accept', ['pickup' => 'manager', 'commission' => 50000]);
        $this->assertSame('Менеджер забирает автомобиль', $offer->fresh()->stage()->name);

        // Меньше трёх суток — тихо.
        $this->assertSame(0, app(RemindStalled::class)());
        Carbon::setTestNow(now()->addDays(3)->addMinute());
        $this->assertSame(1, app(RemindStalled::class)());
        Notification::assertSentTo($manager, StalledStepNotice::class, fn (StalledStepNotice $n) => str_starts_with($n->title(), 'Заберите автомобиль'));
        // Через минуту — не повторяется, ночью — молчит, через трое суток днём — снова.
        $this->assertSame(0, app(RemindStalled::class)());
        Carbon::setTestNow(Carbon::parse(now()->addDays(3)->format('Y-m-d').' 23:00', 'Europe/Moscow'));
        $this->assertSame(0, app(RemindStalled::class)());
        Carbon::setTestNow(now()->addHours(12));
        $this->assertSame(1, app(RemindStalled::class)());
    }
}
