<?php

namespace Tests\Feature;

use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\Give;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\BidKind;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Гараж «платим мы» → «платит менеджер» (13.11.2026, Сергей Николаев берёт Альфу себе и платит ей сам): «Отдать» заново тому
 * же менеджеру — сделка снова на «Согласии менеджера» (письмо о покупке не повторяется), дальше обычная ветка, и покупатель
 * для счёта поставщика нужен — сам менеджер с паспортом.
 */
class GaragePayerSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_to_manager_payer_returns_to_consent_and_asks_buyer(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Сергей Николаев', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $workflow = $vendor->fresh()->workflow(Track::Sale);

        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $workflow->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $manager, null, null, BidKind::Garage);
        $old = app(AcceptBid::class)($bid, $admin, payer: GaragePayer::Us, scheme: DealScheme::Insurer);

        // Поставщик согласовал, менеджер — «Забираю в гараж»: гаражная ветка «Оформляем на нас».
        $take = function (string $label, Actor $actor, User $by) use ($offer, &$old) {
            $o = $offer->fresh();
            $exit = $o->stage()->exitsFor($actor, $old->fresh())->first(fn ($e) => $e->label === $label);
            $this->assertNotNull($exit, $label.' на этапе '.$o->stage()->name);
            app(TakeExit::class)($o, $exit, $actor, $by);
        };
        $take('Поставщик согласовал', Actor::Staff, $admin);
        $take('Забираю в гараж', Actor::Manager, $manager);
        $this->assertSame('Оформляем на нас', $offer->fresh()->stage()->block->name);
        // Письмо «подтверждаем актуальность» на гаражном шаге ушло; вывоз — сам менеджер.
        $account = \Illuminate\Support\Facades\DB::table('mail_accounts')->insertGetId(['slug' => 'sales', 'title' => 'sales', 'email' => 'sales@xcar.ru', 'scope' => 'sales',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = \App\Mail\Thread::create(['account_id' => $account, 'subject' => 'Z', 'offer_id' => $offer->id]);
        $sent = \App\Mail\Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'out', 'from_email' => 'sales@xcar.ru',
            'subject' => 'RE: Z', 'text_body' => 'Подтверждаем актуальность', 'date_at' => now()]);
        $offer->fresh()->log(\App\Offers\OfferEventType::SupplierLetter, $admin, ['letter' => $sent->id]);
        app(\App\Garage\Actions\EnsurePickup::class)($offer->fresh(), $admin, $manager);
        $pickup = \App\Logistics\Transport::openFor($offer->fresh());
        $this->assertSame($manager->id, $pickup->executor_id);

        $deal = app(Give::class)(new Giving($offer->fresh(), $manager, garage: true, scheme: DealScheme::Insurer, payer: GaragePayer::Manager), $admin);

        $this->assertSame(DealState::Cancelled, $old->fresh()->state);
        $this->assertSame(GaragePayer::Manager, $deal->garage_payer);
        $this->assertSame(1, Car::where('offer_id', $offer->id)->where('state', CarState::Waiting)->count());
        $this->assertSame('Согласие менеджера', $offer->fresh()->stage()->name);
        $this->assertSame($manager->id, $pickup->fresh()->executor_id, 'вывоз остался за ним');
        $this->assertNotSame(\App\Logistics\TransportState::Cancelled, $pickup->fresh()->state);

        $old = $deal;
        $take('Беру в работу', Actor::Manager, $manager);
        $deal = $deal->fresh();
        $this->assertTrue($deal->needsBuyer(), 'покупатель для счёта Альфы');
        $this->assertSame('sent', $offer->fresh()->position(Track::Sale)->letterStatus(), 'письмо гаражного шага засчитано');
        $this->assertNotNull($deal->buyer_asked_at);

        app(SaveDealContract::class)(DealContract::for($deal), ['buyer_id' => 'me'], $manager);
        $this->assertSame($manager->fresh()->party_id, $deal->fresh()->supplierInvoicee()?->id);
    }
}
