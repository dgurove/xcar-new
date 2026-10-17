<?php

namespace Tests\Feature;

use App\Billing\Invoice;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Call;
use App\Logistics\Actions\Docs;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\PickUp;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\CarPlace;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Вывоз — задача логистики (17.10.2026). Тихие дорогие ошибки: у Совкомбанка (обязаны вывезти каждую) задача не встала
 * вместе с предложением; счёт ПРАЙМ ушёл до «Забрал»; исполнитель без печати забрал машину без документов; «Забрал»
 * менеджера в логистике не провёл шаг сделки — счёт так и не встал.
 */
class PickupLogisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sovcombank_pickup_runs_through_logistics_and_unlocks_invoice(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Филипп Бородин', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission', 'pickup_on_arrival' => true]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);

        // Вендор встал — задача на вывоз «Без исполнителя».
        $offer = Offer::create(['claim_ref' => '473697/2026', 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer = app(UpdateOffer::class)($offer, ['vendor_id' => $vendor->id], $admin);
        $t = Transport::openFor($offer);
        $this->assertNotNull($t);
        $this->assertSame(TransportState::New, $t->state);
        $this->assertSame(CarPlace::Owner, $offer->fresh()->car_place);

        // Приняли подтверждение, забирает менеджер сделки — он исполнитель; счёта ещё нет.
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer->fresh(), Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $manager, 1100000);
        $this->actingAs($admin)->post('http://'.config('xcar.crm_host').'/confirmations/'.$bid->id.'/accept', ['pickup' => 'manager', 'commission' => 50000])
            ->assertSessionHasNoErrors();
        $offer->refresh();
        $deal = $offer->deal()->first();
        $t->refresh();
        $this->assertSame($manager->id, $t->executor_id);
        $this->assertTrue($t->docs_by_cdek, 'без печати — документы СДЭКом');
        $this->assertSame('Менеджер забирает автомобиль', $offer->stage()->name);
        $this->assertSame(0, Invoice::where('deal_id', $deal->id)->count());

        // Созвонились, но документов нет — «Забрал» нельзя.
        app(Call::class)->agree($t, ['pickup_at' => now()->addDay()->format('Y-m-d H:i'), 'pickup_place' => 'Самара, Ленина 1'], $manager);
        $this->addPhoto($t->fresh());
        try {
            app(PickUp::class)($t->fresh(), ['keys' => 1, 'destination' => 'keeper'], $manager);
            $this->fail('без документов не забирают');
        } catch (ValidationException) {
        }
        app(Docs::class)->send($t->fresh(), '1234 5678 90', $moderator);
        app(Docs::class)->receive($t->fresh(), $manager);

        // «Забрал» к себе: машина у него, шаг сделки прошёл сам — дальше оплата по счёту ПРАЙМ.
        app(PickUp::class)($t->fresh(), ['keys' => 1, 'destination' => 'keeper'], $manager);
        $t->refresh();
        $this->assertSame(TransportState::Delivered, $t->state);
        $this->assertSame(1, $offer->fresh()->keys_count);
        $this->assertSame(CarPlace::Keeper, $offer->fresh()->car_place);
        $this->assertSame('Оплата по счёту ПРАЙМ', $offer->fresh()->stage()->name);
    }

    public function test_logist_assigns_own_contractor_and_debt_goes_to_logist(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $logist = User::create(['name' => 'Татьяна', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $own = User::create(['name' => 'Пётр Исполнитель', 'phone' => '79000000005', 'roles' => [Role::Contractor], 'logist_id' => $logist->id, 'approved_at' => now()]);
        $stranger = User::create(['name' => 'Чужой Исполнитель', 'phone' => '79000000006', 'roles' => [Role::Contractor], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'pickup_on_arrival' => true]);
        $offer = app(UpdateOffer::class)(Offer::create(['claim_ref' => '473698/2026', 'floor_price' => 500000]), ['vendor_id' => $vendor->id], $admin);
        $t = Transport::openFor($offer);

        app(AssignExecutor::class)($t, Executor::Logist, $logist, $admin);
        $this->assertSame(TransportState::Logist, $t->fresh()->state);
        $this->assertTrue(Transport::visibleTo($logist)->whereKey($t->id)->exists());
        $this->assertFalse(Transport::visibleTo($own)->whereKey($t->id)->exists());

        try {
            app(LogistAssign::class)($t->fresh(), ['executor_id' => $stranger->id, 'cost' => '15 000'], $logist);
            $this->fail('чужого исполнителя логист не назначит');
        } catch (ValidationException) {
        }
        app(LogistAssign::class)($t->fresh(), ['executor_id' => $own->id, 'cost' => '15 000', 'distance_km' => 120], $logist);
        $t->refresh();
        $this->assertSame($own->id, $t->executor_id);
        $this->assertTrue($t->docs_by_cdek);
        $this->assertTrue(Transport::visibleTo($own)->whereKey($t->id)->exists());
        // Долг — логисту, не исполнителю.
        $this->assertSame($logist->party?->id ?? $logist->fresh()->party_id, $t->invoice->party_id);
        $this->assertSame(15000, (int) $t->invoice->total);
    }

    private function addPhoto(Transport $t): void
    {
        Media::forceCreate([
            'model_type' => $t->car->getMorphClass(), 'model_id' => $t->car_id, 'uuid' => (string) Str::uuid(), 'collection_name' => 'photos',
            'name' => 'priem', 'file_name' => 'priem.webp', 'mime_type' => 'image/webp', 'disk' => 'media', 'conversions_disk' => 'media', 'size' => 1,
            'manipulations' => [], 'custom_properties' => ['stage' => 'pickup', 'transport' => $t->id, 'at' => 'pickup'], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
    }
}
