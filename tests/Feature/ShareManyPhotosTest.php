<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Share\Subject;
use App\Park\City;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * «Поделиться» без потолка в 30 кадров (02.11.2026, владелец): кадры группами «от страховой» / «при приёме», PDF из всех
 * выбранных, больше 10 — ещё архивом с папками групп.
 */
class ShareManyPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_photos_grouped_into_pdf_and_zip(): void
    {
        config(['xcar.mercure.hub' => '']);
        Storage::fake('public');
        Storage::fake('cache');
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $offer = Offer::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'floor_price' => 900000, 'asking_price' => 1000000]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        foreach (range(1, 35) as $i) {
            $offer->addMedia(UploadedFile::fake()->image("p{$i}.jpg", 200, 150))
                ->withCustomProperties(['stage' => $i <= 23 ? PhotoStage::Vendor->value : PhotoStage::Intake->value])->toMediaCollection('photos');
        }
        $offer = $offer->fresh();

        $groups = Subject::offer($offer)->photoGroups();
        $this->assertSame(['Фото от страховой', 'Фото при приёме'], array_column($groups, 'label'));
        $this->assertSame([23, 12], array_map(fn ($g) => $g['photos']->count(), $groups));

        $ids = $offer->visiblePhotos()->pluck('id')->all();
        $crm = fn (string $path) => 'http://crm.xcar.localhost/offers/'.$offer->number.$path;
        $this->actingAs($admin)->post($crm('/photos/warm'), ['photos' => array_slice($ids, 0, 10), 'watermark' => '0'])->assertNoContent();
        $this->actingAs($admin)->post($crm('/pdf'), ['photos' => $ids, 'watermark' => '0'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $zip = $this->actingAs($admin)->post($crm('/zip'), ['photos' => $ids, 'watermark' => '0'])->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('Content-Type'));
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($zip->baseResponse->getFile()->getPathname()));
        $this->assertSame(35, $archive->numFiles);
        $this->assertStringStartsWith('Фото от страховой/', $archive->getNameIndex(0));
        $this->assertStringStartsWith('Фото при приёме/', $archive->getNameIndex(34));
    }

    public function test_park_vehicle_share_for_admins_only(): void
    {
        config(['xcar.mercure.hub' => '']);
        Storage::fake('public');
        Storage::fake('cache');
        $city = City::main();
        $yard = Yard::create(['name' => 'Краснопутиловская', 'is_active' => true, 'settlement_id' => $city]);
        $vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Альфа СПб'])->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => now()]);
        foreach (range(1, 12) as $i) {
            $vehicle->addMedia(UploadedFile::fake()->image("v{$i}.jpg", 200, 150))
                ->withCustomProperties(['stage' => $i <= 4 ? PhotoStage::Vendor->value : PhotoStage::Intake->value])->toMediaCollection('photos');
        }
        $ids = $vehicle->fresh()->photos()->pluck('id')->all();
        $parkAdmin = User::create(['name' => 'Админ парковки', 'phone' => '79000000061', 'roles' => [Role::ParkAdmin], 'approved_at' => now()]);
        $parking = User::create(['name' => 'Управляющий', 'phone' => '79000000062', 'roles' => [Role::Parking], 'park_city_id' => $city, 'approved_at' => now()]);
        $url = fn (string $path) => 'http://park.xcar.localhost/cars/'.$vehicle->id.$path;

        $this->assertSame(['Фото от страховой', 'Фото при приёме'], array_column(Subject::vehicle($vehicle->fresh())->photoGroups(), 'label'));
        $this->actingAs($parkAdmin)->get($url(''))->assertOk()->assertSee('data-share-zip-url-value', false);
        $this->actingAs($parkAdmin)->post($url('/pdf'), ['photos' => $ids, 'watermark' => '0'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($parkAdmin)->post($url('/zip'), ['photos' => $ids, 'watermark' => '0'])->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->actingAs($parking)->post($url('/pdf'), ['photos' => $ids])->assertNotFound();
        $this->actingAs($parking)->get($url(''))->assertOk()->assertDontSee('data-share-zip-url-value', false);
    }
}
