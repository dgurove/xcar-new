<?php

namespace Tests\Feature;

use App\Billing\Invoice;
use App\Cars\Region;
use App\Cars\Settlement;
use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Call;
use App\Logistics\Actions\Docs;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\PickUp;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Logistics\TransportState;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertFalse($t->docs_by_cdek, 'менеджеру — без СДЭКа: покупатель забирает у страхователя сам');
        // Машина в регионе — админ ставит «Документы СДЭКом» сам, тогда без них не забрать.
        app(AssignExecutor::class)->cdek($t, true, $admin);
        $t->refresh();
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

    /**
     * Назначение пачкой (19.01.2027): регион задачи встаёт по городу предложения и идёт за его правкой, чип «Регион»
     * сужает «Без исполнителя», «Назначить (N)» отдаёт отмеченные логисту, повторная отмена закрытой не роняет пачку.
     */
    public function test_bulk_assign_by_region_and_cancel(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $logist = User::create(['name' => 'Татьяна', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'pickup_on_arrival' => true]);
        $samara = Region::forceCreate(['oktmo' => '99036', 'plate' => '63', 'name' => 'Тестовая Самарская область', 'short' => 'Самарская', 'match' => 'тестовая самарск']);
        $perm = Region::forceCreate(['oktmo' => '99057', 'plate' => '59', 'name' => 'Тестовый Пермский край', 'short' => 'Пермский', 'match' => 'тестовый пермск']);
        $city = Settlement::create(['name' => 'Тестсамара', 'type' => 'г', 'region_code' => '63', 'region_id' => $samara->id]);
        $pickup = function (string $ref, array $fields) use ($admin, $vendor) {
            $offer = app(UpdateOffer::class)(Offer::create(['claim_ref' => $ref, 'floor_price' => 500000] + $fields), ['vendor_id' => $vendor->id], $admin);

            return Transport::openFor($offer);
        };
        $a = $pickup('500001/2026', ['settlement_id' => $city->id]);
        $b = $pickup('500002/2026', ['settlement_id' => $city->id]);
        $c = $pickup('500003/2026', []);
        $this->assertSame($samara->id, $a->region_id);
        $this->assertNull($c->region_id);
        app(UpdateOffer::class)($c->offer, ['inspection_address' => 'Тестовый Пермский край, д. Ванюки'], $admin);
        $this->assertSame($perm->id, $c->fresh()->region_id, 'регион идёт за адресом предложения');

        $host = 'http://'.config('xcar.logistics_host');
        $this->actingAs($admin)->get($host.'/transports?preset=new&region='.$samara->id)->assertOk()
            ->assertSee('data-detail-key="'.$a->id.'"', false)->assertDontSee('data-detail-key="'.$c->id.'"', false)
            ->assertSee('Выбрать все');

        $this->actingAs($admin)->put($host.'/transports/executor', ['executor' => 'logist', 'who' => ['logist' => $logist->id], 'transports' => [$a->id, $b->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('toast', 'Назначено 2');
        $this->assertSame(TransportState::Logist, $a->fresh()->state);
        $this->assertSame($logist->id, $b->fresh()->coordinator_id);
        // Пачкой — одно уведомление логисту, а не по задаче.
        Notification::assertSentToTimes($logist, TransportNotice::class, 1);
        Notification::assertSentTo($logist, TransportNotice::class, fn (TransportNotice $n) => $n->title() === 'Вывозы в регионах: 2');
        // Негодный человек — одна ошибка у поля, а не «не вышло N».
        $closed = User::create(['name' => 'Закрытый Логист', 'phone' => '79000000009', 'roles' => [Role::Logist], 'approved_at' => now(), 'rejected_at' => now()]);
        $this->actingAs($admin)->put($host.'/transports/executor', ['executor' => 'logist', 'who' => ['logist' => $closed->id], 'transports' => [$c->id]])
            ->assertSessionHasErrors('executor_id');
        $this->assertSame(TransportState::New, $c->fresh()->state);

        $this->actingAs($admin)->post($host.'/transports/cancel', ['transports' => [$c->id], 'reason' => 'Дубль'])->assertSessionHas('toast', 'Вывоз отменён');
        $this->assertSame(TransportState::Cancelled, $c->fresh()->state);
        $this->actingAs($admin)->post($host.'/transports/cancel', ['transports' => [$c->id, $a->id]])->assertSessionHas('toast', fn ($t) => str_starts_with($t, 'Отменено 1, '));
        // Менеджера пачкой не назначают.
        $this->actingAs($admin)->put($host.'/transports/executor', ['executor' => 'manager', 'transports' => [$b->id]])->assertSessionHasErrors('executor');
        $this->actingAs($logist)->put($host.'/transports/executor', ['executor' => 'logist', 'who' => ['logist' => $logist->id], 'transports' => [$b->id]])->assertNotFound();
    }

    /** Напоминания: сутки у логиста — ему один раз; третье «Не дозвонился» — админам; сводка админам — раз в день. */
    public function test_stuck_tasks_remind_once(): void
    {
        Notification::fake();
        $this->travelTo(now('Europe/Moscow')->setTime(11, 0));
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $logist = User::create(['name' => 'Татьяна', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $driver = User::create(['name' => 'Иван', 'phone' => '79000000007', 'roles' => [Role::Driver], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'pickup_on_arrival' => true]);
        $open = fn (string $ref) => Transport::openFor(app(UpdateOffer::class)(Offer::create(['claim_ref' => $ref, 'floor_price' => 500000]), ['vendor_id' => $vendor->id], $admin));
        $open('600001/2026');
        $atLogist = $open('600002/2026');
        $called = $open('600003/2026');
        app(AssignExecutor::class)($atLogist, Executor::Logist, $logist, $admin);
        app(AssignExecutor::class)($called, Executor::Staff, $driver, $admin);

        $this->travel(25)->hours();
        Artisan::call('logistics:tick');
        Artisan::call('logistics:tick');
        Notification::assertSentToTimes($logist, TransportNotice::class, 2); // поручение и одно «Назначьте»
        Notification::assertSentTo($driver, TransportNotice::class, fn (TransportNotice $n) => str_starts_with($n->title(), 'Созвонитесь'));
        Notification::assertSentTo($admin, TransportNotice::class, fn (TransportNotice $n) => $n->title() === 'Вывоз ждёт: без исполнителя 1, без созвона 2');
        Notification::assertSentToTimes($admin, TransportNotice::class, 4); // три «новый вывоз» и одна сводка

        foreach (range(1, 3) as $i) {
            app(Call::class)->missed($called->fresh(), $driver);
        }
        Notification::assertSentTo($admin, TransportNotice::class, fn (TransportNotice $n) => str_starts_with($n->title(), 'Не дозвонились 3 раза'));
    }

    /** «Оплаты» логиста: видит свой долг и пишет реквизиты; чужие и исполнитель — нет. */
    public function test_logist_sees_own_debt_and_fills_requisites(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $logist = User::create(['name' => 'Татьяна', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $other = User::create(['name' => 'Другой Логист', 'phone' => '79000000008', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $own = User::create(['name' => 'Пётр Исполнитель', 'phone' => '79000000005', 'roles' => [Role::Contractor], 'logist_id' => $logist->id, 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'pickup_on_arrival' => true]);
        $t = Transport::openFor(app(UpdateOffer::class)(Offer::create(['claim_ref' => '700001/2026', 'floor_price' => 500000]), ['vendor_id' => $vendor->id], $admin));
        app(AssignExecutor::class)($t, Executor::Logist, $logist, $admin);
        app(LogistAssign::class)($t->fresh(), ['executor_id' => $own->id, 'cost' => '17 500', 'distance_km' => 140], $logist);

        $host = 'http://'.config('xcar.logistics_host');
        $logist->refresh(); // контрагента завёл `SettleCost` на другом экземпляре
        $this->actingAs($logist)->get($host.'/money')->assertOk()->assertSee('Ждут выплаты')->assertSee('140 км')->assertSee('не указаны');
        $this->actingAs($other)->get($host.'/money')->assertOk()->assertDontSee('140 км');
        $this->actingAs($own)->get($host.'/money')->assertNotFound();
        $this->actingAs($logist)->put($host.'/money/'.$logist->id.'/party', ['kind' => 'person', 'name' => 'Татьяна Логист', 'card' => '2200 0000 0000 0000'])->assertSessionHasNoErrors();
        $this->assertSame('2200000000000000', $logist->fresh()->party->card);
        $this->actingAs($logist)->put($host.'/money/'.$other->id.'/party', ['kind' => 'person', 'name' => 'Чужая'])->assertNotFound();
        $this->actingAs($admin)->get($host.'/money')->assertOk()->assertSee('Другой Логист')->assertSee('140 км');
    }

    /**
     * «Документы СДЭКом» — выбор, а не всем (17.10.2026: тестовому менеджеру по Т-Страхованию встало «ждём документы»):
     * менеджеру и нашим — без СДЭКа, логисту — со СДЭКом, и он доживает до его исполнителя; снятая галка — без.
     */
    public function test_cdek_only_by_choice(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $logist = User::create(['name' => 'Татьяна', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $own = User::create(['name' => 'Пётр Исполнитель', 'phone' => '79000000005', 'roles' => [Role::Contractor], 'logist_id' => $logist->id, 'approved_at' => now()]);
        $driver = User::create(['name' => 'Иван', 'phone' => '79000000007', 'roles' => [Role::Driver], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'pickup_on_arrival' => true]);
        $open = fn (string $ref) => Transport::openFor(app(UpdateOffer::class)(Offer::create(['claim_ref' => $ref, 'floor_price' => 500000]), ['vendor_id' => $vendor->id], $admin));

        $staff = app(AssignExecutor::class)($open('800001/2026'), Executor::Staff, $driver, $admin);
        $this->assertFalse($staff->fresh()->docs_by_cdek, 'нашим — без СДЭКа');

        $regional = app(AssignExecutor::class)($open('800002/2026'), Executor::Logist, $logist, $admin);
        $this->assertTrue($regional->fresh()->docs_by_cdek, 'логисту — СДЭКом по умолчанию');
        app(LogistAssign::class)($regional->fresh(), ['executor_id' => $own->id, 'cost' => '10 000'], $logist);
        $this->assertTrue($regional->fresh()->docs_by_cdek, 'галка доживает до исполнителя логиста');
        $this->assertTrue(Transport::awaitingDocs()->whereKey($regional->id)->exists());

        // Форма: галка вида снята — без СДЭКа и у логиста.
        $host = 'http://'.config('xcar.logistics_host');
        $t = $open('800003/2026');
        $this->actingAs($admin)->put($host.'/transports/'.$t->id.'/executor', ['executor' => 'logist', 'who' => ['logist' => $logist->id], 'cdek' => ['logist' => '0']])
            ->assertSessionHasNoErrors();
        $this->assertFalse($t->fresh()->docs_by_cdek);
    }

    /** Короткие имена логистики (`go.`, `log.`, `l.`) — 301 туда же с путём и запросом. */
    public function test_short_hosts_redirect_to_logistics(): void
    {
        $site = Surface::Site->host();
        foreach (['go', 'log', 'l'] as $alias) {
            $this->get('http://'.$alias.'.'.$site.'/transports?preset=new')
                ->assertStatus(301)->assertRedirect(Surface::Logistics->url('/transports').'?preset=new');
        }
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
