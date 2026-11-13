<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\CreateRequest;
use App\Park\Actions\PutOnSale;
use App\Park\Actions\WithdrawSold;
use App\Park\City;
use App\Park\RequestType;
use App\Park\Sale;
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
 * Заявка на выдачу без нашей сделки — машина продана не через нас (13.11.2026, владелец): её продажа уходит из CRM в архив
 * (и опубликованная), в продажу с парковки она больше не встаёт; сделку и выдачу по сделке XCar это не трогает.
 */
class ParkSoldElsewhereTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $party = Party::create(['kind' => 'company', 'name' => 'АО «АльфаСтрахование»', 'inn' => '7713056834']);
        $this->vendor = Vendor::create(['name' => 'Альфа тест', 'party_id' => $party->id, 'kind' => Kind::Insurer, 'auto_sale' => true]);
        $yard = Yard::create(['name' => 'Краснопутиловская', 'is_active' => true, 'settlement_id' => City::main()]);
        $this->vehicle = Vehicle::create(['vendor_id' => $this->vendor->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored,
            'accepted_at' => '2026-11-01 10:00', 'vin' => 'XW8ZZZ61ZHG035221', 'ref' => 'Z691/046/00001/26', 'category' => 'passenger']);
    }

    private function offer(OfferState $state = OfferState::Draft): Offer
    {
        // Машина одна у ТС и продажи (`car_id` не заполняется массово — ставим сами).
        $offer = new Offer(['vendor_id' => $this->vendor->id, 'floor_price' => 500000,
            'asking_price' => $state === OfferState::Open ? 600000 : null, 'bids_close_at' => $state === OfferState::Open ? now()->addDay() : null]);
        $offer->forceFill(['car_id' => $this->vehicle->car_id])->save();
        $offer->forceFill(['state' => $state, 'published_at' => $state === OfferState::Open ? now() : null])->save();

        return $offer->fresh();
    }

    public function test_release_request_archives_published_offer_and_rejects_bids(): void
    {
        $offer = $this->offer(OfferState::Open);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $bid = Bid::create(['offer_id' => $offer->id, 'user_id' => $manager->id, 'amount' => 600000, 'state' => BidState::Active]);

        app(CreateRequest::class)(null, RequestType::Release, $this->vehicle, []);

        $this->assertSame(OfferState::Archived, $offer->fresh()->state);
        $this->assertNotSame(BidState::Active, $bid->fresh()->state);
    }

    public function test_draft_goes_to_archive_and_sweep_finds_released(): void
    {
        $offer = $this->offer();
        $this->vehicle->update(['state' => VehicleState::Released, 'released_at' => now()]);

        $this->assertSame(1, app(WithdrawSold::class)->sweep());
        $this->assertSame(OfferState::Archived, $offer->fresh()->state);
    }

    public function test_our_deal_release_is_not_touched(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['state' => OfferState::Sold])->save();

        app(CreateRequest::class)(null, RequestType::Release, $this->vehicle, []);

        $this->assertSame(OfferState::Sold, $offer->fresh()->state);
        $this->assertFalse(Sale::soldElsewhere($this->vehicle->fresh()));
    }

    /** Письмо «покупатель хочет осмотреть» по стоящей ТС — заявка «Осмотр», одна на ТС; «Осмотрели» её закрывает. */
    public function test_inspect_letter_opens_one_inspect_request(): void
    {
        $letter = new \App\Mail\Message(['intent' => \App\Mail\Extraction\Intent::Inspect->value, 'direction' => \App\Mail\Direction::In,
            'from_email' => 'buyer@example.ru', 'from_name' => 'Покупатель', 'date_at' => now()]);
        $first = app(\App\Park\Actions\InspectByLetter::class)($this->vehicle, $letter);
        $again = app(\App\Park\Actions\InspectByLetter::class)($this->vehicle->fresh(), $letter);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(RequestType::Inspect, $first->type);
        \App\Park\Request::closeOpen($this->vehicle, [RequestType::Inspect], User::create(['name' => 'Сергей', 'phone' => '79000000003', 'roles' => [Role::Parking], 'approved_at' => now()]), $first);
        $this->assertFalse($first->fresh()->isOpen());
    }

    public function test_sold_vehicle_does_not_go_on_sale(): void
    {
        app(CreateRequest::class)(null, RequestType::Release, $this->vehicle, []);

        $this->expectException(ValidationException::class);
        app(PutOnSale::class)($this->vehicle->fresh(), null);
    }
}
