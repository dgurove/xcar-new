<?php

namespace Tests\Feature;

use App\Billing\Bank\Actions\LinkTransaction;
use App\Billing\Bank\Transaction;
use App\Billing\DealDebt;
use App\Billing\Invoice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
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
 * Деньги пришли — шаг оплаты закрыт (17.11.2026, владелец: «если деньги уже пришли, значит счёт вне системы выставили — его
 * тут никогда не выставят»): на «Оплате счёта» стояли 26 сделок с деньгами в выписке и без счёта. Ошибка молчалива — шаг
 * просто стоит, как стоял.
 */
class MoneyInTest extends TestCase
{
    use RefreshDatabase;

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Виталий Зайцев', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '645460/2026', 'vin' => 'XTA21140064123456', 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        $stages = Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id);
        app(EnterStage::class)($offer, (clone $stages)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1200000), $admin, 50000, scheme: DealScheme::Prime);
        // Счёт вне системы: нашего счёта менеджеру нет.
        Invoice::where('deal_id', $deal->id)->delete();
        app(EnterStage::class)($offer->fresh(), (clone $stages)->where('name', 'Оплата счёта')->firstOrFail());

        return $deal->fresh();
    }

    private function money(int $amount, string $day = '2026-11-10'): void
    {
        app(LinkTransaction::class)(Transaction::create(['external_id' => uniqid(), 'account' => '1', 'booked_at' => $day, 'direction' => 'in', 'amount' => $amount,
            'counterparty' => 'ИП Зайцев', 'purpose' => 'Оплата ТС vin XTA21140064123456', 'state' => Transaction::UNMATCHED]));
    }

    private function stage(Deal $deal): string
    {
        return Offer::find($deal->offer_id)->position(Track::Sale)->stage->name;
    }

    public function test_money_without_invoice_passes_pay_step(): void
    {
        $deal = $this->deal();
        $this->assertSame('Оплата счёта', $this->stage($deal));

        // Пришла часть — шаг стоит, долг — остаток словами.
        $this->money(400000);
        $this->assertSame('Оплата счёта', $this->stage($deal));
        $debt = DealDebt::of($deal->fresh());
        $this->assertSame(800000.0, $debt->owed);
        $this->assertSame('пришло 400 000 из 1 200 000', str_replace("\u{00A0}", ' ', $debt->status));

        // Пришло остальное — шаг прошёл сам, в «Истории» — сколько пришло.
        $this->money(800000, '2026-11-12');
        $this->assertNotSame('Оплата счёта', $this->stage($deal));
        $this->assertTrue(Offer::find($deal->offer_id)->events()->where('payload->text', 'like', 'Деньги пришли без счёта%')->exists());
    }

    public function test_sold_by_hand_passes_pay_step(): void
    {
        $deal = $this->deal();
        app(\App\Offers\Actions\SetDealDate::class)($deal, \App\Offers\DealDates::SOLD, \Illuminate\Support\Carbon::parse('2026-11-10'), User::where('phone', '79000000001')->first());
        $this->assertNotSame('Оплата счёта', $this->stage($deal));
    }
}
