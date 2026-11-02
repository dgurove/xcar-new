<?php

namespace Tests\Feature;

use App\Cars\Actions\FillCar;
use App\Cars\Brand;
use App\Cars\Category;
use App\Offers\Actions\AskHeavyEstimates;
use App\Offers\Actions\FirstLook;
use App\Offers\Actions\GiveEstimate;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\ReturnClosedToRated;
use App\Offers\AudienceRules;
use App\Offers\Estimate;
use App\Offers\MigtorgFields;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Грузовое — само на оценку своему менеджеру, его оценка — продажа только ему на пять дней, не подтвердил — всем
 * (02.11.2026, владелец). И тип ТС с Мигторга: «Граз» с кузовом «Прицеп» стоял легковым.
 */
class HeavyEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $roma;

    private User $other;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        Storage::fake('public');
        $this->roma = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000050', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->other = User::create(['name' => 'Иван', 'phone' => '79000000051', 'roles' => [Role::Manager], 'approved_at' => now()]);
        config(['xcar.heavy_estimator' => $this->roma->id]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
    }

    private function draft(string $brand, string $ref): Offer
    {
        $b = Brand::create(['slug' => mb_strtolower($brand), 'name' => $brand]);
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => $ref, 'brand_id' => $b->id, 'floor_price' => 900000]);
        $offer->addMedia(UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');

        return $offer->fresh();
    }

    public function test_truck_goes_to_estimator_once_and_first_look_opens_to_all(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00901/26');
        $car = $this->draft('Kia', '5691/046/00902/26');
        $this->assertSame(Category::Truck, $truck->category());
        $this->assertSame(Category::Passenger, $car->category());

        $this->assertSame(1, app(AskHeavyEstimates::class)());
        $this->assertSame(0, app(AskHeavyEstimates::class)());
        $estimate = Estimate::where('offer_id', $truck->id)->sole();
        $this->assertTrue($estimate->auto);
        $this->assertSame($this->roma->id, $estimate->user_id);
        $this->assertFalse(Estimate::where('offer_id', $car->id)->exists());

        app(GiveEstimate::class)($estimate, 1200000, $this->roma);
        $truck = $truck->fresh();
        $this->assertSame(OfferState::Open, $truck->state);
        $this->assertSame(1200000, $truck->asking_price);
        $this->assertNotNull($truck->first_look_until);
        $this->assertTrue($truck->first_look_until->equalTo($truck->bids_close_at));
        $this->assertSame([$this->roma->id], $truck->viewers()->pluck('user_id')->all());

        // Пять дней прошли без подтверждения: «Не продано» не забирает, открывается всем с новым сроком.
        $this->travelTo($truck->first_look_until->copy()->addMinute());
        $this->assertSame(0, app(ReturnClosedToRated::class)());
        $this->assertSame(1, app(FirstLook::class)->due());
        $truck = $truck->fresh();
        $this->assertSame(OfferState::Open, $truck->state);
        $this->assertNull($truck->first_look_until);
        $this->assertTrue($truck->bids_close_at->isFuture());
        $this->assertSame(AudienceRules::everyone(), AudienceRules::of($truck));
        $this->assertContains($this->other->id, $truck->viewers()->pluck('user_id')->all());
    }

    public function test_confirmed_first_look_stays_with_estimator(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00903/26');
        app(AskHeavyEstimates::class)();
        app(GiveEstimate::class)(Estimate::where('offer_id', $truck->id)->sole(), 1200000, $this->roma);
        app(PlaceBid::class)($truck->fresh(), $this->roma, 1200000);

        $this->travelTo($truck->fresh()->first_look_until->copy()->addMinute());
        app(FirstLook::class)->due();
        $truck = $truck->fresh();
        $this->assertNull($truck->first_look_until);
        $this->assertSame([$this->roma->id], $truck->viewers()->pluck('user_id')->all());
    }

    public function test_migtorg_trailer_beats_guessed_passenger(): void
    {
        $fields = MigtorgFields::of(['lot' => ['brand' => ['title' => 'Граз', 'car_type_id' => 4], 'model' => ['title' => 'Граз'], 'carcass' => ['title' => 'Прицеп']]]);
        $this->assertSame('trailer', $fields['category']['value']);
        $this->assertSame('long', MigtorgFields::of(['lot' => ['brand' => ['title' => 'Sitrak'], 'carcass' => ['title' => 'Седельный Тягач']]])['category']['value']);
        $this->assertSame('light', MigtorgFields::of(['lot' => ['brand' => ['title' => 'Луидор'], 'model' => ['title' => '3009Z6'], 'carcass' => ['title' => 'Грузовик']]])['category']['value']);

        // Черновик завели пустым, марка пришла позже — догадка «Легковой», её перебивает Мигторг.
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => 'Z691/046/13687/26']);
        app(FillCar::class)($offer, $fields, null, FillCar::EMPTY);
        $this->assertSame(Category::Trailer, $offer->fresh()->category());

        // Тип, выбранный человеком, Мигторг не трогает.
        $chosen = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => 'Z691/046/13688/26', 'brand_id' => Brand::where('name', 'Граз')->value('id'), 'vehicle_category' => Category::Special]);
        app(FillCar::class)($chosen, $fields, null, FillCar::EMPTY);
        $this->assertSame(Category::Special, $chosen->fresh()->category());
    }
}
