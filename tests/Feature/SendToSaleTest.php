<?php

namespace Tests\Feature;

use App\Offers\Actions\CreateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\SendToSale;
use App\Park\Actions\UndoSendToSale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ТС с парковки уходит в продажу одним черновиком, сколько бы раз и как бы ни нажали: двойной клик, повтор пачки,
 * предложение с тем же убытком уже заведено. Ошибка тут молчалива и дорога (второе предложение по той же машине
 * расходится с парковкой), поэтому тест.
 */
class SendToSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'role' => Role::Admin, 'approved_at' => now()]);
    }

    private function stored(array $attrs = []): Vehicle
    {
        return Vehicle::create($attrs + ['ref' => 'AB-123', 'vin' => 'XTA210930Y2765432', 'state' => VehicleState::Stored, 'accepted_at' => now()->subDays(3)]);
    }

    public function test_repeated_send_keeps_one_offer(): void
    {
        $vehicle = $this->stored();

        $first = app(SendToSale::class)($vehicle, $this->admin);
        $second = app(SendToSale::class)($vehicle->fresh(), $this->admin);

        $this->assertSame(SendToSale::CREATED, $first['status']);
        $this->assertSame(SendToSale::ALREADY, $second['status']);
        $this->assertSame(1, Offer::count());
        $this->assertTrue($first['offer']->is($second['offer']));
        $this->assertSame($first['offer']->id, $vehicle->fresh()->offer_id);
        $this->assertSame(OfferState::Draft, $first['offer']->state);
        $this->assertSame(CarPlace::Ours, $first['offer']->fresh()->car_place);
    }

    public function test_existing_offer_with_same_claim_is_linked_not_duplicated(): void
    {
        $offer = app(CreateOffer::class)($this->admin, ['claim_ref' => 'ab123']);
        $vehicle = $this->stored();

        $result = app(SendToSale::class)($vehicle, $this->admin);

        $this->assertSame(SendToSale::LINKED, $result['status']);
        $this->assertTrue($result['offer']->is($offer));
        $this->assertSame(1, Offer::count());
    }

    public function test_only_stored_vehicle_goes_to_sale(): void
    {
        $vehicle = $this->stored(['state' => VehicleState::Expected, 'accepted_at' => null]);

        $this->expectException(ValidationException::class);
        app(SendToSale::class)($vehicle, $this->admin);
    }

    public function test_untouched_draft_is_withdrawn_with_vehicle_link(): void
    {
        $vehicle = $this->stored();
        app(SendToSale::class)($vehicle, $this->admin);

        app(UndoSendToSale::class)($vehicle->fresh(), $this->admin);

        $this->assertNull($vehicle->fresh()->offer_id);
        $this->assertSame(0, Offer::count());
    }

    public function test_draft_touched_in_crm_is_not_withdrawn(): void
    {
        $vehicle = $this->stored();
        $offer = app(SendToSale::class)($vehicle, $this->admin)['offer'];
        $offer->update(['asking_price' => 900000]);

        $this->expectException(ValidationException::class);
        app(UndoSendToSale::class)($vehicle->fresh(), $this->admin);
    }
}
