<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Actions\SetRoute;
use App\Logistics\Channel;
use App\Logistics\Depot;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Вывоз через стоянку логиста (18.10.2026): Татьяна везёт сначала на свою стоянку — «куда дальше» не теряется; «Привёз» на
 * стоянку ставит машину туда и заводит следующий отрезок; его везёт другой исполнитель до конца; откат «Привёз» убирает
 * пустой отрезок; чужой логист стоянку не видит.
 */
class DepotLegsTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'http://logistics.xcar.localhost';

    private User $admin;

    private User $tanya;

    private User $ivan;

    private User $petr;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $this->ivan = User::create(['name' => 'Иван Петров', 'phone' => '79000000003', 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        $this->petr = User::create(['name' => 'Пётр Сидоров', 'phone' => '79000000004', 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '483511/2024', 'floor_price' => 400000]);
    }

    private function toDepot(): array
    {
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);
        app(AssignExecutor::class)($t, Channel::Logist, $this->tanya, $this->admin);
        app(SetRoute::class)($t->fresh(), ['destination' => 'ours', 'reach' => 'intercity'], $this->admin);

        // Новую стоянку Татьяна заводит прямо в шторке выбора.
        $this->actingAs($this->tanya)->postJson(self::LOG.'/people', ['kind' => 'depot', '_person' => ['name' => 'Киров, Северная', 'address' => 'ул. Северная 5', 'contact_name' => 'Сторож Николай', 'phone' => '+7 900 111-22-33']])
            ->assertOk();
        $depot = Depot::of($this->tanya)->firstOrFail();

        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/logist', ['executor_id' => $this->ivan->id, 'cost' => '40 000', 'leg' => 'depot', 'depot_id' => $depot->id])
            ->assertSessionHasNoErrors();

        return [$t->fresh(), $depot];
    }

    public function test_leg_to_depot_then_next_leg_to_final_point(): void
    {
        [$t, $depot] = $this->toDepot();
        $this->assertSame(Point::Depot, $t->destination);
        $this->assertSame(Point::Ours, $t->next_destination);
        $this->assertSame('Сторож Николай', $t->to_contact);
        $this->assertStringStartsWith('Стоянка Киров, Северная', $t->routeLabel());

        // Иван забрал и привёз на стоянку: машина на стоянке, заведён второй отрезок «У логиста».
        $this->actingAs($this->ivan)->post(self::LOG.'/transports/'.$t->id.'/call', ['pickup_at' => now()->addDay()->format('Y-m-d\TH:i'), 'pickup_place' => 'Киров'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/pickup', ['destination' => 'depot', 'keys' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->ivan)->post(self::LOG.'/transports/'.$t->id.'/deliver')->assertSessionHasNoErrors();
        $this->assertSame(CarPlace::Depot, $this->offer->fresh()->car_place);
        $leg = $t->fresh()->nextLeg;
        $this->assertNotNull($leg);
        $this->assertSame(TransportKind::Move, $leg->kind);
        $this->assertSame(TransportState::Logist, $leg->state);
        $this->assertSame($depot->id, $leg->from_depot_id);
        $this->assertSame(Point::Ours, $leg->destination);
        $this->assertSame('Сторож Николай', $leg->ownerName());
        $this->assertTrue(Transport::openFor($this->offer->fresh())->is($leg));
        $this->assertCount(2, $leg->chain());

        // Пётр везёт со стоянки к нам: машина у нас.
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$leg->id.'/logist', ['executor_id' => $this->petr->id, 'cost' => '25 000', 'leg' => 'route'])->assertSessionHasNoErrors();
        $this->actingAs($this->petr)->post(self::LOG.'/transports/'.$leg->id.'/pickup')->assertSessionHasNoErrors();
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);
        $this->actingAs($this->petr)->post(self::LOG.'/transports/'.$leg->id.'/deliver')->assertSessionHasNoErrors();
        $this->assertSame(CarPlace::WithUs, $this->offer->fresh()->car_place);
        $this->assertNull($leg->fresh()->nextLeg);
    }

    public function test_undo_delivered_to_depot_removes_empty_next_leg(): void
    {
        [$t] = $this->toDepot();
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/pickup', ['destination' => 'depot', 'keys' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/deliver')->assertSessionHasNoErrors();
        $this->assertNotNull($t->fresh()->nextLeg);

        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasNoErrors();
        $this->assertNull($t->fresh()->nextLeg);
        $this->assertSame(TransportState::Moving, $t->fresh()->state);
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);
    }

    public function test_back_to_route_restores_destination_and_other_logist_depot_refused(): void
    {
        [$t] = $this->toDepot();
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/logist', ['executor_id' => $this->ivan->id, 'cost' => '40 000', 'leg' => 'route'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(Point::Ours, $t->destination);
        $this->assertNull($t->depot_id);

        $other = User::create(['name' => 'Другой логист', 'phone' => '79000000005', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $foreign = Depot::create(['logist_id' => $other->id, 'name' => 'Чужая']);
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/logist', ['executor_id' => $this->ivan->id, 'cost' => '40 000', 'leg' => 'depot', 'depot_id' => $foreign->id])
            ->assertSessionHasErrors('depot_id');
    }
}
