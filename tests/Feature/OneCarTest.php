<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\IdentityTaken;
use App\Logistics\Transport;
use App\Mail\Account;
use App\Mail\Extraction\Code;
use App\Mail\Thread;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\DeleteDraft;
use App\Offers\Actions\MergeOffers;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\PutOnSale;
use App\Park\Actions\UpdateVehicle;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Purchases\Actions\MoveToOffers;
use App\Purchases\Purchase;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Машина — одна запись на всё приложение (14.10.2026): продажа, хранение и лот закупки — её дорожки, тождество, фото и
 * документы — у неё. Ошибка здесь — двойник машины или её фото у одной дорожки и нет у другой.
 */
class OneCarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
    }

    private function photo(Car $car, string $sha, array $props = []): Media
    {
        return Media::create(['model_type' => Car::class, 'model_id' => $car->id, 'collection_name' => 'photos', 'name' => 'кадр', 'file_name' => $sha.'.jpg',
            'mime_type' => 'image/jpeg', 'disk' => 'media', 'size' => 10, 'manipulations' => [], 'custom_properties' => ['sha' => $sha] + $props,
            'generated_conversions' => [], 'responsive_images' => []]);
    }

    public function test_offer_and_vehicle_with_one_claim_are_one_car_with_shared_photos(): void
    {
        $kia = Brand::firstOrCreate(['slug' => 'kia'], ['name' => 'Kia']);
        $offer = Offer::create(['claim_ref' => 'AUT-26-100001', 'floor_price' => 500000]);
        $vehicle = Vehicle::create(['ref' => 'aut 26 100001', 'brand_id' => $kia->id, 'year' => 2020, 'state' => VehicleState::Stored, 'plate' => 'а123вс 77']);

        $this->assertSame($offer->car_id, $vehicle->car_id);
        $this->assertSame(1, Car::count());
        $this->assertSame($vehicle->id, $offer->fresh()->parkVehicle->id);
        $this->assertSame($offer->id, $vehicle->fresh()->offer->id);
        $this->assertSame('Kia', $offer->fresh()->brand->name, 'марка, вписанная парковкой, — у продажи');
        $this->assertSame('А123ВС77', $offer->fresh()->plate);

        $car = $vehicle->carRecord();
        $this->photo($car, 'vendor', ['stage' => 'vendor']);
        $this->photo($car, 'intake', ['stage' => 'intake', 'park' => true]);
        $this->photo($car, 'release', ['stage' => 'release', 'park' => true]);
        $this->assertSame(['vendor', 'intake'], $offer->fresh()->photos()->map(fn ($m) => $m->getCustomProperty('sha'))->all(), 'продаже — без выдачи');
        $this->assertCount(3, $vehicle->fresh()->photos(), 'парковка видит все кадры');
        $this->assertTrue(Sale::parkOwned($vehicle->fresh()->photos()->last()));
        $this->assertTrue($car->fresh()->active);
    }

    public function test_new_offer_fills_only_empty_fields_of_parked_car(): void
    {
        $kia = Brand::firstOrCreate(['slug' => 'kia'], ['name' => 'Kia']);
        $bmw = Brand::firstOrCreate(['slug' => 'bmw'], ['name' => 'BMW']);
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100002', 'brand_id' => $kia->id, 'state' => VehicleState::Expected]);
        $offer = app(CreateOffer::class)($this->admin, ['claim_ref' => 'AUT-26-100002', 'brand_id' => $bmw->id, 'year' => 2019]);

        $this->assertSame($vehicle->car_id, $offer->car_id);
        $this->assertSame('Kia', $offer->brand->name, 'тождество держит парковка');
        $this->assertSame(2019, $vehicle->fresh()->year, 'пустое дополнилось');
    }

    public function test_second_sale_of_one_car_is_a_field_error(): void
    {
        $first = Offer::create(['claim_ref' => 'AUT-26-100003']);
        $second = Offer::create(['claim_ref' => 'AUT-26-100004']);
        try {
            app(UpdateOffer::class)($second, ['claim_ref' => 'AUT-26-100003'], $this->admin);
            $this->fail('вторая продажа одной машины');
        } catch (IdentityTaken $e) {
            $this->assertArrayHasKey('claim_ref', $e->errors());
            $this->assertTrue($e->holder->is($first));
        }
        $this->assertSame('AUT-26-100004', $second->fresh()->claim_ref);
        $this->assertSame(2, Car::count());
    }

    public function test_vehicle_with_taken_vin_and_other_claim_is_a_field_error_named_for_park(): void
    {
        Offer::create(['claim_ref' => 'AUT-26-100005', 'vin' => 'XTA21099071234567']);
        try {
            Vehicle::create(['ref' => 'AUT-26-999999', 'vin' => 'XTA21099071234567', 'state' => VehicleState::Expected]);
            $this->fail('VIN живой машины у другой');
        } catch (IdentityTaken $e) {
            $this->assertArrayHasKey('vin', $e->errors());
        }
        // Без номера — та же машина.
        $vehicle = Vehicle::create(['vin' => 'XTA21099071234567', 'state' => VehicleState::Expected]);
        $this->assertSame(Offer::first()->car_id, $vehicle->car_id);
    }

    public function test_closed_car_vin_does_not_block_new_case_but_revival_does(): void
    {
        $old = Offer::create(['claim_ref' => 'AUT-25-000001', 'vin' => 'XTA21099071234568']);
        $old->forceFill(['state' => OfferState::Archived])->save();
        $this->assertFalse($old->carRecord()->fresh()->active);

        $new = Offer::create(['claim_ref' => 'AUT-26-000002', 'vin' => 'XTA21099071234568']);
        $this->assertNotSame($old->car_id, $new->car_id, 'новое ДТП — новая запись');

        $this->expectException(IdentityTaken::class);
        $old->fresh()->forceFill(['state' => OfferState::Draft])->save();
    }

    public function test_claim_typed_into_offer_joins_parked_car_with_its_photos(): void
    {
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100006', 'state' => VehicleState::Stored]);
        $offer = Offer::create(['floor_price' => 400000]);
        $this->photo($offer->carRecord(), 'crm');
        $this->photo($vehicle->carRecord(), 'park', ['park' => true]);
        $orphan = $offer->car_id;

        app(UpdateOffer::class)($offer, ['claim_ref' => 'AUT-26-100006'], $this->admin);

        $this->assertSame($vehicle->car_id, $offer->fresh()->car_id);
        $this->assertNull(Car::find($orphan), 'прежняя запись машины слилась');
        $this->assertCount(2, $vehicle->fresh()->photos());
        $this->assertCount(2, $offer->fresh()->photos());
        $this->assertSame(['linked', $offer->fresh()->number], [$vehicle->events()->first()->type->value, $vehicle->events()->first()->payload['number']]);
    }

    public function test_merge_offers_merges_cars_and_skips_duplicate_photos(): void
    {
        $keep = Offer::create(['claim_ref' => 'AUT-26-100007', 'floor_price' => 300000]);
        $drop = Offer::create(['vin' => 'XTA21099071234569', 'asking_price' => 350000]);
        $this->photo($keep->carRecord(), 'same');
        $this->photo($drop->carRecord(), 'same');
        $this->photo($drop->carRecord(), 'other');

        $result = app(MergeOffers::class)($keep, $drop, $this->admin);

        $this->assertSame(['photos' => 1, 'dropped' => 1], $result);
        $this->assertNull(Offer::find($drop->id));
        $this->assertSame(1, Car::count());
        $keep = $keep->fresh();
        $this->assertSame('XTA21099071234569', $keep->vin);
        $this->assertSame(350000, $keep->asking_price);
        $this->assertCount(2, $keep->photos());
        $this->assertSame($keep->id, Offer::whereKey($keep->id)->first()->id);
    }

    public function test_put_on_sale_uses_the_parked_car_and_revives_archived_sale(): void
    {
        $vendor = Vendor::firstOrCreate(['name' => 'АльфаСтрахование']);
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100008', 'vendor_id' => $vendor->id, 'value' => 900000, 'state' => VehicleState::Stored]);
        $offer = app(PutOnSale::class)($vehicle, $this->admin);

        $this->assertSame($vehicle->car_id, $offer->car_id);
        $this->assertSame(Sale::floorFrom(900000), $offer->floor_price);
        $this->assertSame($vendor->id, $offer->vendor_id);
        $this->assertSame(900000, $offer->value, 'оценочная одна');

        $offer->forceFill(['state' => OfferState::Archived])->save();
        $this->assertNull($vehicle->fresh()->offer, 'архивная — не в продаже');
        $again = app(PutOnSale::class)($vehicle->fresh(), $this->admin);
        $this->assertSame($offer->id, $again->id);
        $this->assertSame(OfferState::Draft, $again->state);
    }

    public function test_value_changed_on_park_moves_rule_floor_of_the_sale(): void
    {
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100009', 'value' => 400000, 'state' => VehicleState::Stored]);
        $offer = app(PutOnSale::class)($vehicle, $this->admin);
        $this->assertSame(450000, $offer->floor_price);

        app(UpdateVehicle::class)($vehicle->fresh(), ['value' => 600000], $this->admin);
        $this->assertSame(Sale::floorFrom(600000), $offer->fresh()->floor_price);

        Deal::create(['offer_id' => $offer->id, 'state' => DealState::Active, 'cost' => Sale::floorFrom(600000)]);
        app(UpdateVehicle::class)($vehicle->fresh(), ['value' => 900000], $this->admin);
        $this->assertSame(Sale::floorFrom(600000), $offer->fresh()->floor_price, 'в сделке закупочную правят в CRM, не оценочной парковки');
    }

    public function test_purchase_lot_goes_to_offers_on_its_own_car(): void
    {
        $vendor = Vendor::firstOrCreate(['name' => 'Каркаде']);
        $purchase = Purchase::create(['number' => 7, 'vendor_id' => $vendor->id, 'title' => 'Тест']);
        $lot = $purchase->cars()->create(['dl' => 'ДЛ 12345/2023', 'ref' => 1, 'year' => 2021, 'vin' => 'XTA21099071234570']);
        $this->photo($lot->carRecord(), 'lot');

        $this->assertSame(1, app(MoveToOffers::class)($purchase, [['dl' => 'дл 12345/2023', 'price' => 777000]], $this->admin));
        $offer = $lot->fresh()->offer;
        $this->assertNotNull($offer);
        $this->assertSame($lot->car_id, $offer->car_id);
        $this->assertSame('ДЛ 12345/2023', $offer->claim_ref);
        $this->assertSame(777000, $offer->floor_price);
        $this->assertCount(1, $offer->photos(), 'фото лота — у продажи без переноса');
        $this->assertSame(0, app(MoveToOffers::class)($purchase, [['dl' => 'ДЛ 12345/2023', 'price' => 777000]], $this->admin), 'второй раз не заводит');
    }

    public function test_deleting_draft_keeps_parked_car_and_drops_lonely_one(): void
    {
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100010', 'state' => VehicleState::Stored]);
        $offer = app(PutOnSale::class)($vehicle, $this->admin);
        app(DeleteDraft::class)($offer, $this->admin);
        $this->assertNotNull(Car::find($vehicle->car_id), 'парковка держит машину');
        $this->assertSame('unlinked', $vehicle->events()->first()->type->value);

        $lonely = Offer::create(['claim_ref' => 'AUT-26-100011']);
        $this->photo($lonely->carRecord(), 'x');
        $car = $lonely->car_id;
        app(DeleteDraft::class)($lonely, $this->admin);
        $this->assertNull(Car::find($car));
        $this->assertSame(0, Media::where('model_id', $car)->where('model_type', Car::class)->count());
    }

    public function test_park_document_keeps_park_mark_with_own_properties(): void
    {
        Storage::fake('private');
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100012', 'state' => VehicleState::Stored]);
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, '%PDF-1.4 акт');

        $media = $vehicle->addMedia($tmp)->usingFileName('akt.pdf')->withCustomProperties(['sha' => 'akt', 'source' => 'mail'])->toMediaCollection('papers');
        $this->assertSame(Car::class, $media->model_type);
        $this->assertTrue($media->getCustomProperty('park'), 'свои свойства вызова метку парковки не стирают');
        $this->assertSame('mail', $media->getCustomProperty('source'));
        $this->assertTrue(Sale::parkOwned($media));
    }

    public function test_park_transfer_is_not_a_sale_pickup_even_when_car_is_on_sale(): void
    {
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100013', 'state' => VehicleState::Stored]);
        $offer = app(PutOnSale::class)($vehicle, $this->admin);
        $transfer = Transport::create(['car_id' => $vehicle->car_id, 'origin' => 'park', 'scope' => 'intercity']);
        $pickup = Transport::create(['car_id' => $offer->car_id, 'origin' => 'sale', 'scope' => 'local']);

        $this->assertNull($transfer->saleOffer(), 'перевод парковки шаги вывоза продажи не двигает');
        $this->assertTrue($transfer->lane()->is($vehicle));
        $this->assertTrue($pickup->saleOffer()->is($offer));
        $this->assertTrue($pickup->lane()->is($offer));
        $this->assertNull($pickup->parkVehicle(), 'вывоз продажи — не оплаты парковки');
        $this->assertTrue(Transport::openFor($vehicle)->is($transfer));
        $this->assertTrue(Transport::openFor($offer)->is($pickup));
    }

    public function test_claim_typed_on_park_reaches_the_sale_side(): void
    {
        $vehicle = Vehicle::create(['ref' => 'AUT-26-100014', 'state' => VehicleState::Stored]);
        $offer = app(PutOnSale::class)($vehicle, $this->admin);
        $account = Account::create(['slug' => 'offer', 'title' => 'Предложения', 'email' => 'offer@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => 'AUT-26-100015', 'keys' => ['code:'.Code::key('AUT-26-100015')]]);
        app(UpdateVehicle::class)($vehicle->fresh(), ['ref' => 'AUT-26-100015'], $this->admin);
        $this->assertSame('AUT-26-100015', $offer->fresh()->claim_ref);
        $this->assertSame($offer->id, $thread->fresh()->offer_id, 'письмо CRM с новым номером — у продажи той же машины');
    }
}
