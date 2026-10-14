<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\AutoSale;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\RequestType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
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
        $this->assertSame($offer->id, Vehicle::whereCar('claim_ref', '785R/046/00062/26')->first()->offer?->id, 'одна машина: ТС и её продажа');
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

    public function test_proposal_accept_and_decline(): void
    {
        $admin = User::create(['name' => 'Админов Андрей', 'phone' => '79001234567', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $offer = app(AutoSale::class)($this->vehicle());
        $this->assertTrue($offer->fresh()->proposed());
        $this->assertTrue($offer->fresh()->awaitingPark());

        $this->actingAs($admin)->post('http://crm.xcar.localhost/offers/'.$offer->number.'/proposal')->assertRedirect();
        $this->assertFalse($offer->fresh()->proposed());

        $other = app(AutoSale::class)($this->vehicle(['ref' => '785R/046/00065/26', 'vin' => 'LS5A2ASE6PD930370']));
        $this->actingAs($admin)->delete('http://crm.xcar.localhost/offers/'.$other->number.'/proposal')->assertRedirect();
        $this->assertNull(Offer::find($other->id));
        $vehicle = Vehicle::whereCar('claim_ref', '785R/046/00065/26')->first();
        $this->assertNotNull($vehicle, 'ТС осталась на парковке');
        $this->assertNull($vehicle->offer);
        $this->assertSame(0, app(AutoSale::class)->sweep(), 'отклонённая сама не выставляется');
    }

    public function test_existing_offer_is_linked_without_proposal_and_archived_is_left(): void
    {
        // Предложение этой машины уже есть — ТС с тем же номером встаёт на ту же машину: она уже в продаже, своей плашки нет.
        $live = Offer::create(['claim_ref' => '785R/046/00062/26', 'floor_price' => 500000]);
        $vehicle = $this->vehicle();
        $this->assertSame($live->car_id, $vehicle->car_id, 'одна машина — одна запись');
        $this->assertSame($live->id, $vehicle->offer?->id);
        $this->assertNull(app(AutoSale::class)($vehicle));
        $this->assertFalse($live->fresh()->proposed(), 'чужой черновик плашку не получает');

        $archived = Offer::create(['claim_ref' => '785R/046/00066/26', 'floor_price' => 500000]);
        $archived->forceFill(['state' => OfferState::Archived])->save();
        $this->assertNull(app(AutoSale::class)($this->vehicle(['ref' => '785R/046/00066/26', 'vin' => 'LS5A2ASE6PD930371'])));
        $this->assertSame(OfferState::Archived, $archived->fresh()->state, 'из архива сама не возвращается');
    }

    public function test_decline_from_window_removes_row_and_publish_clears_proposal(): void
    {
        $admin = User::create(['name' => 'Админов Андрей', 'phone' => '79001234567', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $offer = app(AutoSale::class)($this->vehicle());
        $list = 'http://crm.xcar.localhost/?preset=unpriced&peek='.$offer->number;
        $this->actingAs($admin)->withHeaders(['Turbo-Frame' => 'detail', 'Referer' => $list])
            ->delete('http://crm.xcar.localhost/offers/'.$offer->number.'/proposal')->assertRedirect();
        $frame = $this->withHeaders(['Turbo-Frame' => 'detail'])->get($list)->assertOk()->getContent();
        $this->assertStringContainsString('action="remove"', $frame);
        $this->assertStringContainsString('action="advance"', $frame);

        $other = app(AutoSale::class)($this->vehicle(['ref' => '785R/046/00067/26', 'vin' => 'LS5A2ASE6PD930372']));
        $other->forceFill(['asking_price' => 900000])->save();
        app(ChangeOfferState::class)($other->fresh(), OfferState::Archived, $admin);
        $this->assertNull($other->fresh()->proposed_at);
    }

    public function test_claim_from_katya_table_goes_to_legacy(): void
    {
        $vehicle = $this->vehicle();
        LegacyClaim::create(['claim_ref' => '785R/046/00062/26', 'position' => 1]);

        $this->assertSame('Из таблицы Кати', app(AutoSale::class)($vehicle)->fresh()->crmTab());
    }
}
