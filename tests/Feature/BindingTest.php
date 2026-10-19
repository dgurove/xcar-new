<?php

namespace Tests\Feature;

use App\Logistics\Actions\OpenPickup;
use App\Mail\Extraction\DocumentFields;
use App\Notifications\BindingPanicNotice;
use App\Offers\Actions\BindingAlarm;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Срок обязывающего предложения (19.10.2026, владелец: «нет покупателя за 2 рабочих дня — прям ПАНИКА!!!»): разбор
 * протоколов торгов, паника без покупателя, приём не позже срока, звонок один раз на срок. Ошибка здесь молчит —
 * машина уходит, а никто не заметил.
 */
class BindingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        // Вторник, полдень по Москве: два рабочих дня вперёд — четверг.
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'Europe/Moscow'));
        Queue::fake();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '483512/2026', 'floor_price' => 400000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/docs/binding-{$name}.txt"));
    }

    public function test_parses_four_auction_protocols(): void
    {
        // CarXbid: окончание торгов 2 сен 16:00 + 60 дней.
        $this->assertSame('2026-11-01 16:00', DocumentFields::binding($this->fixture('carxbid')));
        // SD-Assistance: 13.02.2026 16:00 + 40 дней.
        $this->assertSame('2026-03-25 16:00', DocumentFields::binding($this->fixture('sda')));
        // Мигторг и БитАвто — дата словами и цифрами, до конца дня.
        $this->assertSame('2026-10-01 23:59', DocumentFields::binding($this->fixture('migtorg')));
        $this->assertSame('2026-10-16 23:59', DocumentFields::binding($this->fixture('bitauto')));
        // Число строкой ниже подписи.
        $this->assertSame('2026-11-06 16:00', DocumentFields::binding("Срок обязывающего предложения:\n   60 дней\n\n Окончание торгов: 2026-09-07 16:00:00"));
        $this->assertNull(DocumentFields::binding('Окончание торгов: 2026-09-07 16:00:00'));
        // Через поля документа — ключом `binding_until`.
        $this->assertSame('2026-11-01 16:00', DocumentFields::extract($this->fixture('carxbid'))['binding_until']['value']);
    }

    public function test_panics_without_buyer_two_workdays_before(): void
    {
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-30 16:00')]);
        $this->assertSame('set', Binding::of($this->offer->fresh()));

        // Четверг — через два рабочих дня: горит.
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-22 16:00')]);
        $this->assertSame('panic', Binding::of($this->offer->fresh()));
        $this->assertSame('binding', $this->offer->fresh()->crmTone());
        $q = Offer::query();
        Binding::scope($q, 'panic');
        $this->assertSame([$this->offer->id], $q->pluck('id')->all(), 'SQL-зеркало то же');

        // Хотя бы подтверждение — уже не паника.
        $manager = User::create(['name' => 'Иван Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        Bid::create(['offer_id' => $this->offer->id, 'user_id' => $manager->id, 'amount' => 500000, 'state' => BidState::Active]);
        $this->assertSame('set', Binding::of($this->offer->fresh()));
        $q = Offer::query();
        Binding::scope($q, 'panic');
        $this->assertSame(0, $q->count());

        // Вышел без покупателя — «over».
        Bid::query()->update(['state' => BidState::Withdrawn]);
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-19 23:59')]);
        $this->assertSame('over', Binding::of($this->offer->fresh()));
    }

    public function test_bids_close_not_after_binding(): void
    {
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-23 16:00')]);
        try {
            app(UpdateOffer::class)($this->offer->fresh(), ['bids_close_at' => Carbon::parse('2026-10-24 17:30')], $this->admin);
            $this->fail('приём позже срока принят');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('не позже срока обязывающего', $e->errors()['bids_close_at'][0]);
        }
        app(UpdateOffer::class)($this->offer->fresh(), ['bids_close_at' => Carbon::parse('2026-10-23 12:00')], $this->admin);
        $this->assertSame('2026-10-23 12:00', $this->offer->fresh()->bids_close_at->format('Y-m-d H:i'));

        // Срок сдвинули раньше идущего приёма — приём встаёт на срок.
        app(UpdateOffer::class)($this->offer->fresh(), ['binding_until' => Carbon::parse('2026-10-22 10:00')], $this->admin);
        $this->assertSame('2026-10-22 10:00', $this->offer->fresh()->bids_close_at->format('Y-m-d H:i'));
        // Умолчание (3 дня, 17:00) подрезается.
        $this->assertSame('2026-10-22 10:00', Binding::clampClose($this->offer->fresh(), Carbon::parse('2026-10-23 17:00'))->format('Y-m-d H:i'));
    }

    public function test_alarm_once_per_deadline_to_leads_and_logist(): void
    {
        $moderator = User::create(['name' => 'Лиза Модератор', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $this->offer->forceFill(['state' => OfferState::Open])->saveQuietly();
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-21 16:00')]);
        app(OpenPickup::class)($this->offer->fresh(), $this->admin);

        $this->assertSame(1, app(BindingAlarm::class)());
        Notification::assertSentTo([$this->admin, $moderator], BindingPanicNotice::class);
        $this->assertSame(0, app(BindingAlarm::class)(), 'второй раз за тот же срок — тихо');

        // Продлили — загорится снова (срок всё ещё в двух рабочих днях).
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-22 16:00')]);
        $this->assertSame(1, app(BindingAlarm::class)());

        // Ночью не звоним.
        Carbon::setTestNow(Carbon::parse('2026-10-20 23:30', 'Europe/Moscow'));
        $this->offer->update(['binding_until' => Carbon::parse('2026-10-21 18:00')]);
        $this->assertSame(0, app(BindingAlarm::class)());
    }

    public function test_crm_and_logistics_show_panic(): void
    {
        $this->offer->forceFill(['state' => OfferState::Open, 'binding_until' => Carbon::parse('2026-10-21 16:00')])->saveQuietly();
        app(OpenPickup::class)($this->offer->fresh(), $this->admin);

        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/offers/'.$this->offer->fresh()->number)->assertOk()
            ->assertSee('binding-panic', false)->assertSee('Нет покупателя');
        $this->actingAs($this->admin)->get('http://logistics.xcar.localhost/transports?preset=all')->assertOk()
            ->assertSee('Обязывающее до')->assertSee('binding-panic', false);
        $this->actingAs($this->admin)->get('http://logistics.xcar.localhost/transports?preset=all&binding=panic&sort=binding')->assertOk()
            ->assertSee('binding-panic', false);
    }

    public function test_header_alarm_until_cause_removed(): void
    {
        $moderator = User::create(['name' => 'Лиза Модератор', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $this->offer->forceFill(['state' => OfferState::Open, 'binding_until' => Carbon::parse('2026-10-21 16:00')])->saveQuietly();
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);

        // CRM и логистика: капсула с числом, шторка со списком (в логистике — на задачу).
        $this->assertSame(1, Binding::alarmQuery($moderator)->count());
        $this->actingAs($moderator)->get('http://crm.xcar.localhost/?preset=published')->assertOk()->assertSee('data-alarm-button="wide"', false);
        $this->actingAs($moderator)->get('http://logistics.xcar.localhost/live/alarms')->assertOk()->assertSee('/transports/'.$t->id, false)->assertSee('Горит');
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/live/badges', ['Accept' => 'text/vnd.turbo-stream.html'])->assertOk()
            ->assertSee('data-alarm-button', false)->assertSee('Нет покупателя: 1', false);

        // Давно вышедший — красный в списках, но не в тревоге.
        $old = Offer::create(['vendor_id' => $this->offer->vendor_id, 'claim_ref' => '483513/2026']);
        $old->forceFill(['binding_until' => now()->subDays(Binding::ALARM_DAYS + 3)])->saveQuietly();
        $this->assertSame(1, Binding::alarmQuery($moderator)->count());

        // Подтверждение — причины нет, капсула гаснет.
        $manager = User::create(['name' => 'Иван Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        Bid::create(['offer_id' => $this->offer->id, 'user_id' => $manager->id, 'amount' => 500000, 'state' => BidState::Active]);
        $this->assertSame(0, Binding::alarmQuery($moderator)->count());

        // Менеджеру и сайту тревоги нет.
        $this->assertFalse(Binding::alarmsFor($manager, \App\Support\Surface::Crm));
        $this->assertFalse(Binding::alarmsFor($this->admin, \App\Support\Surface::Site));
    }
}
