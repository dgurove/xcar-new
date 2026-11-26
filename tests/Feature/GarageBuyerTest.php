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
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\SetCarPlace;
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
        $this->buyer = User::create(['name' => 'Пётр Покупателев', 'phone' => '79000000003', 'roles' => [Role::Buyer], 'manager_id' => $manager->id, 'approved_at' => now(), 'buyer_access_at' => now()]);
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
        $this->buyer->update(['garage_enabled' => true, 'garage_price_shown' => true]);
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

        // Поставщик согласовал — счёта нет, пока нет цены продажи и клиента (26.11.2026: договор и счёт — по цене продажи его клиенту).
        app(AgreeDeal::class)($deal->fresh(), $this->admin);
        $this->assertNull(Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Sale)->first());
        $this->assertSame('price', $deal->fresh()->invoiceGap());
        $n = $offer->fresh()->number;
        // Ниже его цены — отказ без суммы: его цена гаража бывает закупочной.
        $low = $this->actingAs($this->buyer)->put(self::SITE.'/garage/cars/'.$n.'/sale-price', ['sale_price' => '850 000']);
        $low->assertSessionHasErrors('sale_price');
        $this->assertStringNotContainsString('900', (string) session('errors')->first('sale_price'));
        $this->actingAs($this->buyer)->put(self::SITE.'/garage/cars/'.$n.'/sale-price', ['sale_price' => '1 000 000'])->assertSessionHasNoErrors();
        $this->assertSame(1000000, (int) $deal->fresh()->amount);
        $this->assertNull($deal->fresh()->commission);
        $this->assertNull(Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Sale)->first());
        $this->assertSame('buyer', $deal->fresh()->invoiceGap());
        // Клиент — «Я сам»: счёт ПРАЙМ на цену продажи одной строкой, договор — на неё же.
        $this->actingAs($this->buyer)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'me'])->assertSessionHasNoErrors();
        $invoice = Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Sale)->firstOrFail();
        $this->assertSame(1000000.0, (float) $invoice->total);
        $this->assertCount(1, $invoice->charges);
        $this->assertSame($this->buyer->fresh()->party_id, $invoice->party_id);
        $this->assertSame(1000000, (int) \App\Offers\DealContract::for($deal->fresh())->price);

        $n = $offer->fresh()->number;
        foreach (['/garage', '/garage/cars/'.$n, '/account/money', '/garage?vid=table'] as $path) {
            $html = $this->actingAs($this->buyer)->get(self::SITE.$path)->assertOk()->getContent();
            $this->assertClean($html);
        }
        // Его цены гаража нет нигде, и в «Сделках» тоже; цена продажи — есть.
        foreach (['/garage', '/garage/cars/'.$n, '/account/money', '/garage?vid=table', '/deals'] as $path) {
            $this->assertDoesNotMatchRegularExpression('/900(\s|&nbsp;|\x{00A0})?000/u', $this->actingAs($this->buyer)->get(self::SITE.$path)->getContent(), $path);
        }
        $this->assertMatchesRegularExpression('/1(\s|&nbsp;|\x{00A0})?000(\s|&nbsp;|\x{00A0})?000/u', $this->actingAs($this->buyer)->get(self::SITE.'/garage/cars/'.$n)->getContent());
        foreach (DatabaseNotification::where('notifiable_id', $this->buyer->id)->get() as $note) {
            $this->assertClean(json_encode($note->data, JSON_UNESCAPED_UNICODE));
        }
        // Чат по машине — с площадкой, не с его менеджером.
        $this->actingAs($this->buyer)->get(self::SITE.'/chats/offer/'.$n)->assertOk();

        // Продана — отметкой без суммы; счёт оплачен — расчёт закрыт.
        $deal->finish();
        $car->moveTo(CarState::Selling);
        $this->actingAs($this->buyer)->post(self::SITE.'/garage/cars/'.$n.'/sold')->assertSessionHasNoErrors();
        $this->assertSame(CarState::Sold, $car->fresh()->state);
        $this->assertNull($car->fresh()->sold_price);
        app(RecordPayment::class)($invoice->fresh(), $this->admin, 1000000, null, PaymentSource::Bank);
        $this->assertSame(CarState::Settled, $car->fresh()->state);
    }

    /** Без галки «Показывать цену покупателя» — только цена продажи: ни его цены, ни счёта, ни уведомления о нём (21.10.2026). */
    /**
     * Держатель ведёт сделку, как менеджер (26.11.2026, владелец про Ананьева): своей цены гаража не видит (закупочной тоже),
     * блок денег и документов на странице гаража, заводит своего клиента — только данными, без доступа и ссылки, — и видит его в
     * «Покупателях»; группы, приглашения и показы — только менеджеру.
     */
    public function test_holder_runs_deal_like_manager(): void
    {
        $this->buyer->update(['garage_enabled' => true, 'garage_price_shown' => false]);
        $offer = $this->draft();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '753 210', 'where' => 'arrived',
        ])->assertSessionHasNoErrors();
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        app(AgreeDeal::class)($car->deal->fresh(), $this->admin);

        $n = $offer->fresh()->number;
        $buyer = $this->buyer->fresh();
        // Его цены гаража (бывает закупочной) не видно нигде (26.11.2026).
        foreach (['/garage', '/garage/cars/'.$n, '/account/money', '/garage?vid=table', '/deals'] as $path) {
            $html = $this->actingAs($buyer)->get(self::SITE.$path)->assertOk()->getContent();
            $this->assertClean($html);
            $this->assertDoesNotMatchRegularExpression('/753(\s|&nbsp;|\x{00A0})?210/u', $html, $path);
        }

        // Свой клиент — данными, в сделку; ссылкой и «С доступом» — нет.
        $this->assertTrue($buyer->keepsBuyers());
        $this->actingAs($buyer)->postJson(self::SITE.'/people', ['kind' => 'buyer', 'mode' => 'link'])->assertForbidden();
        $this->actingAs($buyer)->postJson(self::SITE.'/people', ['kind' => 'buyer', '_person' => ['first_name' => 'Иван', 'last_name' => 'Клиентов', 'access' => 'open', 'contact' => '+79990001122']])->assertForbidden();
        $this->actingAs($buyer)->postJson(self::SITE.'/people', ['kind' => 'buyer', 'deal' => $car->deal_id, '_person' => ['first_name' => 'Иван', 'last_name' => 'Клиентов']])->assertOk();
        $client = User::where('last_name', 'Клиентов')->firstOrFail();
        $this->assertSame($buyer->id, $client->manager_id);
        $this->assertFalse($client->hasBuyerAccess());

        $this->actingAs($buyer)->get(self::SITE.'/buyers')->assertOk()->assertSee('Иван Клиентов')->assertDontSee('Новая группа');
        $this->actingAs($buyer)->get(self::SITE.'/buyers/'.$client->id)->assertOk()->assertDontSee('Доступ к предложениям');
        $this->actingAs($buyer)->get(self::SITE.'/buyers/invites')->assertNotFound();
    }

    /**
     * После закрытой сделки (поставщику заплатили) держатель, пока машина продаётся, по-прежнему называет клиента; его ход — словами;
     * оплаченная до 26.11 без цены продажи получает её из оплаты (`garage:holder-sale`).
     */
    public function test_holder_keeps_deal_after_close_and_paid_gets_price(): void
    {
        $this->buyer->update(['garage_enabled' => true]);
        $offer = $this->draft();
        $this->give($offer)->assertSessionHasNoErrors();
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        app(AgreeDeal::class)($car->deal->fresh(), $this->admin);
        $car->moveTo(CarState::Selling);
        $car->deal->finish();
        $deal = $car->deal->fresh();
        $this->assertFalse($deal->isLive());
        $this->assertTrue($deal->holderEditable());
        $view = \App\Garage\GarageView::for($car->fresh(), $this->buyer->fresh());
        $this->assertSame('Указать цену продажи', \App\Offers\Turn::ofGarage($car->fresh(), $this->buyer->fresh(), $view)?->title);

        $n = $offer->fresh()->number;
        $this->actingAs($this->buyer)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'me'])->assertSessionHasNoErrors();
        $this->assertSame($this->buyer->id, \App\Offers\DealContract::for($deal->fresh())->buyer_user_id);
        $this->actingAs($this->buyer)->get(self::SITE.'/garage/cars/'.$n)->assertOk()->assertSee('ООО «ПРАЙМ»', false);

        // Оплачено по старой цене, цены продажи нет — команда ставит её из оплаты, договор встаёт на неё же.
        $this->actingAs($this->buyer)->put(self::SITE.'/garage/cars/'.$n.'/sale-price', ['sale_price' => '1 000 000'])->assertSessionHasNoErrors();
        $invoice = Invoice::where('deal_id', $deal->id)->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->firstOrFail();
        app(RecordPayment::class)($invoice, $this->admin, 1000000, null, PaymentSource::Bank);
        $deal->fresh()->update(['amount' => null]);
        $this->artisan('garage:holder-sale', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1000000, (int) $deal->fresh()->amount);
        $this->assertSame(1000000, (int) \App\Offers\DealContract::for($deal->fresh())->price);
    }

    /** Покупатель без менеджера — только свой гараж: ни предложений, ни галереи, шторка сохраняет «Без менеджера» (21.10.2026). */
    public function test_buyer_without_manager_sees_only_garage(): void
    {
        $this->buyer->update(['manager_id' => null, 'garage_enabled' => true]);
        $this->assertTrue($this->buyer->fresh()->onlyGarage());
        $offer = $this->draft();
        $this->give($offer)->assertSessionHasNoErrors();
        $gallery = Offer::create(['vendor_id' => $this->vendor->id, 'asking_price' => 500000, 'gallery_from' => now(), 'gallery_until' => now()->addDay()]);

        $buyer = $this->buyer->fresh();
        foreach (['/offers', '/gallery', '/account/interests'] as $path) {
            $this->actingAs($buyer)->get(self::SITE.$path)->assertRedirect('/garage');
        }
        $this->actingAs($buyer)->get(self::SITE.'/garage')->assertOk();
        $this->actingAs($buyer)->get(self::SITE.'/garage/cars/'.$offer->fresh()->number)->assertOk();
        $this->assertFalse(Offer::visibleTo($buyer)->whereKey($gallery->id)->exists());
        $this->assertFalse($buyer->canInterestIn($gallery->fresh()));

        $this->actingAs($this->admin)->put(self::CRM.'/settings/users/'.$buyer->id, [
            'first_name' => 'Пётр', 'last_name' => 'Покупателев', 'manager_id' => '', 'garage_enabled' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertNull($buyer->fresh()->manager_id);
    }

    public function test_admin_edits_price_and_pickup(): void
    {
        $this->buyer->update(['garage_enabled' => true, 'garage_price_shown' => true]);
        $offer = $this->draft();
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '900 000', 'where' => 'owner', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $n = $offer->fresh()->number;
        app(AgreeDeal::class)($car->deal->fresh(), $this->admin);

        // Его цена — наш предел, счёт её не трогает; цена продажи правится сотрудником (и ниже его цены): счёт на прежнюю гаснет,
        // новый — на новую (26.11.2026).
        $this->actingAs($this->admin)->put(self::CRM.'/garage/cars/'.$n.'/buyer-price', ['buyer_price' => '950 000'])->assertSessionHasNoErrors();
        \App\Offers\DealContract::for($car->deal->fresh())->update(['buyer_user_id' => $this->buyer->id, 'payer' => 'manager']);
        $this->actingAs($this->admin)->put(self::CRM.'/garage/cars/'.$n.'/sale-price', ['sale_price' => '1 100 000'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(self::CRM.'/garage/cars/'.$n.'/sale-price', ['sale_price' => '940 000'])->assertSessionHasNoErrors();
        $live = Invoice::where('deal_id', $car->deal_id)->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->get();
        $this->assertCount(1, $live);
        $this->assertSame(940000.0, (float) $live->first()->total);
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
     * шторке, свою машину везёт «сам», но задача — логистики (видна ему, ссылка `/trip`), а прямого исполнителя XCAR
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
        app(SetCarPlace::class)($offer, CarPlace::Elsewhere, $this->admin);
        $this->actingAs($this->admin)->post(self::CRM.'/offers/'.$offer->number.'/give', [
            'to' => 'garage', 'whom' => 'buyer', 'manager_id' => $this->buyer->id, 'buyer_price' => '900 000', 'where' => 'arrived', 'pickup' => 'manager',
        ])->assertSessionHasNoErrors();
        $this->assertSame(CarState::Repair, Car::where('offer_id', $offer->id)->firstOrFail()->state);
        $this->assertSame(CarPlace::Keeper, $offer->fresh()->car_place);
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
