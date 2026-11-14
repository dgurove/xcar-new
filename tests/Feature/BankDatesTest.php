<?php

namespace Tests\Feature;

use App\Billing\Bank\Actions\LinkTransaction;
use App\Billing\Bank\CarMatch;
use App\Billing\Bank\Transaction;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Выписка Сбера → «Продано» и «Оплачено» (14.11.2026, владелец: «почему нигде не проставлены даты — связь со СберБизнесом же
 * есть»): поступление с VIN — первая дата продажи, платёж Совкомбанку «по договору комиссии № …» — «Оплачено». Ошибка
 * молчалива: даты просто не встанут, как не вставали.
 */
class BankDatesTest extends TestCase
{
    use RefreshDatabase;

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '238163/2026', 'vin' => 'XTA21140064123456', 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1200000), $admin, 50000, CommissionMode::Payout, scheme: DealScheme::Prime)->fresh();
    }

    private function tx(string $dir, string $day, string $purpose, ?string $inn = null): Transaction
    {
        return Transaction::create(['external_id' => uniqid(), 'account' => '1', 'booked_at' => $day, 'direction' => $dir, 'amount' => 100000,
            'counterparty' => 'Кто-то', 'counterparty_inn' => $inn, 'purpose' => $purpose, 'state' => $dir === 'in' ? Transaction::IGNORED : Transaction::OUTGOING]);
    }

    public function test_bank_rows_set_sold_and_paid(): void
    {
        $deal = $this->deal();
        $link = app(LinkTransaction::class);
        $link($this->tx('in', '2026-11-10', 'Оплата TC LADA 2014 г.в. vin: XTA21140064123456. НДС не облагается'));
        $link($this->tx('in', '2026-11-05', 'Аванс за ТС vin XTA21140064123456'));
        $this->assertSame('2026-11-05', $deal->fresh()->paid_in_at?->toDateString(), 'первый платёж');

        $out = $link($this->tx('out', '2026-11-12', 'Оплата за поврежденный автомобиль LADA, р/н У757РУ152, по договору комиссии № 238163/2026. НДС не облагается', '7812016906'));
        $this->assertTrue($out?->is($deal));
        $this->assertSame('2026-11-12', $deal->fresh()->supplier_paid_at?->toDateString());
    }

    public function test_two_cars_are_not_guessed(): void
    {
        $this->deal();
        Offer::create(['claim_ref' => '777777/2026', 'vin' => 'XTA21140064999999'])->forceFill(['state' => OfferState::Draft])->save();
        $this->assertNull(CarMatch::of('vin XTA21140064123456 и XTA21140064999999'));
        $this->assertSame('claim', CarMatch::of('по договору комиссии № 238163/2026')['by'] ?? null);
    }
}
