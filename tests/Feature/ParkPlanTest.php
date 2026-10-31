<?php

namespace Tests\Feature;

use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Схема парковки (31.10.2026): смотреть и ставить ТС на места — все люди парковки, править ряды — администратор и
 * управляющие, не приёмщик. Молчаливо дорогое: место со стоящей ТС схема не убирает, переименованный ряд уносит ТС с собой.
 */
class ParkPlanTest extends TestCase
{
    use RefreshDatabase;

    private Yard $yard;

    private Vehicle $vehicle;

    private User $receiver;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $city = City::main();
        $this->yard = Yard::create(['name' => 'Измайловский лес', 'is_active' => true, 'settlement_id' => $city,
            'rows' => [['id' => 'a', 'name' => 'A', 'n' => 5, 'x' => 0, 'y' => 0]]]);
        $this->vehicle = Vehicle::create(['yard_id' => $this->yard->id, 'state' => VehicleState::Stored, 'spot' => 'A-2', 'accepted_at' => now()->subDays(3)]);
        $this->receiver = User::create(['name' => 'Приёмщик', 'phone' => '79000000017', 'roles' => [Role::Parking], 'park_readonly' => true, 'park_city_id' => $city, 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Управляющий', 'phone' => '79000000041', 'roles' => [Role::Parking], 'park_city_id' => $city, 'approved_at' => now()]);
    }

    private function savePlan(User $user, array $rows)
    {
        return $this->actingAs($user)->putJson(Surface::Park->url("/yards/{$this->yard->id}/plan"), ['rows' => $rows, 'rev' => $this->yard->refresh()->planRev()]);
    }

    public function test_receiver_sees_plan_but_does_not_edit_it(): void
    {
        $this->actingAs($this->receiver)->get(Surface::Park->url("/yards/{$this->yard->id}/plan"))->assertOk()->assertSee('A-2');
        $this->assertContains($this->actingAs($this->receiver)->get(Surface::Park->url("/yards/{$this->yard->id}/plan/edit"))->status(), [403, 404]);
        $this->assertContains($this->savePlan($this->receiver, [])->status(), [403, 404]);
        $this->assertCount(1, $this->yard->refresh()->plan());

        $this->actingAs($this->manager)->get(Surface::Park->url("/yards/{$this->yard->id}/plan/edit"))->assertOk();
    }

    public function test_plan_keeps_parked_cars(): void
    {
        $row = ['id' => 'a', 'name' => 'A', 'from' => 1, 'n' => 5, 'x' => 0, 'y' => 0, 'dir' => 'h', 'w' => 2.5, 'l' => 5, 'holes' => [], 'rev' => false];

        // Убрать место или ряд, где стоит ТС, нельзя (убранные места — по порядку в ряду: A-2 — второе, 1).
        $this->savePlan($this->manager, [['holes' => [1]] + $row])->assertStatus(422);
        $this->savePlan($this->manager, [])->assertStatus(422);
        $this->savePlan($this->manager, [['n' => 1] + $row])->assertStatus(422);

        // Ряд переименован — ТС на том же месте под новым кодом; вместимость — по схеме.
        $this->savePlan($this->manager, [['name' => 'B', 'holes' => [4]] + $row])->assertOk();
        $this->assertSame('B-2', $this->vehicle->refresh()->spot);
        $this->assertSame(4, $this->yard->refresh()->capacity);

        // Нумерация с другого конца: убранное место остаётся тем же местом на площадке, ТС — на своём месте под новым номером
        // (раньше убранное по номеру переезжало на место ТС, и та молча получала чужой код).
        $this->savePlan($this->manager, [['name' => 'B', 'holes' => [4], 'rev' => true] + $row])->assertOk();
        $this->assertSame('B-4', $this->vehicle->refresh()->spot);
        $this->assertSame(['B-2', 'B-3', 'B-4', 'B-5'], $this->yard->refresh()->spots());

        // Старая ревизия — отказ, а не перезапись чужой правки.
        $this->actingAs($this->manager)->putJson(Surface::Park->url("/yards/{$this->yard->id}/plan"), ['rows' => [$row], 'rev' => 'old'])->assertStatus(422);
        $this->assertSame('B-4', $this->vehicle->refresh()->spot);
    }

    public function test_rename_does_not_collide_with_free_text_spot(): void
    {
        // ТС записана на «B-1» свободным текстом до схемы; ряд A переименовывают в B — на B-1 встала бы вторая машина.
        Vehicle::create(['yard_id' => $this->yard->id, 'state' => VehicleState::Stored, 'spot' => 'B-1', 'accepted_at' => now()->subDay()]);
        $this->vehicle->update(['spot' => 'A-1']);
        $row = ['id' => 'a', 'name' => 'B', 'from' => 1, 'n' => 5, 'x' => 0, 'y' => 0, 'dir' => 'h', 'w' => 2.5, 'l' => 5, 'holes' => [], 'rev' => false];

        $this->savePlan($this->manager, [$row])->assertStatus(422)->assertJsonPath('errors.plan.0', fn ($m) => str_contains($m, 'B-1'));
        $this->assertSame('A-1', $this->vehicle->refresh()->spot);
    }
}
