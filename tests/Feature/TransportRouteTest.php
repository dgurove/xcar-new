<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Actions\PickUp;
use App\Logistics\Actions\SetRoute;
use App\Logistics\Actions\StepBack;
use App\Logistics\Channel;
use App\Logistics\Depot;
use App\Logistics\Point;
use App\Logistics\Route;
use App\Logistics\Transport;
use App\Logistics\TransportKind;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Маршрут вывоза из отрезков (20.10.2026, владелец: «до стоянки довёз Иван, от стоянки до стоянки в другом регионе — Степан,
 * от стоянки до конечного пункта — Роман»): логист вставляет стоянки и ставит исполнителей заранее; исполнитель видит свой
 * отрезок только в свой ход; «Привёз» на стоянку открывает следующий; в таблице одна строка на машину; откат «Привёз»
 * возвращает следующий отрезок в «Впереди»; стоянку убирают, пока за ней ничего не начато; отмена снимает весь маршрут.
 */
class TransportRouteTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'http://logistics.xcar.localhost';

    private User $admin;

    private User $tanya;

    private User $ivan;

    private User $stepan;

    private User $roman;

    private Offer $offer;

    private Yard $yard;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->yard = Yard::create(['name' => 'Измайловский лес']);
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        foreach (['ivan' => 'Иван Петров', 'stepan' => 'Степан Кузин', 'roman' => 'Роман Орлов'] as $key => $name) {
            $this->{$key} = User::create(['name' => $name, 'phone' => '7900000001'.strlen($key), 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        }
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '483511/2024', 'floor_price' => 400000]);
    }

    private function depot(string $name): Depot
    {
        $this->actingAs($this->tanya)->postJson(self::LOG.'/people', ['kind' => 'depot', '_person' => ['name' => $name, 'contact_name' => 'Сторож', 'phone' => '+7 900 111-22-33']])->assertOk();

        return Depot::of($this->tanya)->where('name', $name)->firstOrFail();
    }

    /** Вывоз у Татьяны до парковки, с двумя стоянками: Киров и Москва. */
    private function route(): array
    {
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);
        app(AssignExecutor::class)($t, Channel::Logist, $this->tanya, $this->admin);
        app(SetRoute::class)($t->fresh(), ['destination' => 'yard:'.$this->yard->id, 'reach' => 'intercity'], $this->admin);
        [$kirov, $moscow] = [$this->depot('Киров'), $this->depot('Москва-Юг')];

        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/stops', ['depot_id' => $kirov->id])->assertSessionHasNoErrors();
        $second = Route::after($t->fresh());
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$second->id.'/stops', ['depot_id' => $moscow->id])->assertSessionHasNoErrors();

        return [Route::legs($t->fresh()), $kirov, $moscow];
    }

    private function assign(Transport $leg, User $who, string $cost, string $reach): void
    {
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$leg->id.'/logist', ['executor_id' => $who->id, 'cost' => $cost, 'reach' => $reach])
            ->assertSessionHasNoErrors();
    }

    public function test_three_legs_three_executors_one_row(): void
    {
        [$legs, $kirov, $moscow] = $this->route();
        $this->assertCount(3, $legs);
        [$one, $two, $three] = $legs->all();
        $this->assertSame([1, 2, 3], $legs->pluck('seq')->all());
        $this->assertSame(Point::Depot, $one->destination);
        $this->assertSame($kirov->id, $one->depot_id);
        $this->assertSame($kirov->id, $two->from_depot_id);
        $this->assertSame($moscow->id, $two->depot_id);
        $this->assertSame(Point::Yard, $three->destination);
        $this->assertSame(TransportState::Planned, $two->state);
        $this->assertSame(TransportKind::Move, $three->kind);

        $this->assign($one, $this->ivan, '5 000', 'local');
        $this->assign($two, $this->stepan, '40 000', 'intercity');
        $this->assign($three, $this->roman, '3 000', 'local');
        // Заранее назначенный отрезок «Впереди»: исполнитель его не видит, ссылки нет.
        $this->assertSame(TransportState::Planned, $two->fresh()->state);
        $this->assertNull($two->fresh()->trip_code);
        $this->assertFalse(Transport::visibleTo($this->stepan)->whereKey($two->id)->exists());
        // Одна строка на машину у логиста и у ведущих.
        $this->assertSame(1, Transport::listed($this->tanya)->count());
        $this->assertSame(1, Transport::listed($this->admin)->count());
        $this->assertSame($one->id, Transport::listed($this->admin)->first()->id);

        // Иван: забрал у владельца — машина в пути; привёз на стоянку — она там, очередь Степана.
        app(PickUp::class)($one->fresh(), ['keys' => 2], $this->admin);
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);
        app(Deliver::class)($one->fresh(), $this->admin);
        $this->assertSame(CarPlace::Depot, $this->offer->fresh()->car_place);
        $two = $two->fresh();
        $this->assertSame(TransportState::Assigned, $two->state);
        $this->assertTrue($two->current);
        $this->assertNotNull($two->trip_code);
        $this->assertTrue(Transport::visibleTo($this->stepan)->whereKey($two->id)->exists());
        $this->assertSame($two->id, $this->offer->fresh()->pickup()->id);
        $this->assertSame(1, Transport::listed($this->admin)->count());

        // Откат «Привёз» Ивана: Степан снова «Впереди», текущий — Иван в пути.
        app(StepBack::class)($two, $this->admin);
        $this->assertSame(TransportState::Planned, $two->fresh()->state);
        $this->assertSame(TransportState::Moving, $one->fresh()->state);
        $this->assertTrue($one->fresh()->current);
        app(Deliver::class)($one->fresh(), $this->admin);

        // Степан: со стоянки Киров до стоянки Москва; Роман: со стоянки до парковки.
        app(PickUp::class)($two->fresh(), [], $this->admin);
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);
        app(Deliver::class)($two->fresh(), $this->admin);
        $this->assertSame(TransportState::Assigned, $three->fresh()->state);
        app(PickUp::class)($three->fresh(), [], $this->admin);
        app(Deliver::class)($three->fresh(), $this->admin);
        $this->assertSame(CarPlace::Ours, $this->offer->fresh()->car_place);
        $this->assertTrue($three->fresh()->current);
        $this->assertSame(1, Transport::listed($this->admin)->step('done')->count());
        // Три отрезка — три долга логисту.
        $this->assertSame(3, Transport::where('route_id', $one->route_id)->whereNotNull('invoice_id')->count());
    }

    public function test_remove_stop_merges_legs_and_cancel_drops_route(): void
    {
        [$legs, , $moscow] = $this->route();
        [$one, $two] = $legs->all();
        $this->assign($two, $this->stepan, '40 000', 'intercity');

        // Москву убрали: Степан везёт из Кирова сразу на парковку.
        $this->actingAs($this->tanya)->delete(self::LOG.'/transports/'.$two->id.'/stop')->assertSessionHasNoErrors();
        $legs = Route::legs($one->fresh());
        $this->assertCount(2, $legs);
        $this->assertSame(Point::Yard, $legs[1]->destination);
        $this->assertSame($this->stepan->id, $legs[1]->executor_id);
        $this->assertSame([1, 2], $legs->pluck('seq')->all());

        // Отмена вывоза — и отрезки впереди.
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$one->id.'/cancel', ['reason' => 'не нужен'])->assertSessionHasNoErrors();
        $this->assertSame([TransportState::Cancelled, TransportState::Cancelled], Route::legs($one->fresh())->pluck('state')->all());
    }

    public function test_depot_on_the_go_continues_route(): void
    {
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);
        app(AssignExecutor::class)($t, Channel::Logist, $this->tanya, $this->admin);
        app(SetRoute::class)($t->fresh(), ['destination' => 'yard:'.$this->yard->id, 'reach' => 'local'], $this->admin);
        $this->assign($t->fresh(), $this->ivan, '5 000', 'local');
        app(PickUp::class)($t->fresh(), ['keys' => 1, 'destination' => 'yard:'.$this->yard->id], $this->admin);
        // Иван уже в пути — логист решила поставить машину на стоянку: отрезок делится, за стоянкой — впереди к парковке.
        $kirov = $this->depot('Киров');
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/stops', ['depot_id' => $kirov->id])->assertSessionHasNoErrors();
        app(Deliver::class)($t->fresh(), $this->admin);
        $next = Route::after($t->fresh());
        $this->assertSame(TransportState::Logist, $next->state);
        $this->assertSame(Point::Yard, $next->destination);
        $this->assertSame(1, Transport::listed($this->tanya)->step('logist')->count());
    }

    public function test_reorder_and_replace_stops_keep_executors_on_legs(): void
    {
        [$legs, $kirov, $moscow] = $this->route();
        [$one, $two, $three] = $legs->all();
        $this->assign($two, $this->stepan, '40 000', 'intercity');

        // Перетащили Москву перед Кировом: точки меняются, Степан остаётся на втором отрезке.
        $this->actingAs($this->tanya)->postJson(self::LOG.'/transports/'.$one->id.'/stops/order', ['order' => [$moscow->id, $kirov->id]])->assertOk();
        $legs = Route::legs($one->fresh());
        $this->assertSame([$moscow->id, $kirov->id], [$legs[0]->depot_id, $legs[1]->depot_id]);
        $this->assertSame($moscow->id, $legs[1]->from_depot_id);
        $this->assertSame($kirov->id, $legs[2]->from_depot_id);
        $this->assertSame($this->stepan->id, $legs[1]->executor_id);

        // Другая стоянка на месте Кирова.
        $vyatka = $this->depot('Вятка');
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$legs[1]->id.'/stop', ['depot_id' => $vyatka->id])->assertSessionHasNoErrors();
        $this->assertSame($vyatka->id, $legs[1]->fresh()->depot_id);
        $this->assertSame($vyatka->id, $legs[2]->fresh()->from_depot_id);

        // Машину довезли до первой стоянки — её уже не переставить.
        $this->assign($legs[0]->fresh(), $this->ivan, '5 000', 'local');
        app(PickUp::class)($legs[0]->fresh(), ['keys' => 1], $this->admin);
        app(Deliver::class)($legs[0]->fresh(), $this->admin);
        $this->actingAs($this->tanya)->postJson(self::LOG.'/transports/'.$one->id.'/stops/order', ['order' => [$vyatka->id, $moscow->id]])->assertStatus(422);
    }
}
