<?php

namespace Tests\Feature;

use App\Billing\Actions\IssueDealInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Notifications\InvoiceNeededNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Этап оплаты ждёт того, у кого ход на самом деле: без счёта у ПРАЙМ — менеджера (указать покупателя, счёт встанет
 * сам), со счётом — менеджера (оплатить). Владелец 05.10.2026: сделка Belgee X50 пять дней стояла «Ждём менеджера» в CRM и «ждём нас» у
 * менеджера, и никто ничего не делал. Ошибка молчалива и стоит сделки — поэтому тест.
 */
class PayStepTurnTest extends TestCase
{
    use RefreshDatabase;

    public function test_pay_step_waits_for_us_until_invoice_then_for_manager(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);

        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1200000), $admin, 50000);

        // Идём по маршруту, пока не встанем на этап оплаты.
        for ($i = 0; $i < 10 && ! ($o = $offer->fresh())->stage()->isPayStep(); $i++) {
            $stage = $o->stage();
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label !== 'Отказываюсь');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains(mb_strtolower($e->label), 'отказ')), Actor::Staff, $admin);
        }
        $position = Position::where('offer_id', $offer->id)->where('track', Track::Sale)->firstOrFail();
        $this->assertTrue($position->stage->isPayStep());

        // Счёта нет, а схема — ПРАЙМ по счёту: счёт встанет сам, когда менеджер укажет покупателя (05.10.2026). Ход его,
        // без часов и без просьбы «Оплатите»; сотрудникам «Выставите счёт» не пишем.
        $this->assertSame(WaitsFor::Manager, $position->waitsFor());
        $this->assertFalse($position->awaitsInvoice());
        $this->assertNull($position->deadline_at);
        $this->assertFalse(Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists());
        $this->assertTrue(Position::whereKey($position->id)->waiting(WaitsFor::Manager)->exists());
        Notification::assertSentTo($manager, \App\Notifications\BuyerNeededNotice::class);
        Notification::assertNotSentTo($admin, InvoiceNeededNotice::class);

        // Выставили — ход менеджера: часы этапа от сейчас, просьба «Оплатите счёт».
        $party = Party::create(['kind' => PartyKind::Person, 'name' => 'Менеджер']);
        $invoice = app(IssueDealInvoice::class)($deal->fresh(), $admin, $party, ChargeKind::Sale, 1200000, now()->addDays(3));
        $position->refresh();
        $this->assertSame(WaitsFor::Manager, $position->waitsFor());
        $this->assertFalse($position->awaitsInvoice());
        $this->assertNotNull($position->deadline_at);
        $this->assertTrue($position->deadline_at->isFuture());
        $this->assertTrue(Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists());

        // Аннулировали единственный счёт — снова ход менеджера «Укажите покупателя», просьбы нет.
        app(VoidInvoice::class)($invoice->fresh(), $admin);
        $position->refresh();
        $this->assertSame(WaitsFor::Manager, $position->waits_for);
        $this->assertNull($position->deadline_at);
        $this->assertFalse(Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists());
    }
}
