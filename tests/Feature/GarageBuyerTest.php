<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Garage\Actions\AddCost;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Offers\Actions\AgreeDeal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Preset;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Гараж покупателю по тумблеру (20.10.2026, владелец: «покупатель нигде ни в коем случае не должен видеть закупочные цены,
 * даже если ему включили гараж»). Утечка здесь молчалива и дорога — поэтому тест: закупочная 612 345 и наш расход 15 432
 * не появляются ни на одной его странице и ни в одном уведомлении; без тумблера в гараж ему не отдать.
 */
class GarageBuyerTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private const SITE = 'http://xcar.localhost';

    private User $admin;

    private User $buyer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->buyer = User::create(['name' => 'Пётр Покупателев', 'phone' => '79000000003', 'roles' => [Role::Buyer], 'manager_id' => $manager->id, 'approved_at' => now()]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Alfa);
    }

    private function draft(): Offer
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 612345, 'asking_price' => 900000]);

        return app(StartRoute::class)($offer->fresh(), $this->admin, Track::Sale)->fresh();
    }

    private function give(Offer $offer)
    {
        return $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '900 000', 'where' => 'arrived',
        ]);
    }

    /** Ни одной формы закупочной и нашего расхода в ответе. */
    private function assertClean(string $html): void
    {
        foreach (['612345', '612 345', "612\u{00A0}345", '612&nbsp;345', '15432', '15 432', "15\u{00A0}432", '15&nbsp;432'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, 'Покупатель видит закупочную или наш расход: '.$leak);
        }
    }

    public function test_without_toggle_buyer_gets_no_garage(): void
    {
        $offer = $this->draft();
        $this->give($offer)->assertSessionHasErrors('manager_id');
        $this->assertNull(Car::where('offer_id', $offer->id)->first());
        $this->assertFalse($this->buyer->fresh()->canGarage());
        $this->assertContains($this->actingAs($this->buyer)->get(self::SITE.'/garage')->status(), [403, 404]);
    }

    public function test_buyer_garage_shows_only_his_price(): void
    {
        $this->buyer->update(['garage_enabled' => true]);
        $offer = $this->draft();
        $this->give($offer)->assertSessionHasNoErrors()->assertRedirect();

        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $deal = $car->deal;
        $this->assertTrue($car->forBuyer());
        $this->assertSame(900000, $car->buyer_price);
        $this->assertSame(GaragePayer::Us, $deal->garage_payer);
        $this->assertNull($deal->commission);
        // Наш эвакуатор — в его цене, ему не виден.
        app(AddCost::class)($car, ['title' => 'Эвакуатор', 'amount' => 15432, 'paid_by' => 'xcar'], $this->admin);

        // Поставщик согласовал — счёт ПРАЙМ на его цену одной строкой, без закупочной.
        app(AgreeDeal::class)($deal->fresh(), $this->admin);
        $invoice = Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Sale)->firstOrFail();
        $this->assertSame(900000.0, (float) $invoice->total);
        $this->assertCount(1, $invoice->charges);
        $this->assertSame($this->buyer->fresh()->party_id, $invoice->party_id);

        $n = $offer->fresh()->number;
        foreach (['/garage', '/garage/cars/'.$n, '/account/money', '/garage?vid=table'] as $path) {
            $html = $this->actingAs($this->buyer)->get(self::SITE.$path)->assertOk()->getContent();
            $this->assertClean($html);
        }
        $this->assertStringContainsString('900', $this->actingAs($this->buyer)->get(self::SITE.'/garage/cars/'.$n)->getContent());
        foreach (DatabaseNotification::where('notifiable_id', $this->buyer->id)->get() as $note) {
            $this->assertClean(json_encode($note->data, JSON_UNESCAPED_UNICODE));
        }
        // Чат по машине — с площадкой, не с его менеджером.
        $this->actingAs($this->buyer)->get(self::SITE.'/account/chats/offer/'.$n)->assertOk();

        // Продана — отметкой без суммы; счёт оплачен — расчёт закрыт.
        $deal->update(['state' => DealState::Done]);
        $car->moveTo(CarState::Selling);
        $this->actingAs($this->buyer)->post(self::SITE.'/garage/cars/'.$n.'/sold')->assertSessionHasNoErrors();
        $this->assertSame(CarState::Sold, $car->fresh()->state);
        $this->assertNull($car->fresh()->sold_price);
        app(RecordPayment::class)($invoice->fresh(), $this->admin, 900000, null, PaymentSource::Bank);
        $this->assertSame(CarState::Settled, $car->fresh()->state);
    }

    public function test_admin_edits_price_and_pickup(): void
    {
        $this->buyer->update(['garage_enabled' => true]);
        $offer = $this->draft();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '900 000', 'where' => 'owner', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $n = $offer->fresh()->number;
        app(AgreeDeal::class)($car->deal->fresh(), $this->admin);

        // Цена правится: счёт на прежнюю гаснет, новый — на новую.
        $this->actingAs($this->admin)->put(self::CRM.'/garage/cars/'.$n.'/buyer-price', ['buyer_price' => '950 000'])->assertSessionHasNoErrors();
        $live = Invoice::where('deal_id', $car->deal_id)->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->get();
        $this->assertCount(1, $live);
        $this->assertSame(950000.0, (float) $live->first()->total);
        // Заплатил — цену не поправить и машину не забрать.
        app(RecordPayment::class)($live->first()->fresh(), $this->admin, 100000, null, PaymentSource::Bank);
        $this->actingAs($this->admin)->put(self::CRM.'/garage/cars/'.$n.'/buyer-price', ['buyer_price' => '800 000'])->assertSessionHasErrors('buyer_price');
        $this->assertSame(950000, $car->fresh()->buyer_price);

        // Забирает сам → логистика → снова сам.
        $t = Transport::openFor($offer->fresh());
        $this->assertSame(Channel::Manager, $t->channel);
        $this->assertSame($this->buyer->id, $t->executor_id);
        $driver = User::create(['name' => 'Иван Водитель', 'phone' => '79000000009', 'roles' => [Role::Driver], 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$n.'/pickup', ['channel' => 'staff', 'who' => ['staff' => $driver->id], 'reach' => 'local', 'destination' => 'keeper'])->assertSessionHasNoErrors();
        $this->assertSame($driver->id, $t->fresh()->executor_id);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$n.'/pickup', ['channel' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame(Channel::Manager, $t->fresh()->channel);
        $this->assertSame($this->buyer->id, $t->fresh()->executor_id);
    }

    /**
     * Покупатель с гаражом — ещё и исполнитель вывоза (21.10.2026, 756998: Лобус сам вывозит, чинит и продаёт): галка в
     * шторке, свою машину везёт «сам», но задача — логистики (видна ему, ссылка `/trip`), а прямого исполнителя XCar
     * ставят и на чужие вывозы («Свой исполнитель»).
     */
    public function test_buyer_contractor_carries_own_car_in_logistics_and_others(): void
    {
        $manager = $this->buyer->manager_id;
        $this->actingAs($this->admin)->put(self::CRM.'/settings/users/'.$this->buyer->id, [
            'first_name' => 'Пётр', 'last_name' => 'Покупателев', 'manager_id' => $manager, 'garage_enabled' => '1', 'contractor' => '1',
        ])->assertSessionHasNoErrors();
        $buyer = $this->buyer->fresh();
        $this->assertTrue($buyer->isBuyer() && $buyer->isContractor() && $buyer->garageBuyer());

        $offer = $this->draft();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $buyer->id, 'buyer_price' => '900 000', 'where' => 'owner', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $t = Transport::openFor($offer->fresh());
        $this->assertSame(Channel::Manager, $t->channel);
        $this->assertFalse($t->isSelfPickup());
        $this->assertTrue(Transport::visibleTo($buyer)->whereKey($t->id)->exists(), 'своя задача видна ему в логистике');
        $this->assertNotNull($t->trip_code);
        $this->get('http://logistics.xcar.localhost/trip/'.$t->trip_code)->assertOk();

        // Чужая машина — «Свой исполнитель».
        $other = $this->draft();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$other->number.'/pickup', [
            'channel' => 'partner', 'who' => ['partner' => $buyer->id], 'reach' => 'local', 'destination' => 'other', 'to_address' => 'Москва',
        ])->assertSessionHasNoErrors();
        $o = Transport::openFor($other->fresh());
        $this->assertSame(Channel::Partner, $o->channel);
        $this->assertSame($buyer->id, $o->executor_id);
    }

    /** «Уже у покупателя», когда ТС уже вывезли (617472, «Уже вывезли» к Ананьеву): сразу «Подготовка», а не «Ждёт ТС». */
    public function test_arrived_when_already_picked_up_starts_prep(): void
    {
        $this->buyer->update(['garage_enabled' => true]);
        $offer = $this->draft();
        app(\App\Workflow\Actions\SetCarPlace::class)($offer, \App\Offers\CarPlace::Elsewhere, $this->admin);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '900 000', 'where' => 'arrived', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $this->assertSame(CarState::Repair, Car::where('offer_id', $offer->id)->firstOrFail()->state);
        $this->assertSame(\App\Offers\CarPlace::Keeper, $offer->fresh()->car_place);
    }

    public function test_super_buyer_garage_bid_only_with_toggle(): void
    {
        $super = User::create(['name' => 'Сергей Супер', 'phone' => '79000000004', 'roles' => [Role::SuperBuyer], 'approved_at' => now()]);
        $offer = $this->draft();
        $this->assertFalse($offer->garageAllowedFor($super));
        $super->update(['garage_enabled' => true]);
        $this->assertTrue($offer->garageAllowedFor($super->fresh()));
    }
}
