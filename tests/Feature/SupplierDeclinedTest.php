<?php

namespace Tests\Feature;

use App\Notifications\SupplierDeclinedNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\DealLine;
use App\Offers\DealScheme;
use App\Offers\DealState;
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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Поставщик отказал» (28.10.2026, 812865/2026): сделка закрыта по причине, предложение в архиве с пометкой, менеджеру —
 * уведомление. Молчаливая ошибка тут — менеджер едет за машиной, которую продали без нас.
 */
class SupplierDeclinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_refusal_closes_deal_with_reason(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 540000, 'asking_price' => 1100000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1100000), $admin, 30000, CommissionMode::Payout, scheme: DealScheme::Prime);

        $this->actingAs($admin)->post("http://crm.xcar.localhost/work/deals/{$deal->id}/declined", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post("http://crm.xcar.localhost/work/deals/{$deal->id}/declined", ['note' => 'Поставщик продаёт сам', 'pickup' => 'keep'])
            ->assertSessionHasNoErrors();

        $deal->refresh();
        $this->assertSame(DealState::Cancelled, $deal->state);
        $this->assertTrue($deal->declinedBySupplier());
        $this->assertSame('Поставщик продаёт сам', $deal->close_note);
        $offer->refresh();
        $this->assertSame(OfferState::Cancelled, $offer->state);
        $this->assertSame('Архив, отказ поставщика', $offer->crmTab($admin));
        $this->assertSame('Отказ поставщика', DealLine::of($deal)->word);
        Notification::assertSentTo($manager, SupplierDeclinedNotice::class);
        // Второй раз закрыть нельзя.
        $this->actingAs($admin)->post("http://crm.xcar.localhost/work/deals/{$deal->id}/declined", ['note' => 'ещё'])->assertSessionHasErrors('note');
    }
}
