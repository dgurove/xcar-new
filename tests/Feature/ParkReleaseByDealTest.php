<?php

namespace Tests\Feature;

use App\Notifications\ParkPassNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Handover;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\Intake;
use App\Park\Actions\Release;
use App\Park\Pass;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Выдача с нашей парковки по сделке XCar (18.10.2026, владелец): заявка и QR-пропуск встают сами, когда сделке можно
 * забирать; кто заберёт — решает менеджер; отменили сделку — пропуск гаснет. Ошибка здесь — машину отдали не тому или
 * парковка не знает, кому отдавать.
 */
class ParkReleaseByDealTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $keeper;

    private function deal(VehicleState $state = VehicleState::Stored): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер Тест', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->keeper = User::create(['name' => 'Пётр Управляющий', 'phone' => '79000000009', 'roles' => [Role::Parking], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay(),
            'insured_name' => 'Иван Страхователь', 'insured_phone' => '+79001112233']);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $yard = Yard::create(['name' => 'Измайловский лес', 'is_active' => true, 'address' => 'ул. Дорофеева, 2А']);
        $vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'yard_id' => $state === VehicleState::Stored ? $yard->id : null, 'state' => $state,
            'accepted_at' => $state === VehicleState::Stored ? now() : null]);
        DB::table('park_vehicles')->where('id', $vehicle->id)->update(['car_id' => $offer->car_id]);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::Insurer, ownerPrice: 900000);

        return [$offer->fresh(), $deal, $vehicle->fresh(), $yard];
    }

    /** Шагами маршрута до «Оплата получена»: согласие, «Беру в работу», платёжка, подтверждение. */
    private function paid(Offer $offer, Deal $deal): void
    {
        foreach (['Поставщик согласовал', 'Беру в работу', 'Ответ поставщика получен', 'Платёжное поручение приложено', 'Оплата получена'] as $label) {
            $o = $offer->fresh();
            $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal->fresh()));
            if (! $exit && $label === 'Поставщик согласовал') {
                continue;
            }
            $this->assertNotNull($exit, 'нет «'.$label.'» на «'.$o->stage()->name.'»');
            app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
        }
    }

    public function test_release_appears_when_paid_and_follows_receiver(): void
    {
        [$offer, $deal, $vehicle] = $this->deal();
        // До оплаты — ни продажи, ни заявки, ни пропуска.
        app(TakeExit::class)($offer, $offer->stage()->exits->first(fn ($e) => $e->label === 'Поставщик согласовал'), Actor::Staff, $this->admin);
        $this->assertNull($vehicle->fresh()->sold_at);
        $this->assertNull($vehicle->fresh()->dealPass());

        $this->paid($offer, $deal);
        $vehicle->refresh();
        $pass = $vehicle->dealPass();
        $this->assertNotNull($pass);
        $this->assertSame($this->manager->name, $pass->name);
        $this->assertTrue($pass->isConfirmed());
        $this->assertTrue($vehicle->releasesByQr());
        $request = $vehicle->requests()->where('type', RequestType::Release)->first();
        $this->assertSame($deal->id, $request->deal_id);
        $this->assertStringStartsWith('Сделка XCar', $request->source()['name']);
        Notification::assertSentTo($this->manager, ParkPassNotice::class);
        $h = Handover::for($deal->fresh());
        $this->assertSame('На парковке', $h->where);
        $this->assertStringContainsString('Пётр Управляющий', (string) $h->name);

        // Менеджер отдаёт покупателю: пропуск новый, прежний погашен, заявка с новым контактом.
        $deal->update(['receiver_name' => 'Иван Покупатель', 'receiver_phone' => '+79005556677']);
        app(\App\Offers\Actions\SyncParkRelease::class)($deal->fresh(), $this->manager);
        $this->assertNotNull($pass->fresh()->revoked_at);
        $fresh = $vehicle->fresh()->dealPass();
        $this->assertSame('Иван Покупатель', $fresh->name);
        $this->assertSame('Иван Покупатель', $request->fresh()->contact_name);
        $this->assertSame(1, $vehicle->requests()->where('type', RequestType::Release)->count());

        // На воротах — по этому пропуску: выдана, пропуск погашен, машина у менеджера, сделку можно закрыть.
        app(Release::class)($vehicle->fresh(), $this->keeper, now(), request: $request->fresh(), qr: ['pass' => $fresh->code]);
        $this->assertSame(VehicleState::Released, $vehicle->fresh()->state);
        $this->assertNotNull($fresh->fresh()->used_at);
        $this->assertNull($deal->fresh()->closeBlocker());
    }

    public function test_old_pass_is_refused_at_the_gate(): void
    {
        [$offer, $deal, $vehicle] = $this->deal();
        $this->paid($offer, $deal);
        $old = $vehicle->fresh()->dealPass();
        $deal->update(['receiver_name' => 'Иван Покупатель', 'receiver_phone' => '+79005556677']);
        app(\App\Offers\Actions\SyncParkRelease::class)($deal->fresh(), $this->manager);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(Release::class)($vehicle->fresh(), $this->keeper, now(), qr: ['pass' => $old->code]);
    }

    public function test_cancel_withdraws_release(): void
    {
        [$offer, $deal, $vehicle] = $this->deal();
        $this->paid($offer, $deal);
        $pass = $vehicle->fresh()->dealPass();
        app(CancelDeal::class)($deal->fresh(), $this->admin);
        $vehicle->refresh();
        $this->assertNotNull($pass->fresh()->revoked_at);
        $this->assertNull($vehicle->sold_at);
        $this->assertSame(0, $vehicle->requests()->where('type', RequestType::Release)->whereIn('state', \App\Park\RequestState::open())->count());
        $this->assertFalse($vehicle->releasesByQr());
    }

    public function test_expected_car_gets_release_on_intake(): void
    {
        [$offer, $deal, $vehicle, $yard] = $this->deal(VehicleState::Expected);
        $this->paid($offer, $deal);
        $vehicle->refresh();
        $this->assertNotNull($vehicle->dealPass());
        $this->assertSame(0, $vehicle->requests()->where('type', RequestType::Release)->count());
        $this->assertSame('Едет на парковку', Handover::for($deal->fresh())->where);

        $vehicle->update(['yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => now()]);
        \App\Park\Events\VehicleAccepted::dispatch($vehicle->fresh(), null, $this->keeper);
        $this->assertSame($deal->id, $vehicle->fresh()->requests()->where('type', RequestType::Release)->first()?->deal_id);
    }
}
