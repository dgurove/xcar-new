<?php

namespace Tests\Feature;

use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Party;
use App\Park\Actions\ReleasePast;
use App\Park\City;
use App\Park\Jobs\PurgeReleasedLetters;
use App\Park\LettersPurge;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Файлы из писем выданной ТС (06.11.2026, владелец: акты приёма и выдачи нужны для сверки счёта и акта за месяц) стираются не
 * при выдаче, а когда месяц выдачи закрыт и оплачен (`LettersPurge::due`).
 */
class LettersPurgeTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 12:00');
        $party = Party::create(['kind' => 'company', 'name' => 'АО «СОГАЗ»', 'inn' => '7736035485']);
        $this->vendor = Vendor::create(['name' => 'СОГАЗ тест', 'party_id' => $party->id, 'kind' => Kind::Insurer]);
        $yard = Yard::create(['name' => 'Дорофеева', 'is_active' => true, 'settlement_id' => City::main()]);
        $this->vehicle = Vehicle::create(['vendor_id' => $this->vendor->id, 'yard_id' => $yard->id, 'state' => VehicleState::Released,
            'accepted_at' => '2026-09-01 10:00', 'released_at' => '2026-10-15 12:00', 'vin' => 'XW8ZZZ61ZHG035220', 'ref' => 'SGZA-1', 'category' => 'passenger']);
    }

    private function invoice(string $state): void
    {
        $id = DB::table('billing_invoices')->insertGetId(['seller' => 'park', 'code' => '20261101-01', 'direction' => 'issued', 'kind' => 'storage',
            'party_id' => $this->vendor->party_id, 'issued_at' => now(), 'state' => $state, 'total' => 4500, 'created_at' => now(), 'updated_at' => now()]);
        Charge::create(['party_id' => $this->vendor->party_id, 'vehicle_id' => $this->vehicle->id, 'invoice_id' => $id, 'kind' => ChargeKind::Storage,
            'title' => 'Хранение', 'qty' => 45, 'unit' => 'day', 'price' => 100, 'amount' => 4500, 'period_from' => '2026-09-01', 'period_to' => '2026-10-15']);
        $this->vehicle->update(['storage_billed_until' => '2026-10-15']);
    }

    public function test_release_does_not_purge(): void
    {
        Queue::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
        $stored = Vehicle::create(['vendor_id' => $this->vendor->id, 'state' => VehicleState::Stored, 'accepted_at' => '2026-10-01 10:00', 'vin' => 'XW8ZZZ61ZHG035221', 'category' => 'passenger']);
        app(ReleasePast::class)($stored, $admin, now(), null, []);
        Queue::assertNotPushed(PurgeReleasedLetters::class);
    }

    public function test_due_only_when_release_month_closed_and_paid(): void
    {
        $this->assertFalse(LettersPurge::due($this->vehicle), 'не выставлено');
        $this->invoice('issued');
        $this->assertFalse(LettersPurge::due($this->vehicle->fresh()), 'не оплачено');
        DB::table('billing_invoices')->update(['state' => 'paid']);
        $this->assertTrue(LettersPurge::due($this->vehicle->fresh()));
        $this->vehicle->update(['released_at' => '2026-11-02 12:00', 'storage_billed_until' => '2026-11-02']);
        $this->assertFalse(LettersPurge::due($this->vehicle->fresh()), 'месяц выдачи не кончился');
    }

    public function test_own_vendor_after_month_end(): void
    {
        $this->vendor->update(['kind' => Kind::Own]);
        $this->assertTrue(LettersPurge::due($this->vehicle->fresh()));
    }
}
