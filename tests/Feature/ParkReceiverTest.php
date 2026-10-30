<?php

namespace Tests\Feature;

use App\Park\City;
use App\Park\Pass;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Приёмщик парковки (27.10.2026, владелец: Роман в Москве и Никита в Питере «просто принимают и выдают», денег не видят).
 * Доступ и утечки: приёмщик выдаёт по QR и подтверждает покупателя, а ставки, начисления, счета и договор комиссии ему
 * не открываются ни страницей, ни столбцом. Всё остальное — его (30.10.2026, владелец: «всё, что не касается денег, —
 * свобода»): правит ТС и оценочную стоимость, но не ставку. Управляющий видит деньги, как раньше.
 */
class ParkReceiverTest extends TestCase
{
    use RefreshDatabase;

    private User $receiver;

    private User $manager;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $city = City::main();
        $yard = Yard::create(['name' => 'Измайловский лес', 'is_active' => true, 'settlement_id' => $city]);
        $vendor = Vendor::create(['name' => 'ВСК тест', 'release_by_qr' => true]);
        $this->vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored,
            'accepted_at' => now()->subDays(5), 'storage_rate' => 300, 'sold_at' => now()->subDay()]);
        $this->receiver = User::create(['name' => 'Роман Приёмщик', 'phone' => '79000000017', 'roles' => [Role::Manager, Role::Parking],
            'park_readonly' => true, 'park_city_id' => $city, 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Управляющий', 'phone' => '79000000041', 'roles' => [Role::Parking], 'park_city_id' => $city, 'approved_at' => now()]);
    }

    public function test_receiver_handles_release_by_qr(): void
    {
        $pass = Pass::create(['vehicle_id' => $this->vehicle->id, 'code' => 'TESTCODE0000000000AB', 'name' => 'Иван Покупатель', 'phone' => '+7 900 000-00-00', 'submitted_at' => now()]);

        $this->actingAs($this->receiver)->post(Surface::Park->url("/cars/{$this->vehicle->id}/buyer/confirm"))->assertRedirect();
        $this->assertTrue($pass->refresh()->isConfirmed());
        $this->actingAs($this->receiver)->postJson(Surface::Park->url("/cars/{$this->vehicle->id}/pass-check"), ['code' => $pass->code])
            ->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_receiver_sees_no_money(): void
    {
        $id = $this->vehicle->id;
        foreach (['/money', '/money/debts', '/cars/export', "/acts/{$id}/contract", "/cars/{$id}/invoices/new"] as $path) {
            $status = $this->actingAs($this->receiver)->get(Surface::Park->url($path))->status();
            $this->assertContains($status, [403, 404], "{$path} открыт приёмщику: {$status}");
        }
        $this->assertContains($this->actingAs($this->receiver)->post(Surface::Park->url("/cars/{$id}/rate"), ['storage_rate' => 1])->status(), [403, 404]);

        $list = $this->actingAs($this->receiver)->get(Surface::Park->url('/cars'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Начислено', $list);
        $this->assertStringNotContainsString('Ставка', $list);
        $this->assertStringNotContainsString('300 ₽', str_replace("\u{A0}", ' ', $list));
        $case = $this->actingAs($this->receiver)->get(Surface::Park->url("/cars/{$id}"))->assertOk()->getContent();
        $this->assertStringNotContainsString('₽/сут', $case);
        $this->assertStringNotContainsString('Выставить счёт', $case);

        // Управляющий — как раньше.
        $this->assertStringContainsString('Начислено', $this->actingAs($this->manager)->get(Surface::Park->url('/cars'))->getContent());
        $this->actingAs($this->manager)->get(Surface::Park->url('/money'))->assertOk();
    }

    public function test_receiver_edits_car_but_not_rate(): void
    {
        $this->actingAs($this->receiver)->put(Surface::Park->url("/cars/{$this->vehicle->id}"), ['value' => '1 250 000', 'storage_rate' => '1'])
            ->assertRedirect();

        $this->vehicle->refresh();
        $this->assertSame(1250000, (int) $this->vehicle->value);
        $this->assertEquals(300, $this->vehicle->storage_rate);
        $this->assertTrue($this->receiver->canManagePark());
    }

    public function test_manager_receiver_keeps_site_and_reaches_park(): void
    {
        $this->assertNull(Surface::onlyFor($this->receiver));
        $this->assertTrue(Surface::Park->opensFor($this->receiver));
        $this->assertFalse($this->receiver->parkMoney());
        $this->assertTrue($this->receiver->parkHandles());
    }
}
