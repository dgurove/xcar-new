<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\CarModel;
use App\Cars\Vin\KnownWmi;
use App\Cars\Vin\VinMemory;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Каждая вещь — одна сущность (29.10.2026): реквизиты вендора живут только в его контрагенте (`billing_parties`),
 * память VIN — в самих машинах (`cars`), без копий по дорожкам.
 */
class VendorRequisitesVinTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_form_writes_requisites_into_party_and_documents_read_them(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Админ парковки', 'phone' => '79000000051', 'roles' => [Role::ParkAdmin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'ВСК тест', 'kind' => 'insurer', 'on_park' => true]);
        $form = ['name' => 'ВСК тест', 'kind' => 'insurer', 'is_active' => 1, 'storage_payer' => 'vendor', 'buyer_rate_multiplier' => 1, 'billing_cadence' => 'monthly',
            'party' => ['name' => 'САО «ВСК»', 'inn' => '7710026574', 'kpp' => '773101001', 'legal_address' => 'Москва, Островная, 4', 'bank_name' => 'ПАО Сбербанк',
                'account' => '40701810000000000001', 'corr_account' => '30101810400000000225', 'bik' => '044525225', 'payment_purpose' => 'За ТС по договору']];

        $this->actingAs($admin)->put(Surface::Park->url("/vendors/{$vendor->id}"), $form)->assertSessionHasNoErrors()->assertRedirect();

        $party = $vendor->refresh()->party;
        $this->assertNotNull($party);
        $this->assertSame(['САО «ВСК»', '7710026574', '40701810000000000001', '044525225'], [$party->name, $party->inn, $party->account, $party->bik]);
        $this->assertSame($party->id, Party::forVendor($vendor)->id);
        $this->assertTrue($party->billable());

        // Повторное сохранение правит того же контрагента, второго не заводит.
        $form['party']['account'] = '40701810000000000002';
        $this->actingAs($admin)->put(Surface::Park->url("/vendors/{$vendor->id}"), $form)->assertSessionHasNoErrors();
        $this->assertSame('40701810000000000002', $party->refresh()->account);
        $this->assertSame(1, Party::where('inn', '7710026574')->count());

        // Обзор и договор комиссии печатают реквизиты контрагента.
        $this->actingAs($admin)->get(Surface::Park->url("/vendors/{$vendor->id}"))->assertOk()->assertSee('7710026574')->assertSee('40701810000000000002');
        $yard = Yard::create(['name' => 'Измайловский лес', 'is_active' => true, 'settlement_id' => City::main()]);
        $vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => now()->subDay()]);
        $this->actingAs($admin)->get(Surface::Park->url("/acts/{$vehicle->id}/contract"))->assertOk()
            ->assertSee('САО «ВСК»', false)->assertSee('40701810000000000002')->assertSee('За ТС по договору');
    }

    public function test_vin_memory_reads_cars(): void
    {
        $brand = Brand::create(['name' => 'Chery', 'slug' => 'chery']);
        $model = CarModel::create(['brand_id' => $brand->id, 'name' => 'Tiggo 7 Pro', 'slug' => 'tiggo-7-pro']);
        Car::create(['vin' => 'EDEDB21B4RD000101', 'brand_id' => $brand->id, 'model_id' => $model->id, 'year' => 2024]);
        Car::create(['vin' => 'EDEDB21B7RD000202', 'brand_id' => $brand->id, 'model_id' => $model->id]);

        $memory = (new VinMemory)->recall('EDEDB21B0RD999999');
        $this->assertSame($brand->id, (int) $memory['values']['brand_id']);
        $this->assertSame($model->id, (int) $memory['values']['model_id']);
        $this->assertSame(2, $memory['count']);
        $this->assertArrayHasKey('EDE', KnownWmi::learned());
    }
}
