<?php

namespace Tests\Feature;

use App\Billing\ActForm;
use App\Billing\Actions\IssueInvoice;
use App\Billing\Cadence;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Numbering;
use App\Billing\Party;
use App\Billing\Seller;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Tariff;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Закрытие месяца по прежним документам (архив «СТОЯНКА», 05.11.2026): номер «ГГГГДДММ-NN», ИП без НДС, эвакуация
 * своим счётом, акт — файлом формы страховой. Ошибка здесь — двойной номер, НДС в счёте ИП или эвакуация в счёте
 * хранения по чужому договору.
 */
class ParkClosingDocsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media-library.disk_name'));
        Storage::fake('private');
        Notification::fake();
        Carbon::setTestNow('2026-11-05 12:00');
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $party = Party::create(['kind' => 'company', 'name' => 'АО «СОГАЗ»', 'inn' => '7736035485', 'kpp' => '997950001', 'legal_address' => '107078, г. Москва']);
        $this->vendor = Vendor::create(['name' => 'СОГАЗ тест', 'party_id' => $party->id, 'billing_cadence' => Cadence::Monthly, 'act_form' => ActForm::Sogaz,
            'agreement_number' => 'СОХ/2/23/3350', 'agreement_date' => '2023-08-24', 'tow_agreement_number' => 'Э/2/23/3351', 'tow_agreement_date' => '2023-07-28']);
        Tariff::create(['vendor_id' => $this->vendor->id, 'category' => 'passenger', 'service' => 'storage', 'from_day' => 1, 'price' => 100, 'valid_from' => '2023-01-01']);
        $yard = Yard::create(['name' => 'Дорофеева', 'is_active' => true, 'settlement_id' => City::main()]);
        $this->vehicle = Vehicle::create(['vendor_id' => $this->vendor->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => '2026-10-01 10:00',
            'vin' => 'XW8ZZZ61ZHG035220', 'ref' => 'SGZA0000933697D№0000001', 'category' => 'passenger']);
    }

    public function test_document_code_counts_per_day_and_seller(): void
    {
        $this->assertSame('20260511-01', Numbering::code(Seller::Park, now()));
        \DB::table('billing_invoices')->insert(['seller' => 'park', 'code' => '20260511-01', 'direction' => 'issued', 'kind' => 'storage', 'party_id' => $this->vendor->party_id,
            'issued_at' => now(), 'state' => 'issued', 'total' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('20260511-02', Numbering::code(Seller::Park, now()));
        $this->assertSame('20260511-01', Numbering::code(Seller::Prime, now()));
        $this->assertSame('20260611-01', Numbering::code(Seller::Park, now()->addDay()));
    }

    public function test_tow_is_its_own_invoice_and_ip_has_no_vat(): void
    {
        Charge::create(['party_id' => $this->vendor->party_id, 'vehicle_id' => $this->vehicle->id, 'kind' => ChargeKind::Tow, 'title' => 'Подача эвакуатора',
            'qty' => 1, 'unit' => 'pc', 'price' => 16000, 'amount' => 16000, 'period_from' => '2026-10-01', 'created_by' => $this->admin->id]);
        $month = Carbon::parse('2026-10-01');
        $items = Closing::items($month);
        $this->assertCount(2, $items);
        $this->assertCount(2, $items->groupBy(fn ($i) => Closing::groupOf($i)));

        $res = Closing::issue($month, $items->pluck('key')->all(), $this->admin, app(IssueInvoice::class), app(StorageActPdf::class));
        $this->assertSame([], $res['errors']);
        $storage = $res['issued']->firstWhere('kind', ChargeKind::Storage)->fresh();
        $tow = $res['issued']->firstWhere('kind', ChargeKind::Tow)->fresh();

        $this->assertSame(3100.0, (float) $storage->total);           // 31 сутки по 100, без эвакуации
        $this->assertSame(16000.0, (float) $tow->total);
        $this->assertNull($storage->vat_rate);                         // ИП — без НДС
        $this->assertSame('20260511-01', $storage->code);
        $this->assertSame('20260511-02', $tow->code);
        $this->assertSame('№ 20260511-01', $storage->label());
        $this->assertStringEndsWith('.xlsx', $storage->getFirstMedia('act')->file_name);
        $this->assertStringContainsString('Акт по эвакуации', $tow->getFirstMedia('act')->file_name);
        $this->assertSame('2026-10-31', $this->vehicle->fresh()->storage_billed_until->toDateString());
    }

    public function test_rgs_form_gives_act_and_appendix(): void
    {
        $this->vendor->update(['act_form' => ActForm::Rgs]);
        $month = Carbon::parse('2026-10-01');
        $res = Closing::issue($month, Closing::items($month)->pluck('key')->all(), $this->admin, app(IssueInvoice::class), app(StorageActPdf::class));
        $files = $res['issued']->first()->fresh()->getMedia('act')->pluck('file_name')->sort()->values()->all();
        $this->assertCount(2, $files);
        $this->assertStringEndsWith('.docx', $files[0]);
        $this->assertStringEndsWith('.xlsx', $files[1]);
    }
}
