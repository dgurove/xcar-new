<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\PaymentSource;
use App\Garage\Actions\AddCost;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
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

    public function test_super_buyer_garage_bid_only_with_toggle(): void
    {
        $super = User::create(['name' => 'Сергей Супер', 'phone' => '79000000004', 'roles' => [Role::SuperBuyer], 'approved_at' => now()]);
        $offer = $this->draft();
        $this->assertFalse($offer->garageAllowedFor($super));
        $super->update(['garage_enabled' => true]);
        $this->assertTrue($offer->garageAllowedFor($super->fresh()));
    }
}
