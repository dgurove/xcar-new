<?php

namespace Tests\Feature;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Отдать» (18.10.2026): черновик — менеджеру в сделку ценой или в гараж без публикации, открытое — третьему мимо
 * подтверждений, готовое подтверждение — тем же листом. Ошибка тут молчалива и дорога (сделка без подтверждения, маршрут
 * на черновике, временный номер у менеджера) — поэтому тест.
 */
class GiveTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private User $admin;

    private User $roman;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->roman = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Alfa);
    }

    private function draft(?int $asking): Offer
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 900000, 'asking_price' => $asking]);

        return app(StartRoute::class)($offer->fresh(), $this->admin, Track::Sale)->fresh();
    }

    public function test_draft_with_price_goes_into_deal_without_publishing(): void
    {
        $offer = $this->draft(1200000);
        $temporary = $offer->number;

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 150 000', 'commission' => '30 000', 'mode' => 'payout', 'scheme' => 'prime', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $offer = $offer->fresh();
        $deal = $offer->deal;
        $this->assertSame(OfferState::Sold, $offer->state);
        $this->assertSame(1150000, $deal->amount);
        $this->assertSame(30000, $deal->commission);
        $this->assertSame(900000, $deal->cost);
        $this->assertTrue($deal->buyer->is($this->roman));
        // Сделка — на подтверждении, внесённом сотрудником.
        $this->assertSame(BidState::Accepted, $deal->bid->state);
        $this->assertTrue($deal->bid->placedBy->is($this->admin));
        // Номер настоящий, прежний открывает то же предложение.
        $this->assertNotSame($temporary, $offer->number);
        $this->assertTrue(OfferNumber::isPublic($offer));
        $this->assertTrue(OfferNumber::find($temporary)->is($offer));
        // Маршрут — на входе сделки, а не на черновике.
        $this->assertSame($offer->stage()->workflow->dealEntry($deal)->id, $offer->stage()->id);
        // Забирает Роман — он исполнитель вывоза; страница предложения у него открыта.
        $this->assertSame(Channel::Manager, Transport::openFor($offer)->channel);
        $this->assertSame($this->roman->id, Transport::openFor($offer)->executor_id);
        $this->assertTrue($offer->isVisibleTo($this->roman));
    }

    /** Уже вывезли (21.10.2026): «Уже забрал» в сделку и «Уже у нас» в гараж — вывоз закрыт «Привёз» названной датой. */
    public function test_already_taken_closes_pickup_with_date(): void
    {
        $offer = $this->draft(1200000);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 150 000', 'commission' => '30 000', 'mode' => 'payout', 'scheme' => 'prime',
            'pickup' => 'taken', 'arrived_at' => '2026-10-15T12:00',
        ])->assertSessionHasNoErrors();
        $t = Transport::where('car_id', $offer->car_id)->latest('id')->firstOrFail();
        $this->assertSame(\App\Logistics\TransportState::Delivered, $t->state);
        $this->assertSame($this->roman->id, $t->executor_id);
        $this->assertSame('2026-10-15 12:00', $t->delivered_at->format('Y-m-d H:i'));
        $this->assertSame(\App\Offers\CarPlace::Keeper, $offer->fresh()->car_place);

        // Гараж «Себе», а вывоз уже висит «Без исполнителя» — закрывается датой, подготовка сразу.
        $other = $this->draft(null);
        $open = app(\App\Logistics\Actions\OpenPickup::class)($other->fresh(), $this->admin);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$other->number.'/give', [
            'to' => 'garage', 'whom' => 'self', 'where' => 'arrived', 'arrived_at' => '2026-06-25T12:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame(\App\Logistics\TransportState::Delivered, $open->fresh()->state);
        $this->assertSame('2026-06-25', $open->fresh()->delivered_at->format('Y-m-d'));
        $this->assertSame(CarState::Repair, Car::where('offer_id', $other->id)->firstOrFail()->state);
    }

    public function test_draft_without_price_goes_only_to_garage(): void
    {
        $offer = $this->draft(null);

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 000 000'])
            ->assertSessionHasErrors('give');
        $this->assertNull($offer->fresh()->deal);

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'manager_id' => $this->roman->id, 'payer' => 'manager', 'share' => '50 000', 'pickup' => 'manager', 'where' => 'owner',
        ])->assertSessionHasNoErrors();

        $deal = $offer->fresh()->deal;
        $this->assertSame(GaragePayer::Manager, $deal->garage_payer);
        $this->assertSame(50000, $deal->share);
        $this->assertSame(BidKind::Garage, $deal->bid->kind);
        $this->assertSame(CarState::Waiting, Car::where('offer_id', $offer->id)->firstOrFail()->state);
        $this->assertSame($this->roman->id, Transport::openFor($offer->fresh())->executor_id);
    }

    public function test_garage_without_manager_is_ours(): void
    {
        $offer = $this->draft(null);

        // «Кому → Менеджеру» без менеджера — ошибка, а не «под себя» молча (19.10.2026).
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'garage', 'whom' => 'manager', 'where' => 'arrived'])
            ->assertSessionHasErrors('manager_id');
        $this->assertNull($offer->fresh()->deal);

        // «Себе»: менеджер из формы не берётся, даже если пришёл.
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'garage', 'whom' => 'self', 'manager_id' => $this->roman->id, 'payer' => 'manager', 'pickup' => 'manager', 'where' => 'arrived'])
            ->assertSessionHasNoErrors();

        $deal = $offer->fresh()->deal;
        $this->assertNull($deal->bid_id);
        $this->assertNull($deal->buyer_id);
        $this->assertSame(GaragePayer::Us, $deal->garage_payer);
        $this->assertSame(CarState::Repair, Car::where('offer_id', $offer->id)->firstOrFail()->state);
    }

    /** Ошиблись, где ТС (20.10.2026, 488319/2026): «У страховой» → «Уже вывезли» из CRM, «Уже у нас» → «Ещё не вывезли». */
    public function test_where_car_is_can_be_corrected_both_ways(): void
    {
        $offer = $this->draft(null);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'garage', 'whom' => 'self', 'where' => 'owner'])
            ->assertSessionHasNoErrors();
        $this->assertSame(CarState::Waiting, Car::where('offer_id', $offer->id)->firstOrFail()->state);
        $this->actingAs($this->admin)->get(self::CRM.'/offers/'.$offer->number)->assertOk()->assertSee('Уже вывезли');
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/pickup/backdate', ['picked_at' => now()->subMonths(4)->format('Y-m-d\TH:i'), 'destination' => 'other', 'to_address' => 'Москва, Шоссе Энтузиастов'])
            ->assertSessionHasNoErrors();
        $this->assertSame(CarState::Repair, Car::where('offer_id', $offer->id)->firstOrFail()->state);

        $other = $this->draft(null);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$other->number.'/give', ['to' => 'garage', 'whom' => 'self', 'where' => 'arrived'])
            ->assertSessionHasNoErrors();
        $this->assertNull(Transport::openFor($other->fresh()));
        $this->actingAs($this->admin)->get(self::CRM.'/offers/'.$other->number)->assertOk()->assertSee('Ещё не вывезли');
        $this->actingAs($this->admin)->post(self::CRM.'/garage/cars/'.$other->number.'/waiting')->assertSessionHasNoErrors();
        $this->assertSame(CarState::Waiting, Car::where('offer_id', $other->id)->firstOrFail()->state);
        $this->assertSame(\App\Offers\CarPlace::Owner, $other->fresh()->car_place);
        $this->assertNotNull(Transport::openFor($other->fresh()));
    }

    public function test_open_offer_given_to_third_keeps_others_as_reserve(): void
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        app(EnterStage::class)($offer, Stage::where('workflow_id', $this->vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $denis = User::create(['name' => 'Денис', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer->viewers()->firstOrCreate(['user_id' => $denis->id], ['opens_at' => now()]);
        $reserve = app(PlaceBid::class)($offer->fresh(), $denis, 1100000);

        $offer = $offer->fresh();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 180 000'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($offer->fresh()->deal->buyer->is($this->roman));
        $this->assertSame(BidState::Active, $reserve->fresh()->state);

        // Резерв «Отдать» — тем же листом: сделка Романа отменяется, Денис — в сделке своей ценой.
        $this->actingAs($this->admin)->post(self::CRM.'/confirmations/'.$reserve->id.'/accept', ['commission' => '10 000'])->assertSessionHasNoErrors();
        $deal = $offer->fresh()->deal;
        $this->assertTrue($deal->buyer->is($denis));
        $this->assertSame(1100000, $deal->amount);
        $this->assertSame(1, Deal::where('offer_id', $offer->id)->where('state', DealState::Cancelled)->count());
    }

    public function test_price_at_floor_needs_consent(): void
    {
        $offer = $this->draft(1200000);

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '900 000'])
            ->assertSessionHasErrors('give');
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '900 000', 'loss' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(900000, $offer->fresh()->deal->amount);
    }

    public function test_cancel_after_giving_from_draft(): void
    {
        $offer = $this->draft(1200000);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', ['to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 150 000']);
        $offer = $offer->fresh();

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/state', ['state' => 'draft'])->assertSessionHasNoErrors();

        $offer = $offer->fresh();
        $this->assertSame(OfferState::Draft, $offer->state);
        $this->assertNull($offer->deal);
        $this->assertSame(OfferState::Draft, $offer->stage()->offer_state ?? OfferState::Draft);
    }

    /** «Не определено» (21.10.2026): вывоз заведён тихо, без исполнителя и с меткой; назначили — метки нет. */
    public function test_pickup_undecided_until_someone_is_assigned(): void
    {
        $offer = $this->draft(1200000);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'deal', 'manager_id' => $this->roman->id, 'amount' => '1 150 000', 'scheme' => 'prime', 'pickup' => 'later',
        ])->assertSessionHasNoErrors();

        $t = Transport::openFor($offer->fresh());
        $this->assertNull($t->channel);
        $this->assertTrue($t->undecided);
        $this->assertSame('кто вывозит, не определено', $t->next());

        $t->update(['channel' => Channel::Logist, 'state' => \App\Logistics\TransportState::Logist]);
        $this->assertFalse($t->fresh()->undecided);
    }

    /**
     * Иван взял в гараж, привёз, чинить не стал (21.10.2026): «Вернуть в продажу» оставляет машину у него — новый
     * покупатель забирает у него, «Забрал» сам не жмётся; машина уехала — метка гаснет.
     */
    public function test_garage_car_back_on_sale_stays_with_its_manager(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $offer = $this->draft(null);
        $brand = \App\Cars\Brand::create(['slug' => 'kia', 'name' => 'Kia']);
        $offer->update(['brand_id' => $brand->id, 'model_id' => \App\Cars\CarModel::create(['brand_id' => $brand->id, 'slug' => 'rio', 'name' => 'Rio'])->id]);
        $im = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($im);
        $offer->fresh()->addMediaFromString((string) ob_get_clean())->usingFileName('kadr.jpg')->toMediaCollection('photos');
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'manager_id' => $this->roman->id, 'payer' => 'manager', 'share' => '50 000', 'pickup' => 'manager', 'where' => 'arrived',
        ])->assertSessionHasNoErrors();
        $this->assertSame(\App\Offers\CarPlace::Keeper, $offer->fresh()->car_place);

        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->fresh()->number.'/resale', [
            'asking_price' => '1 300 000', 'floor_price' => '900 000', 'stays' => '1', 'inspection_address' => 'Кострома, Советская, 1',
        ])->assertSessionHasNoErrors();
        $offer = $offer->fresh();
        $this->assertSame(OfferState::Open, $offer->state);
        $this->assertSame($this->roman->id, $offer->held_by);
        $this->assertSame('Кострома, Советская, 1', $offer->inspection_address);
        $this->assertNull(Car::where('offer_id', $offer->id)->first());
        $this->assertFalse($offer->pickedUp());
        $this->actingAs($this->roman)->get('http://xcar.localhost/garage')->assertOk()->assertSee('продаёт XCar');

        $petr = User::create(['name' => 'Пётр Сидоров', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'deal', 'manager_id' => $petr->id, 'amount' => '1 250 000', 'scheme' => 'prime', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $offer = $offer->fresh();
        $t = Transport::openFor($offer);
        $this->assertSame($petr->id, $t->executor_id);
        $this->assertSame('Роман Корабельников', $t->ownerName());
        $handover = \App\Offers\Handover::for($offer->deal);
        $this->assertSame('У '.$this->roman->shortName(), $handover->where);
        $this->assertSame('Роман Корабельников', $handover->name);

        $offer->update(['car_place' => \App\Offers\CarPlace::Moving]);
        $this->assertNull($offer->fresh()->held_by);
    }
}
