<?php

namespace Tests\Feature;

use App\Billing\ActForm;
use App\Billing\Actions\FixClosingDocument;
use App\Billing\Actions\TickInvoices;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Cadence;
use App\Billing\Charge;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\ClosingDoc;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Numbering;
use App\Billing\Party;
use App\Billing\Seller;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Tariff;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
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

    public function test_draft_is_live_and_fix_records_it(): void
    {
        Charge::create(['party_id' => $this->vendor->party_id, 'vehicle_id' => $this->vehicle->id, 'kind' => ChargeKind::Tow, 'title' => 'Подача эвакуатора',
            'qty' => 1, 'unit' => 'pc', 'price' => 16000, 'amount' => 16000, 'period_from' => '2026-10-01', 'created_by' => $this->admin->id]);
        $month = Carbon::parse('2026-10-01');
        $groups = Closing::items($month)->groupBy(fn ($i) => Closing::groupOf($i))->keys();
        $this->assertCount(2, $groups);
        [$storageGroup, $towGroup] = str_starts_with($groups[0], 'tow:') ? [$groups[1], $groups[0]] : [$groups[0], $groups[1]];

        // Живой: ничего не записано, сумма по текущим данным, номер — какой получит.
        $draft = Closing::draft($month, $storageGroup);
        $this->assertFalse($draft->exists);
        $this->assertSame(3100.0, (float) $draft->total);             // 31 сутки по 100, без эвакуации
        $this->assertSame('20260511-01', $draft->code);
        $this->assertSame(0, Invoice::count());
        $this->assertNull($this->vehicle->fresh()->storage_billed_until);

        $storage = app(FixClosingDocument::class)($month, $storageGroup, $this->admin);
        $tow = app(FixClosingDocument::class)($month, $towGroup, $this->admin);
        $this->assertSame(3100.0, (float) $storage->total);
        $this->assertSame(16000.0, (float) $tow->total);
        $this->assertNull($storage->vat_rate);                         // ИП — без НДС
        $this->assertNotNull($storage->sent_at);
        $this->assertSame('№ 20260511-01', $storage->label());
        $this->assertSame('20260511-02', $tow->code);
        $this->assertStringEndsWith('.xlsx', $storage->getFirstMedia('act')->file_name);
        $this->assertStringContainsString('Акт по эвакуации', $tow->getFirstMedia('act')->file_name);
        $this->assertSame('2026-10-31', $this->vehicle->fresh()->storage_billed_until->toDateString());
        $this->assertNull(Closing::draft($month, $storageGroup));       // отправлен — живого больше нет
        $this->assertNull(Closing::drift($storage));
    }

    /** Данные отправленного исправили — «изменилось», исправленный уходит тем же номером и «ред. 1», прежний аннулирован. */
    public function test_changed_after_sending_is_revised_with_same_number(): void
    {
        $month = Carbon::parse('2026-10-01');
        $group = Closing::items($month)->map(fn ($i) => Closing::groupOf($i))->first();
        $sent = app(FixClosingDocument::class)($month, $group, $this->admin);
        $this->vehicle->fresh()->update(['accepted_at' => '2026-10-05 10:00']);

        $this->assertSame(['was' => 3100.0, 'now' => 2700.0], Closing::drift($sent->fresh()));
        $revised = app(FixClosingDocument::class)($month, $group, $this->admin, $sent->fresh());
        $this->assertSame(InvoiceState::Void, $sent->fresh()->state);
        $this->assertSame($sent->code, $revised->code);
        $this->assertSame(1, $revised->revision);
        $this->assertSame(2700.0, (float) $revised->total);
        $this->assertSame('№ 20260511-01, ред. 1', $revised->label());
        $this->assertNull(Closing::drift($revised));
    }

    /**
     * Сентябрь и октябрь отправлены, сентябрь исправили: октябрь остаётся выставленным, второй раз не всплывает; просто
     * аннулировать сентябрь, пока есть октябрь, нельзя.
     */
    public function test_revising_earlier_month_keeps_later_billed(): void
    {
        $this->vehicle->update(['accepted_at' => '2026-09-01 10:00']);
        $sep = Carbon::parse('2026-09-01');
        $oct = Carbon::parse('2026-10-01');
        $group = Closing::items($sep)->map(fn ($i) => Closing::groupOf($i))->first();
        $september = app(FixClosingDocument::class)($sep, $group, $this->admin);
        $october = app(FixClosingDocument::class)($oct, $group, $this->admin);
        $this->assertSame('2026-10-31', $this->vehicle->fresh()->storage_billed_until->toDateString());

        try {
            app(VoidInvoice::class)($september->fresh(), $this->admin);
            $this->fail('Сентябрь аннулирован при живом октябре');
        } catch (ValidationException) {
        }

        $this->vehicle->fresh()->update(['accepted_at' => '2026-09-03 10:00']);
        $this->assertSame(['was' => 3000.0, 'now' => 2800.0], Closing::drift($september->fresh()));
        $revised = app(FixClosingDocument::class)($sep, $group, $this->admin, $september->fresh());
        $this->assertSame(2800.0, (float) $revised->total);
        $this->assertSame('2026-10-31', $this->vehicle->fresh()->storage_billed_until->toDateString());
        $this->assertNull(Closing::draft($oct, $group));             // октябрь не всплыл живым
        $this->assertSame(InvoiceState::Issued, $october->fresh()->state);
    }

    /**
     * Документ месяца — строго свой месяц (05.11.2026, владелец: «в счёте за октябрь строки, выданные в сентябре»): по выдаче —
     * выданные в нём; помесячно — в месяце первого невыставленного дня; эвакуация — в месяц своей даты.
     */
    public function test_month_holds_only_its_own_cars(): void
    {
        $sep = Carbon::parse('2026-09-01');
        $oct = Carbon::parse('2026-10-01');
        $alfa = Vendor::create(['name' => 'Альфа тест', 'party_id' => $this->vendor->party_id, 'billing_cadence' => Cadence::Release, 'act_form' => ActForm::Report]);
        Tariff::create(['vendor_id' => $alfa->id, 'category' => 'passenger', 'service' => 'storage', 'from_day' => 1, 'price' => 300, 'valid_from' => '2023-01-01']);
        $yard = $this->vehicle->yard_id;
        $september = Vehicle::create(['vendor_id' => $alfa->id, 'yard_id' => $yard, 'state' => VehicleState::Released, 'accepted_at' => '2026-09-01 10:00', 'released_at' => '2026-09-17 12:00', 'vin' => 'XW8ZZZ61ZHG035221', 'ref' => 'A-1', 'category' => 'passenger']);
        $october = Vehicle::create(['vendor_id' => $alfa->id, 'yard_id' => $yard, 'state' => VehicleState::Released, 'accepted_at' => '2026-09-20 10:00', 'released_at' => '2026-10-10 12:00', 'vin' => 'XW8ZZZ61ZHG035222', 'ref' => 'A-2', 'category' => 'passenger']);
        $this->vehicle->update(['accepted_at' => '2026-09-01 10:00']);
        Charge::create(['party_id' => $this->vendor->party_id, 'vehicle_id' => $this->vehicle->id, 'kind' => ChargeKind::Tow, 'title' => 'Подача эвакуатора',
            'qty' => 1, 'unit' => 'pc', 'price' => 16000, 'amount' => 16000, 'period_from' => '2026-09-01', 'created_by' => $this->admin->id]);
        $cars = fn ($month, $tow = false) => Closing::items($month)->where('tow', $tow)->pluck('vehicle.id')->unique()->sort()->values()->all();

        // По выдаче: выданная 17.09 — только в сентябре, пока сентябрь не отправлен — тоже.
        $this->assertSame([$this->vehicle->id, $september->id], $cars($sep));
        $this->assertSame([$october->id], $cars($oct));
        // Помесячно: сентябрь не отправлен — ТС только в сентябре, 30 суток.
        $this->assertSame(30, Closing::items($sep)->firstWhere('vehicle.id', $this->vehicle->id)['days']);
        // Эвакуация 01.09 — в сентябре, не в октябре.
        $this->assertSame([$this->vehicle->id], $cars($sep, true));
        $this->assertSame([], $cars($oct, true));

        $group = Closing::items($sep)->firstWhere('vehicle.id', $this->vehicle->id);
        app(FixClosingDocument::class)($sep, Closing::groupOf($group), $this->admin);
        // Сентябрь отправлен — помесячная в октябре, 31 сутки.
        $this->assertSame(31, Closing::items($oct)->firstWhere('vehicle.id', $this->vehicle->id)['days']);
    }

    /**
     * Экран «Счета и акты» — месяцами одной таблицей, строка открывает карточку рядом (живой документ — по ключу месяца
     * и группы); «Отправили сами» записывает, кто отметил, и ведёт в карточку отправленного.
     */
    public function test_screen_lists_months_and_opens_card(): void
    {
        $month = Carbon::parse('2026-10-01');
        $group = Closing::items($month)->map(fn ($i) => Closing::groupOf($i))->first();
        $key = ClosingDoc::liveKey($month, $group);
        $this->actingAs($this->admin)->get(Surface::Park->url('/money/closing'))->assertOk()
            ->assertSee('Октябрь')->assertSee('СОГАЗ тест')->assertSee('data-detail-key="'.$key.'"', false);
        $this->actingAs($this->admin)->get(Surface::Park->url('/money/closing?peek='.$key), ['Turbo-Frame' => 'detail'])->assertOk()
            ->assertSee('Отправить')->assertSee('Файлы')->assertSee('01.10 – 31.10, 31')->assertDontSee('Не вошли');

        $this->actingAs($this->admin)->post(Surface::Park->url('/money/draft/fix'), ['month' => '2026-10', 'group' => $group])
            ->assertRedirect('/money/closing?peek=i'.Invoice::first()->id);
        $this->assertSame($this->admin->id, Invoice::first()->sent_by);
        $this->actingAs($this->admin)->get(Surface::Park->url('/money/closing?peek=i'.Invoice::first()->id), ['Turbo-Frame' => 'detail'])->assertOk()
            ->assertSee('Отмечен отправленным');
    }

    /**
     * Прошлый документ ушёл по-старому (05.11.2026, владелец: «сверю со старыми актами и прожму, что отправлено»): «Отправили
     * сами» — свой номер из архива и день; занятый номер — ошибкой у поля. Счёт-реестр открывается страницей документа со
     * всеми ТС.
     */
    public function test_marked_sent_with_archive_number_and_day(): void
    {
        $month = Carbon::parse('2026-10-01');
        $group = Closing::items($month)->map(fn ($i) => Closing::groupOf($i))->first();
        $this->actingAs($this->admin)->post(Surface::Park->url('/money/draft/fix'), ['month' => '2026-10', 'group' => $group, 'code' => '20261102-07', 'sent_on' => '2026-11-02']);
        $invoice = Invoice::firstOrFail();
        $this->assertSame('20261102-07', $invoice->code);
        $this->assertSame('2026-11-02', $invoice->issued_at->toDateString());
        $this->assertSame('2026-11-02', $invoice->sent_at->toDateString());
        $this->assertTrue($invoice->due_at->gt($invoice->issued_at));

        $this->actingAs($this->admin)->get(Surface::Park->url('/money/invoices/'.$invoice->id))->assertRedirect('/money/closing/i'.$invoice->id);
        $this->actingAs($this->admin)->get(Surface::Park->url('/money/closing/i'.$invoice->id))->assertOk()
            ->assertSee('Все ТС')->assertSee('Отменить отправку')->assertSee('SGZA0000933697D', false);

        // Тот же номер второй раз — ошибка у поля, ничего не записано.
        Charge::create(['party_id' => $this->vendor->party_id, 'vehicle_id' => $this->vehicle->id, 'kind' => ChargeKind::Tow, 'title' => 'Подача эвакуатора',
            'qty' => 1, 'unit' => 'pc', 'price' => 16000, 'amount' => 16000, 'period_from' => '2026-10-03', 'created_by' => $this->admin->id]);
        $tow = Closing::items($month)->first(fn ($i) => $i['tow']);
        $this->actingAs($this->admin)->from(Surface::Park->url('/money/closing'))
            ->post(Surface::Park->url('/money/draft/fix'), ['month' => '2026-10', 'group' => Closing::groupOf($tow), 'code' => '20261102-07', 'sent_on' => '2026-11-02'])
            ->assertSessionHasErrors('code');
        $this->assertSame(1, Invoice::count());

        // «···» → «Отменить отправку»: номер освобождается, документ снова живой.
        $this->actingAs($this->admin)->post(Surface::Park->url('/money/invoices/'.$invoice->id.'/void'));
        $this->assertSame(InvoiceState::Void, $invoice->fresh()->state);
        $this->assertNotNull(Closing::draft($month, $group));
    }

    /** Отмеченный задним числом с прошедшим сроком — просрочка отмечена молча: часы не шлют «просрочен» за всю историю. */
    public function test_back_dated_document_does_not_alarm_overdue(): void
    {
        $month = Carbon::parse('2026-10-01');
        $group = Closing::items($month)->map(fn ($i) => Closing::groupOf($i))->first();
        $invoice = app(FixClosingDocument::class)($month, $group, $this->admin, null, '20261001-01', Carbon::parse('2026-10-01'));
        $this->assertNotNull($invoice->fresh()->overdue_at);
        $this->assertSame(0, app(TickInvoices::class)());
    }

    public function test_rgs_form_gives_act_and_appendix(): void
    {
        $this->vendor->update(['act_form' => ActForm::Rgs]);
        $month = Carbon::parse('2026-10-01');
        $invoice = app(FixClosingDocument::class)($month, Closing::items($month)->map(fn ($i) => Closing::groupOf($i))->first(), $this->admin);
        $files = $invoice->fresh()->getMedia('act')->pluck('file_name')->sort()->values()->all();
        $this->assertCount(2, $files);
        $this->assertStringEndsWith('.docx', $files[0]);
        $this->assertStringEndsWith('.xlsx', $files[1]);
    }
}
