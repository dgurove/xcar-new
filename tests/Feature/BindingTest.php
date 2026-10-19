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
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Workflow\Position;
use App\Workflow\Track;
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

        // Хотя бы подтверждение — уже не паника, только «не принято» (метка во вкладках подтверждений).
        $manager = User::create(['name' => 'Иван Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        Bid::create(['offer_id' => $this->offer->id, 'user_id' => $manager->id, 'amount' => 500000, 'state' => BidState::Active]);
        $this->assertSame('undecided', Binding::of($this->offer->fresh()));
        $this->assertFalse(Binding::panics($this->offer->fresh()));
        $q = Offer::query();
        Binding::scope($q, 'panic');
        $this->assertSame(0, $q->count());
        $q = Offer::query();
        Binding::scope($q, 'undecided');
        $this->assertSame(1, $q->count());

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

        // CRM и логистика: капсула с числом ведёт на экран «Горит» (в логистике строка — на задачу).
        $this->assertSame(1, Binding::alarmQuery($moderator)->count());
        $this->actingAs($moderator)->get('http://crm.xcar.localhost/?preset=published')->assertOk()->assertSee('data-alarm-button="wide"', false);
        $this->actingAs($moderator)->get('http://logistics.xcar.localhost/alarms')->assertOk()->assertSee('/transports/'.$t->id, false)
            ->assertSee('Вот-вот сгорят')->assertSee('В продаже, подтверждений нет');
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/live/badges', ['Accept' => 'text/vnd.turbo-stream.html'])->assertOk()
            ->assertSee('data-alarm-button', false)->assertSee('Горит срок обязывающего: 1', false);

        // Две машины — строка на каждую (19.10.2026: вторая роняла шторку «не получилось»); вышедший — в «Сгорели».
        $second = Offer::create(['vendor_id' => $this->offer->vendor_id, 'claim_ref' => '483515/2026']);
        $second->forceFill(['state' => OfferState::Open, 'binding_until' => Carbon::parse('2026-10-21 18:00')])->saveQuietly();
        $this->actingAs($moderator)->get('http://crm.xcar.localhost/alarms')->assertOk()->assertSee('peek='.$second->fresh()->number, false);
        $second->forceFill(['binding_until' => now()->subDay()])->saveQuietly();
        $this->actingAs($moderator)->get('http://crm.xcar.localhost/alarms')->assertOk()->assertSee('Сгорели')->assertSee('просрочено');
        // Чип «Менеджер»: «Без менеджера» — машины без покупателя, они и есть.
        $this->actingAs($moderator)->get('http://crm.xcar.localhost/alarms?manager=none')->assertOk()->assertSee('peek='.$second->fresh()->number, false);
        $second->forceFill(['binding_until' => null])->saveQuietly();

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

    public function test_deal_burns_until_supplier_paid(): void
    {
        $manager = User::create(['name' => 'Иван Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->offer->forceFill(['state' => OfferState::Sold, 'binding_until' => Carbon::parse('2026-10-22 16:00')])->saveQuietly();
        $deal = Deal::create(['offer_id' => $this->offer->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 400000, 'amount' => 500000]);

        $this->assertSame('deal', Binding::of($this->offer->fresh()));
        $this->assertTrue(Binding::panics($this->offer->fresh()));
        $this->assertSame(1, Binding::alarmQuery($this->admin)->count(), 'в тревоге шапки');
        $q = Offer::query();
        Binding::scope($q, 'deal');
        $this->assertSame(1, $q->count());

        // Шаг сделки получает срок обязывающего, правка срока его двигает.
        $stage = $this->stage();
        $pos = Position::create(['offer_id' => $this->offer->id, 'track' => Track::Sale->value, 'stage_id' => $stage->id, 'entered_at' => now(), 'block_entered_at' => now(), 'deadline_at' => null]);
        app(UpdateOffer::class)($this->offer->fresh(), ['binding_until' => Carbon::parse('2026-10-21 18:00')], $this->admin);
        $this->assertSame('2026-10-21 18:00', $pos->fresh()->deadline_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-21 18:00', Binding::stageDeadline($this->offer->fresh(), $deal, null, Carbon::parse('2026-10-30'))->format('Y-m-d H:i'));

        // «Работа → Сделки»: столбца нет, горит — короткая метка у названия.
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/work/deals?vid=table')->assertOk()->assertSee('binding-tag', false)->assertDontSee('Обязывающее до');

        // Заплатили поставщику — срок больше не грозит.
        $deal->forceFill(['supplier_paid_at' => now()])->save();
        $this->assertSame('set', Binding::of($this->offer->fresh()));
        $this->assertSame(0, Binding::alarmQuery($this->admin)->count());
        $this->assertSame('2026-10-30 00:00', Binding::stageDeadline($this->offer->fresh(), $deal->fresh(), null, Carbon::parse('2026-10-30'))->format('Y-m-d H:i'));
    }

    public function test_vendor_days_fill_empty_deadline(): void
    {
        $this->offer->vendor->forceFill(['binding_days' => 60])->save();
        app(UpdateOffer::class)($this->offer->fresh(), ['insurer_deadline_at' => '2026-09-02'], $this->admin);
        $this->assertSame('2026-11-01 23:59', $this->offer->fresh()->binding_until->format('Y-m-d H:i'));

        // Вписанный руками не трогается.
        app(UpdateOffer::class)($this->offer->fresh(), ['binding_until' => Carbon::parse('2026-11-10 16:00')], $this->admin);
        app(UpdateOffer::class)($this->offer->fresh(), ['insurer_deadline_at' => '2026-09-05'], $this->admin);
        $this->assertSame('2026-11-10 16:00', $this->offer->fresh()->binding_until->format('Y-m-d H:i'));
    }

    private function stage(): \App\Workflow\Stage
    {
        $workflow = \App\Workflow\Workflow::create(['vendor_id' => $this->offer->vendor_id, 'track' => Track::Sale->value]);

        $block = \App\Workflow\Block::create(['workflow_id' => $workflow->id, 'name' => 'Получение', 'position' => 1]);

        return \App\Workflow\Stage::create(['workflow_id' => $workflow->id, 'block_id' => $block->id, 'name' => 'Менеджер забирает автомобиль', 'position' => 1, 'waits_for' => 'manager']);
    }

    public function test_only_sovcombank_and_only_staff(): void
    {
        // Вендор не по комиссии (Т-Страхование) — срока нет: вписанный стирается, из вендора не встаёт.
        $this->offer->vendor->forceFill(['deal_format' => 'direct', 'binding_days' => 60])->save();
        app(UpdateOffer::class)($this->offer->fresh(), ['binding_until' => Carbon::parse('2026-10-21 16:00'), 'insurer_deadline_at' => '2026-09-02'], $this->admin);
        $this->assertNull($this->offer->fresh()->binding_until);

        // Логисту — ни тревоги, ни столбца.
        $this->offer->vendor->forceFill(['deal_format' => 'commission'])->save();
        $this->offer->forceFill(['state' => OfferState::Open, 'binding_until' => Carbon::parse('2026-10-21 16:00')])->saveQuietly();
        $tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000009', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $this->assertFalse(Binding::alarmsFor($tanya, \App\Support\Surface::Logistics));
        $this->assertSame(0, Binding::alarmQuery($tanya)->count());
        $this->assertTrue(Binding::alarmsFor($this->admin, \App\Support\Surface::Logistics));
    }
}
