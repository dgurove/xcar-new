<?php

namespace Tests\Feature;

use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidKind;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\PriceHistory;
use App\Park\Actions\UpdateVehicle;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Закупочная одна и с историей (14.10.2026, после файла Carcade): правка пишет было → стало; цена менеджера закреплена с
 * его подтверждения (15.10.2026) — новая закупочная у сделки только поставщика, у опубликованного менеджеры видят
 * прежнюю; принять подтверждение не выше закупочной можно только с согласия. Ошибка здесь молчалива: сделка в минус,
 * менеджер видит скидку поставщика или счёт перевыставлен на другую цену.
 */
class PriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function offer(int $floor = 183840): Offer
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер Тест', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Каркаде тест']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => $floor, 'asking_price' => 300000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);

        return $offer->fresh();
    }

    public function test_update_keeps_was_and_now_with_reason(): void
    {
        $offer = $this->offer();
        app(UpdateOffer::class)($offer, ['floor_price' => 211200, 'asking_price' => 320000, 'description' => 'Длинный текст'], $this->admin, ['why' => 'По СМС Марины']);

        $event = $offer->events()->where('type', OfferEventType::Updated)->firstOrFail();
        $this->assertSame([183840, 211200], $event->payload['changes']['floor_price']);
        $this->assertArrayNotHasKey('description', $event->payload['changes']);
        $this->assertSame('По СМС Марины: закупочная цена 183 840 → 211 200 ₽, цена продажи 300 000 → 320 000 ₽, описание', str_replace("\u{A0}", ' ', $event->text()));

        $history = PriceHistory::of($offer);
        $this->assertSame(183840, $history->previous('floor_price'));
        $this->assertSame('По СМС Марины', $history->rows('floor_price')[0]['why']);
    }

    public function test_new_floor_after_acceptance_is_only_the_suppliers(): void
    {
        $offer = $this->offer(250000);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 300000), $this->admin, 10000, CommissionMode::Payout, scheme: DealScheme::Prime);
        $this->assertSame(250000, $deal->cost);

        // Цена менеджера закреплена с подтверждения (15.10.2026): новая закупочная — только поставщика, у админа.
        app(UpdateOffer::class)($offer->fresh(), ['floor_price' => 260000], $this->admin);
        $this->assertSame(250000, $deal->fresh()->cost);
        $this->assertSame(260000, $deal->fresh()->supplierCost());
        $this->assertTrue($offer->events()->where('type', OfferEventType::Updated)->get()->contains(fn ($e) => ($e->payload['changes']['supplier_cost'] ?? null) === [null, 260000]));

        // Вернули прежнюю — своей закупочной поставщика у сделки больше нет.
        app(UpdateOffer::class)($offer->fresh(), ['floor_price' => 250000], $this->admin);
        $this->assertNull($deal->fresh()->supplier_cost);
    }

    public function test_dkp_deal_keeps_owner_price_and_invoice_after_new_floor(): void
    {
        $offer = $this->offer(250000);
        // ДКП без маршрута: согласовано сразу, подбор менеджеру 300 − 250 − 10 = 40 000 по ссылке.
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 300000), $this->admin, 10000, CommissionMode::Payout, scheme: DealScheme::OwnerDkp);
        $this->assertSame(250000, $deal->fresh()->owner_price);
        $invoice = $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Selection)->sole();
        $this->assertSame(40000.0, (float) $invoice->total);

        // Ни собственнику, ни подбору новая закупочная не указ: договор и счёт менеджера прежние.
        app(UpdateOffer::class)($offer->fresh(), ['floor_price' => 260000], $this->admin);
        $this->assertSame(250000, $deal->fresh()->cost);
        $this->assertSame(250000, $deal->fresh()->owner_price);
        $this->assertSame($invoice->id, $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Selection)->where('state', '!=', InvoiceState::Void)->sole()->id);
        $this->assertSame(260000, $offer->fresh()->floor_price);
    }

    public function test_published_offer_keeps_declared_without_writing_it(): void
    {
        $offer = $this->offer(183840);
        app(UpdateOffer::class)($offer, ['floor_price' => 211200], $this->admin);

        // Менеджеры видят прежнюю закупочную и заявленную от неё; заявленную за админа никто не вписывал.
        $offer = $offer->fresh();
        $this->assertNull($offer->publish_price);
        $this->assertSame(183840, $offer->bidFloor());
        $this->assertSame(184000, $offer->declaredPrice());
        $this->assertSame(183840, app(PlaceBid::class)($offer, $this->manager, 300000)->cost);

        // Вернулось в черновики — будет новая оценка от новой закупочной.
        app(ChangeOfferState::class)($offer, OfferState::Draft, $this->admin);
        $this->assertSame(212000, $offer->fresh()->declaredPrice());
    }

    public function test_bid_at_or_below_floor_needs_consent(): void
    {
        $offer = $this->offer(300000);
        $bid = app(PlaceBid::class)($offer, $this->manager, 300000);
        try {
            app(AcceptBid::class)($bid, $this->admin, 0, CommissionMode::Payout, scheme: DealScheme::Prime);
            $this->fail('приняли не выше закупочной молча');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bid', $e->errors());
        }
        $this->assertNull($offer->fresh()->deal);
        $deal = app(AcceptBid::class)($bid->fresh(), $this->admin, 0, CommissionMode::Payout, scheme: DealScheme::Prime, loss: true);
        $this->assertSame(300000, $deal->amount);
    }

    public function test_garage_price_is_the_deal_cost(): void
    {
        $offer = $this->offer(250000);
        $offer->update(['garage_allowed' => true]);
        $bid = app(PlaceBid::class)($offer->fresh(), $this->manager, null, null, BidKind::Garage);
        $deal = app(AcceptBid::class)($bid, $this->admin, payer: GaragePayer::Us, scheme: DealScheme::Prime);
        $car = $offer->fresh()->garageCar;
        $this->assertNull($car->cost);
        $this->assertSame(250000, $car->base());

        app(UpdateOffer::class)($offer->fresh(), ['floor_price' => 240000], $this->admin);
        $this->assertSame(240000, $car->fresh()->base());
        $this->assertSame(240000, $car->fresh()->price());
    }

    public function test_vehicle_update_keeps_was_and_now(): void
    {
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vehicle = Vehicle::create(['ref' => 'AUT-26-1', 'state' => VehicleState::Expected, 'value' => 500000, 'year' => 2019]);
        app(UpdateVehicle::class)($vehicle, ['value' => 520000, 'year' => 2020], $admin);

        $event = $vehicle->events()->where('type', EventType::Updated)->firstOrFail();
        $this->assertSame('Изменена: оценочная стоимость 500 000 → 520 000 ₽, год 2019 → 2020', str_replace("\u{A0}", ' ', $event->text()));
    }
}
