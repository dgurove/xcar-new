<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Actions\StepBack;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Notifications\PickupTakenNotice;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Вывоз менеджера сделки в логистике (19.10.2026, владелец: «хотят видеть все вывозы, админ/модератор снимает или меняет
 * исполнителя — тогда менеджер уже не сам вывозит»; ГАЗель 807360 нельзя было отдать логисту): ведущие видят его, снимают
 * менеджера или отдают логисту, менеджеру — «Вывоз передан в логистику»; логист такой задачи не видит.
 */
class ManagerPickupInLogisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_see_and_take_manager_pickup(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '807360/2026', 'floor_price' => 400000]);
        Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 400000, 'amount' => 500000]);
        $t = app(OpenPickup::class)($offer->fresh(), $admin, quiet: true);
        app(AssignExecutor::class)($t, Channel::Manager, $manager, $admin);
        $t = $t->fresh();

        $this->assertTrue(Transport::visibleTo($admin)->whereKey($t->id)->exists());
        $this->assertFalse(Transport::visibleTo($tanya)->whereKey($t->id)->exists());
        $this->assertSame('executor', StepBack::last($t));
        Notification::assertNotSentTo($manager, PickupTakenNotice::class);

        // Сняли менеджера — «Без исполнителя», ему уведомление.
        app(StepBack::class)($t, $admin);
        $t = $t->fresh();
        $this->assertSame(TransportState::New, $t->state);
        $this->assertNull($t->executor_id);
        Notification::assertSentTo($manager, PickupTakenNotice::class);

        // Снова менеджеру, потом сразу логисту — ещё одно уведомление.
        app(AssignExecutor::class)($t, Channel::Manager, $manager, $admin);
        app(AssignExecutor::class)($t->fresh(), Channel::Logist, $tanya, $admin);
        $this->assertSame(Channel::Logist, $t->fresh()->channel);
        Notification::assertSentToTimes($manager, PickupTakenNotice::class, 2);
    }

    public function test_second_owner_phone_reaches_logistics(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $offer = Offer::create(['claim_ref' => '807361/2026', 'floor_price' => 400000, 'insured_name' => 'Иван Владелец', 'insured_phone' => '+7 900 111-22-33']);
        $offer->forceFill(['state' => OfferState::Open, 'number' => 2610990001])->save();
        $this->actingAs($admin)->put('http://crm.xcar.localhost/offers/'.$offer->number, ['insured_phone2' => '+7 916 555-44-33', '_fields' => ['insured_phone2']])->assertRedirect();
        $this->assertSame('+7 916 555-44-33', $offer->fresh()->insured_phone2);
        $t = app(OpenPickup::class)($offer->fresh(), $admin, quiet: true);
        $this->assertSame('79165554433', $t->fresh()->ownerPhone2());
    }
}
