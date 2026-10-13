<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\LegacyClaim;
use App\Park\Actions\AutoSale;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Сразу в продажу» (13.10.2026, начальник): ТС Альфы из заявки на приём, что завела почта, уходит в продажу сама,
 * когда документы прочитаны и фото доехали, — черновиком в «Без продажной цены» с закупочной по правилу.
 */
class AutoSaleTest extends TestCase
{
    use RefreshDatabase;

    private function vehicle(array $attrs = [], bool $autoSale = true): Vehicle
    {
        Queue::fake();
        $vendor = Vendor::firstOrCreate(['name' => 'АльфаСтрахование'], ['kind' => Kind::Insurer]);
        $vendor->forceFill(['on_park' => true, 'auto_sale' => $autoSale])->save();
        $brand = Brand::firstOrCreate(['slug' => 'kia'], ['name' => 'Kia']);
        $account = Account::firstOrCreate(['slug' => 'storage'], ['title' => 'Парковка', 'email' => 'storage@xcar.test', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $vehicle = Vehicle::create($attrs + ['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'ref' => '785R/046/00062/26', 'state' => VehicleState::Expected, 'value' => 900000]);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => 'заявка на прием ТС', 'vehicle_id' => $vehicle->id]);
        $letter = Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In, 'subject' => 'заявка на прием ТС',
            'from_email' => 'kolbasinamp@alfastrah.test', 'date_at' => now(), 'text_body' => 'Прошу принять ТС.']);
        Request::create(['vehicle_id' => $vehicle->id, 'type' => RequestType::Intake, 'state' => RequestState::New, 'thread_id' => $thread->id, 'letter_id' => $letter->id]);

        return $vehicle->fresh();
    }

    public function test_read_vehicle_goes_to_unpriced_with_floor_by_rule(): void
    {
        $offer = app(AutoSale::class)($this->vehicle());

        $this->assertNotNull($offer);
        $this->assertSame($offer->id, Vehicle::where('ref', '785R/046/00062/26')->value('offer_id'));
        $this->assertSame(Sale::floorFrom(900000), (int) $offer->fresh()->floor_price);
        $this->assertSame('Без продажной цены', $offer->fresh()->crmTab());
        $this->assertSame(0, app(AutoSale::class)->sweep(), 'второй раз не уходит');
    }

    public function test_waits_for_value_reading_photos_and_flag(): void
    {
        $sale = app(AutoSale::class);
        $this->assertNull($sale($this->vehicle(['value' => null])));

        $busy = $this->vehicle(['ref' => '785R/046/00063/26', 'vin' => 'LS5A2ASE6PD930368']);
        Cache::put('scan:auto:v:'.$busy->id, ['pass' => 'docs', 'i' => 0, 'n' => 2], 60);
        $this->assertNull($sale($busy), 'документы ещё читаются');
        Cache::forget('scan:auto:v:'.$busy->id);
        Cache::put('import:vehicle:'.$busy->id, ['stage' => 'x', 'i' => 1, 'n' => 5], 60);
        $this->assertNull($sale($busy), 'фото ещё едут');
        Cache::forget('import:vehicle:'.$busy->id);
        $this->assertSame(1, $sale->sweep(), 'часы подбирают, когда всё доехало');

        $this->assertNull($sale($this->vehicle(['ref' => '785R/046/00064/26', 'vin' => 'LS5A2ASE6PD930369'], autoSale: false)));
    }

    public function test_claim_from_katya_table_goes_to_legacy(): void
    {
        $vehicle = $this->vehicle();
        LegacyClaim::create(['claim_ref' => '785R/046/00062/26', 'position' => 1]);

        $this->assertSame('Из таблицы Кати', app(AutoSale::class)($vehicle)->fresh()->crmTab());
    }
}
