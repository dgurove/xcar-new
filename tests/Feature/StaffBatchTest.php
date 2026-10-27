<?php

namespace Tests\Feature;

use App\Notifications\Batch;
use App\Notifications\BatchNotice;
use App\Notifications\ParkNotice;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Массовое сотрудникам — пачкой (27.10.2026, владелец: 100 уведомлений, когда у 100 предложений разом кончился приём,
 * «ебаный бред», админы отключали уведомления). Сорок разом — одно сводное; одно — как было.
 */
class StaffBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_forty_at_once_become_one_and_single_stays_as_is(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000004', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'ВСК тест']);
        $cars = collect(range(1, 40))->map(fn () => Vehicle::create(['vendor_id' => $vendor->id, 'state' => VehicleState::Stored, 'accepted_at' => now()->subDays(31)]));

        foreach ($cars as $v) {
            Batch::send([$admin], ParkNotice::idle($v, 31));
        }
        // Пока идут — не шлём.
        $this->assertSame(0, Batch::flush());
        Notification::assertNothingSent();

        $this->travel(3)->minutes();
        $this->assertSame(1, Batch::flush());
        Notification::assertSentToTimes($admin, BatchNotice::class, 1);
        Notification::assertSentTo($admin, BatchNotice::class, fn (BatchNotice $n) => str_contains($n->title(), '40 ТС') && str_contains((string) $n->text(), 'и ещё'));
        Notification::assertNotSentTo($admin, ParkNotice::class);

        // Одно — прежним уведомлением.
        Batch::send([$admin], ParkNotice::idle($cars->first(), 32));
        $this->travel(3)->minutes();
        Batch::flush();
        Notification::assertSentToTimes($admin, ParkNotice::class, 1);
        $this->assertDatabaseCount('notice_batch_items', 0);
    }
}
