<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Http\Admin\OfferController;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\DropValuation;
use App\Offers\Offer;
use App\Park\Actions\AutoSale;
use App\Park\City;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Заявка на приём «Сразу в продажу» — всегда «Заполнить цену продажи» (26.11.2026, владелец по Exeed 785R/046/01928/26: черновик
 * с Мигторга уже был, оценочная из повёрнутого скана не прочиталась, и машина легла в «Без закупочной» среди 500 других —
 * «их никто не заметит, это потерянные деньги»). Ошибка молчаливая: машина просто теряется в чужой вкладке.
 */
class ParkIntakeUnpricedTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $party = Party::create(['kind' => 'company', 'name' => 'АО «АльфаСтрахование»', 'inn' => '7713056834']);
        $this->vendor = Vendor::create(['name' => 'Альфа тест', 'party_id' => $party->id, 'kind' => Kind::Insurer, 'auto_sale' => true, 'floor_by_value' => true]);
        $this->admin = User::create(['name' => 'Админ', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
    }

    public function test_unread_request_lands_in_unpriced_without_brand_and_value(): void
    {
        $vehicle = $this->vehicle();

        $this->assertSame(1, app(AutoSale::class)->sweep());

        $offer = Offer::where('car_id', $vehicle->car_id)->sole();
        $this->assertNotNull($offer->park_intake_at);
        $this->assertNull($offer->floor_price, 'оценочной нет — и закупочной нет');
        $this->assertTrue($this->in('unpriced', $offer), 'без закупочной, а в «Заполнить цену продажи»');
        $this->assertFalse($this->in('nofloor', $offer));
        $this->assertSame(OfferController::PRESETS['unpriced'], $offer->crmTab($this->admin));
        $this->assertTrue($this->in('nofloor', $offer, admin: false), 'модератору — как было');
    }

    public function test_existing_draft_is_marked_takes_park_value_and_rule_returns_it(): void
    {
        $vehicle = $this->vehicle(value: 2529999);
        $offer = new Offer(['vendor_id' => $this->vendor->id]);
        $offer->forceFill(['car_id' => $vehicle->car_id])->save();
        $this->assertTrue($this->in('nofloor', $offer->fresh()));

        app(AutoSale::class)->sweep();

        $offer->refresh();
        $this->assertNotNull($offer->park_intake_at);
        $this->assertSame(2529999, (int) $offer->value, 'оценочная с парковки — и в продажу');
        $this->assertGreaterThan(0, (int) $offer->floor_price);
        $this->assertTrue($this->in('unpriced', $offer));

        // «Оценочная неактуальна» / правило Мигторга — назад в «Без закупочной», и часы не помечают снова.
        app(DropValuation::class)($offer, $this->admin, 'тест');
        $offer->refresh();
        $this->assertNull($offer->park_intake_at);
        $this->assertTrue($this->in('nofloor', $offer));
        $this->assertSame(0, app(AutoSale::class)->sweep());
        $this->assertNull($offer->fresh()->park_intake_at);
    }

    public function test_draft_with_dropped_value_is_left_in_nofloor(): void
    {
        $vehicle = $this->vehicle();
        $offer = new Offer(['vendor_id' => $this->vendor->id]);
        $offer->forceFill(['car_id' => $vehicle->car_id, 'value_at' => now()->subDay()])->save();

        app(AutoSale::class)->sweep();

        $this->assertNull($offer->fresh()->park_intake_at, 'правило Мигторга сняло оценочную — остаётся в «Без закупочной»');
        $this->assertTrue($this->in('nofloor', $offer->fresh()));
    }

    public function test_lot_after_request_returns_unread_draft_to_nofloor(): void
    {
        $vehicle = $this->vehicle();
        app(AutoSale::class)->sweep();
        $offer = Offer::where('car_id', $vehicle->car_id)->sole();
        $this->assertTrue($this->in('unpriced', $offer));

        DB::table('migtorg_lots')->insert(['id' => 900002, 'claim_ref' => '785R/046/01928/26', 'claim_ref_key' => \App\Mail\Extraction\Code::key('785R/046/01928/26'),
            'title' => 'Exeed', 'seen_at' => now()->addMinute(), 'first_seen_at' => now()->addMinute()]);
        \App\Offers\Valuations::sweep();

        $this->assertNull($offer->fresh()->park_intake_at, 'лот позже заявки — сумма заявки неактуальна, ждём оценку поставщика');
        $this->assertTrue($this->in('nofloor', $offer->fresh()));
    }

    public function test_waits_for_reading_but_not_longer_than_half_an_hour(): void
    {
        $vehicle = $this->vehicle(at: now()->subMinutes(5));
        Cache::put('scan:auto:v:'.$vehicle->id, ['pass' => 'docs', 'i' => 0, 'n' => 2], 600);

        $this->assertSame(0, app(AutoSale::class)->sweep(), 'документы читаются — ждём');

        Request::withoutGlobalScope(City::SCOPE)->where('vehicle_id', $vehicle->id)->update(['created_at' => now()->subMinutes(31)]);
        $this->assertSame(1, app(AutoSale::class)->sweep(), 'чтение застряло — черновик всё равно');
    }

    private function in(string $tab, Offer $offer, bool $admin = true): bool
    {
        return OfferController::scopeFor($tab, Offer::query(), admin: $admin)->whereKey($offer->id)->exists();
    }

    private function vehicle(?int $value = null, $at = null): Vehicle
    {
        $vehicle = Vehicle::create(['vendor_id' => $this->vendor->id, 'state' => VehicleState::Expected, 'ref' => '785R/046/01928/26',
            'vin' => 'LNNBDDEZ2SD390368', 'category' => 'passenger', 'value' => $value]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => 'заявка на прием ТС 785R/046/01928/26', 'vehicle_id' => $vehicle->id]);
        $letter = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru',
            'subject' => 'заявка на прием ТС 785R/046/01928/26', 'intent' => 'intake', 'date_at' => now()]);
        $request = Request::create(['vehicle_id' => $vehicle->id, 'type' => 'intake', 'state' => RequestState::New]);
        $request->forceFill(['letter_id' => $letter->id, 'created_at' => $at ?? now()->subHour()])->save();

        return $vehicle->fresh();
    }
}
