<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\LaneEdit;
use App\Cars\LaneHistory;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Правка машины видна в истории обеих дорожек (`Cars\LaneHistory`): VIN, поправленный в CRM, — в деле ТС; цвет с
 * парковки — в истории предложения. Цены продажи на парковку не попадают.
 */
class LaneHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Offer $offer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $kia = Brand::firstOrCreate(['slug' => 'kia'], ['name' => 'Kia']);
        $this->offer = Offer::create(['claim_ref' => 'AUT-26-300001', 'floor_price' => 500000])->fresh();
        $this->vehicle = Vehicle::create(['ref' => 'AUT-26-300001', 'brand_id' => $kia->id, 'year' => 2020, 'state' => VehicleState::Stored]);
    }

    public function test_crm_edit_shows_in_vehicle_history_without_sale_fields(): void
    {
        app(UpdateOffer::class)($this->offer->fresh(), ['vin' => 'XW8ZZZ61ZJG061001', 'floor_price' => 650000], $this->admin);

        $rows = LaneHistory::forVehicle($this->vehicle->fresh());
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(LaneEdit::class, $rows[0]);
        $this->assertSame('в CRM', $rows[0]->where);
        $this->assertSame($this->admin->id, $rows[0]->user->id);
        $this->assertStringContainsString('Изменена: VIN XW8ZZZ61ZJG061001', $rows[0]->text());
        $this->assertStringNotContainsString('закупочн', $rows[0]->text(), 'цены продажи на парковку не попадают');
    }

    public function test_sale_only_edit_stays_out_of_vehicle_history(): void
    {
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 700000], $this->admin);

        $this->assertCount(0, LaneHistory::forVehicle($this->vehicle->fresh()));
    }

    public function test_park_edit_shows_in_offer_history_with_park_names_renamed(): void
    {
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий', 'ref' => 'AUT-26-300002'], $this->admin);

        $rows = LaneHistory::forOffer($this->offer->fresh());
        $this->assertCount(1, $rows);
        $this->assertSame('на парковке', $rows[0]->where);
        $this->assertStringContainsString('номер убытка AUT-26-300001 → AUT-26-300002', $rows[0]->text());
        $this->assertStringContainsString('цвет Синий', $rows[0]->text());
    }

    public function test_records_before_the_merge_are_not_doubled(): void
    {
        OfferEvent::create(['offer_id' => $this->offer->id, 'user_id' => $this->admin->id, 'type' => OfferEventType::Updated,
            'payload' => ['fields' => ['vin'], 'changes' => ['vin' => [null, 'XW8ZZZ61ZJG061001']]]])
            ->forceFill(['created_at' => '2026-10-14 20:00:00'])->save();

        $this->assertCount(0, LaneHistory::forVehicle($this->vehicle->fresh()), 'тогда строку в дело писало зеркало');
    }

    public function test_case_page_shows_crm_edit(): void
    {
        $this->admin->update(['roles' => [Role::Admin, Role::ParkAdmin]]);
        app(UpdateOffer::class)($this->offer->fresh(), ['year' => 2021], $this->admin);

        $this->actingAs($this->admin)->get('http://park.'.parse_url(config('app.url'), PHP_URL_HOST).'/cars/'.$this->vehicle->id)
            ->assertOk()->assertSee('Изменена: год 2020 → 2021')->assertSee('в CRM');
    }

    public function test_editor_shows_park_edit(): void
    {
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);

        $this->actingAs($this->admin)->get('http://crm.'.parse_url(config('app.url'), PHP_URL_HOST).'/offers/'.$this->offer->number)
            ->assertOk()->assertSee('Изменён: цвет Синий')->assertSee('на парковке');
    }
}
