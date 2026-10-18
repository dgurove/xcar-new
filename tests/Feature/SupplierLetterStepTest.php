<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\SendState;
use App\Mail\Thread;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Events\InsurerReplied;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Письмо поставщику — шаг (11.10.2026, начальник: «не понимаю, мы реально отправили письмо и ждём или ещё не
 * отправили»). После «Принять» у Альфы ход наш, пока письмо не ушло; ушло — ход поставщика, сутки от письма; ответил —
 * снова наш. Ошибка здесь молчалива: сделка «ждёт поставщика», которому никто не написал.
 */
class SupplierLetterStepTest extends TestCase
{
    use RefreshDatabase;

    private function accepted(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер Тест', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1200000), $admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer), ownerPrice: 900000);
    }

    private function thread(Offer $offer): Thread
    {
        $account = Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);

        return Thread::create(['account_id' => $account->id, 'subject' => 'Убыток', 'offer_id' => $offer->id]);
    }

    public function test_letter_moves_turn_to_supplier_and_reply_back_to_us(): void
    {
        $deal = $this->accepted();
        $position = $deal->offer->fresh()->position(Track::Sale);
        $this->assertTrue($position->stage->isLetterStep(), 'этап после «Принять» у Альфы — с письмом поставщику');
        $this->assertSame('unsent', $position->letterStatus());
        $this->assertSame(WaitsFor::Us, $position->waitsFor());
        $this->assertNull($position->deadline_at);
        // У Альфы этот шаг без срока с 18.10.2026 (владелец); срок от письма проверяем на этапе со сроком.
        $position->stage->update(['limit_minutes' => 1440]);

        $thread = $this->thread($deal->offer);
        $sentAt = now()->subHour();
        $letter = Message::create(['account_id' => $thread->account_id, 'thread_id' => $thread->id, 'direction' => Direction::Out,
            'subject' => 'Убыток', 'from_email' => 'deal@xcar.test', 'date_at' => $sentAt, 'sent_at' => $sentAt, 'send_state' => SendState::Sent]);
        MessageSent::dispatch($letter);

        $position = $deal->offer->fresh()->position(Track::Sale);
        $this->assertSame('sent', $position->letterStatus());
        $this->assertSame(WaitsFor::Supplier, $position->waitsFor());
        $this->assertLessThan(2, abs($position->deadline_at->diffInSeconds($sentAt->copy()->addMinutes($position->stage->limit_minutes))),
            'срок ответа — от письма: '.$position->deadline_at.' против '.$sentAt);
        $this->assertTrue($deal->offer->events()->where('type', OfferEventType::SupplierLetter)->exists());

        // Второе письмо ход не трогает.
        MessageSent::dispatch($letter);
        $this->assertSame(1, $deal->offer->events()->where('type', OfferEventType::SupplierLetter)->count());

        $reply = Message::create(['account_id' => $thread->account_id, 'thread_id' => $thread->id, 'direction' => Direction::In,
            'subject' => 'Re: Убыток', 'from_email' => 'agent@alfa.test', 'date_at' => now(), 'text_body' => 'Согласовано, ждём оплату']);
        InsurerReplied::dispatch($deal->fresh(), $reply);

        $position = $deal->offer->fresh()->position(Track::Sale);
        $this->assertSame('replied', $position->letterStatus());
        $this->assertSame(WaitsFor::Us, $position->waitsFor());
    }

    /** Кому письмо по продаже без письма вендора: «Реализация» не из копии, а нет её — общий ящик из копии (16.10.2026). */
    public function test_sale_addressee(): void
    {
        $alfa = Vendor::create(['name' => 'Альфа тест']);
        $alfa->contacts()->create(['name' => 'Третьи лица', 'role' => 'sales', 'email' => 'third@alfa.test', 'always_cc' => true]);
        $alfa->contacts()->create(['name' => 'Реализация', 'role' => 'sales', 'email' => 'implementation@alfa.test', 'is_default' => true]);
        $this->assertSame('implementation@alfa.test', $alfa->fresh()->saleAddressee());

        $t = Vendor::create(['name' => 'Т тест']);
        $t->contacts()->create(['name' => 'Убытки', 'role' => 'claims', 'email' => 'claims@t.test']);
        $this->assertNull($t->fresh()->saleAddressee());
        $t->contacts()->create(['name' => 'Реализация', 'role' => 'sales', 'email' => 'total@t.test', 'always_cc' => true]);
        $this->assertSame('total@t.test', $t->fresh()->saleAddressee());
        $this->assertNull($t->fresh()->saleTo());
    }
}
