<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Park\AcceptHints;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleEvent;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Фактическая дата приёма (02.11.2026, владелец: «многие даты приёма неверные, менять безумно неудобно»): подсказки дня из
 * писем, правка управляющим (раньше — только администратором), выставленный счёт правке не мешает, источник — в истории.
 * Тихая ошибка: от даты считается хранение.
 */
class AcceptHintsTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $yard = Yard::create(['name' => 'Шоссе Энтузиастов', 'is_active' => true, 'settlement_id' => City::main()]);
        $this->vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'yard_id' => $yard->id,
            'state' => VehicleState::Stored, 'accepted_at' => Carbon::parse('2026-10-20 15:00'), 'storage_billed_until' => Carbon::parse('2026-10-31')]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => '0790/046/01373/25', 'vehicle_id' => $this->vehicle->id]);
        foreach ([['in', 'intake', '2026-10-02 12:58'], ['out', 'accepted', '2026-10-06 10:11']] as [$dir, $intent, $at]) {
            Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => $dir, 'intent' => $intent, 'subject' => $thread->subject,
                'from_email' => $dir === 'in' ? 'a@alfastrah.ru' : 'storage@xcar.ru', 'date_at' => Carbon::parse($at)]);
        }
    }

    public function test_hints_from_vendor_request_and_our_report(): void
    {
        $hints = AcceptHints::for($this->vehicle);

        $this->assertSame(['Заявка 02.10', 'Отчёт 06.10'], array_column($hints, 'label'));
        $this->assertSame('2026-10-02 12:00', $hints[0]['at']->format('Y-m-d H:i'));
        $this->assertSame('request', $hints[0]['source']);
    }

    public function test_manager_fixes_date_despite_storage_invoice(): void
    {
        $manager = User::create(['name' => 'Управляющий', 'phone' => '79000000041', 'roles' => [Role::Parking], 'park_city_id' => City::main(), 'approved_at' => now()]);

        $this->actingAs($manager)->get(Surface::Park->url("/cars/{$this->vehicle->id}"))->assertOk()->assertSee('Заявка 02.10');
        $this->actingAs($manager)->post(Surface::Park->url("/cars/{$this->vehicle->id}/dates"), ['accepted_at' => '2026-10-02T12:00', 'source' => 'request'])
            ->assertSessionHas('toast', 'Дата приёма поправлена');

        $this->assertSame('2026-10-02 12:00', $this->vehicle->fresh()->accepted_at->format('Y-m-d H:i'));
        $event = VehicleEvent::where('vehicle_id', $this->vehicle->id)->where('type', EventType::DatesChanged)->first();
        $this->assertStringContainsString('по заявке', $event->text());
    }

    public function test_receiver_changes_date_too(): void
    {
        $receiver = User::create(['name' => 'Приёмщик', 'phone' => '79000000017', 'roles' => [Role::Parking], 'park_readonly' => true, 'park_city_id' => City::main(), 'approved_at' => now()]);

        $this->actingAs($receiver)->post(Surface::Park->url("/cars/{$this->vehicle->id}/dates"), ['accepted_at' => '2026-10-02T12:00'])->assertSessionHas('toast', 'Дата приёма поправлена');
        $this->assertSame('2026-10-02 12:00', $this->vehicle->fresh()->accepted_at->format('Y-m-d H:i'));
    }

    /** Продана, а не забирают: тег, задача и день забора, о котором договорились (02.11.2026). */
    public function test_pickup_late_until_date_agreed(): void
    {
        $manager = User::create(['name' => 'Управляющий', 'phone' => '79000000041', 'roles' => [Role::Parking], 'park_city_id' => City::main(), 'approved_at' => now()]);
        $this->vehicle->update(['sold_at' => now()->subDays(6)]);
        $late = fn () => Vehicle::with('requests')->find($this->vehicle->id)->pickupLate();

        $this->assertSame('не забирают 6 д', $late());
        $this->assertContains('pickup', \App\Park\Tasks::of([$this->vehicle->id])[$this->vehicle->id]);

        $this->actingAs($manager)->post(Surface::Park->url("/cars/{$this->vehicle->id}/pickup"), ['pickup_at' => now()->addDay()->format('Y-m-d\T12:00')])
            ->assertSessionHas('toast', 'Забор назначен');
        $this->assertNull($late());
        $this->assertNotContains('pickup', \App\Park\Tasks::of([$this->vehicle->id])[$this->vehicle->id]);

        \App\Park\Request::where('vehicle_id', $this->vehicle->id)->update(['planned_at' => now()->subDays(2)->setTime(12, 0)]);
        $this->assertSame('не забрали '.now()->subDays(2)->format('d.m'), $late());
    }
}
