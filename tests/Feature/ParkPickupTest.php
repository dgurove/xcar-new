<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Offers\Offer;
use App\Park\City;
use App\Park\Events\VehicleAccepted;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Машина у нас на стоянке или едет к нам — выдача по заявке, а не вывоз (13.11.2026, владелец: «зачем задача на вывоз, если тачка
 * у нас на стоянке стоит»): вывоз ей не заводится, а незабранный вывоз снимается, когда машину принимают.
 */
class ParkPickupTest extends TestCase
{
    use RefreshDatabase;

    /** ТС на парковке и продажа одной машины (`car_id` предложения массово не пишется — ставим до первого сохранения). */
    private function offer(VehicleState $state): array
    {
        $party = Party::create(['kind' => 'company', 'name' => 'АО «АльфаСтрахование»', 'inn' => '7713056834']);
        $vendor = Vendor::create(['name' => 'Альфа тест', 'party_id' => $party->id, 'kind' => Kind::Insurer]);
        $yard = Yard::create(['name' => 'Краснопутиловская', 'is_active' => true, 'settlement_id' => City::main()]);
        $vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'yard_id' => $yard->id, 'state' => $state,
            'accepted_at' => $state === VehicleState::Stored ? now() : null, 'ref' => 'Z691/046/00002/26', 'category' => 'passenger']);
        $offer = new Offer(['vendor_id' => $vendor->id, 'floor_price' => 500000]);
        $offer->forceFill(['car_id' => $vehicle->car_id])->save();

        return [$offer->fresh(), $vehicle];
    }

    public function test_no_pickup_for_car_coming_to_our_park(): void
    {
        Queue::fake();
        [$offer] = $this->offer(VehicleState::Expected);

        $this->expectException(ValidationException::class);
        app(OpenPickup::class)($offer->fresh(), null);
    }

    public function test_acceptance_cancels_open_pickup(): void
    {
        Queue::fake();
        [$offer, $vehicle] = $this->offer(VehicleState::Cancelled);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $t = app(OpenPickup::class)($offer, $admin);
        $this->assertSame(TransportState::New, $t->state);

        $vehicle->update(['state' => VehicleState::Stored, 'accepted_at' => now()]);
        VehicleAccepted::dispatch($vehicle->fresh(), null, $admin);

        $this->assertSame(TransportState::Cancelled, $t->fresh()->state);
        $this->assertNull(Transport::openFor($offer->fresh()));
        $this->assertTrue($offer->fresh()->onPark());
    }
}
