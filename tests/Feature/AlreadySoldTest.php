<?php

namespace Tests\Feature;

use App\Notifications\DealStepNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\MarkContractSigned;
use App\Offers\Actions\MarkSoldDeal;
use App\Offers\Actions\PlaceBid;
use App\Offers\CarPlace;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Уже продана…» и «Договор уже подписан» (14.11.2026): давно проданная закрывается одним нажатием — сделка закрыта датой
 * продажи, «Выдан», ТС у покупателя, менеджеру ничего; договор «Ещё нет» — сделка встаёт на шаге договора и живёт дальше.
 * Ошибка молчалива: сделка закрылась бы мимо договора или повисла бы навсегда.
 */
class AlreadySoldTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $o = $offer->fresh();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === 'Поставщик согласовал' && $e->fits($deal->fresh()));
        app(TakeExit::class)($o, $exit, $exit->actor, $this->admin);

        return $deal->fresh();
    }

    public function test_already_sold_closes_quietly(): void
    {
        $deal = $this->deal();
        Notification::fake();
        $deal = app(MarkSoldDeal::class)($deal, $this->admin, true, null, true, Carbon::parse('2026-11-10'));

        $this->assertSame(DealState::Done, $deal->state);
        $this->assertSame('2026-11-10', $deal->closed_at->toDateString());
        $this->assertNotNull($deal->contract_signed_at);
        $offer = $deal->offer->fresh();
        $this->assertSame(OfferState::Delivered, $offer->state);
        $this->assertSame('Сделка закрыта', $offer->stage()->name);
        $this->assertSame(CarPlace::Buyer, $offer->car_place);
        Notification::assertNotSentTo($this->manager, DealStepNotice::class);
    }

    public function test_contract_not_signed_stops_on_contract_step(): void
    {
        $deal = app(MarkSoldDeal::class)($this->deal(), $this->admin, false, null, true, Carbon::today());

        $this->assertSame(DealState::Active, $deal->state);
        $this->assertTrue($deal->offer->fresh()->stage()->aboutContract(), 'встала на «'.$deal->offer->fresh()->stage()->name.'»');
        $this->assertNull($deal->contract_signed_at);
    }

    public function test_contract_signed_drops_buyer_ask(): void
    {
        $deal = $this->deal();
        $deal->forceFill(['buyer_asked_at' => now()])->save();
        $deal = app(MarkContractSigned::class)($deal, $this->admin);

        $this->assertTrue($deal->contractSigned());
        $this->assertFalse($deal->needsBuyer());
        $this->assertNull($deal->buyer_asked_at);
        $this->assertSame(DealState::Active, $deal->state);
    }
}
