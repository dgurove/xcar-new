<?php

namespace Tests\Feature;

use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Listeners\AdvanceOnReply;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Разобрать ответ поставщика» (28.10.2026, 0385/046/07142/26 и 0890/046/10323/25): наш ответ поставщику после его письма
 * и «Ответ не нужен» — снова ждём его, а не висим в «Ваш ход»; «Продал другому» в окне письма закрывает сделку отказом.
 */
class SupplierReplyHandledTest extends TestCase
{
    use RefreshDatabase;

    public function test_our_reply_after_supplier_reply_waits_for_supplier_again(): void
    {
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 1000000), $admin, scheme: DealScheme::Prime);
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage')->firstOrFail();

        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => '0385/046/07142/26', 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $send = function (string $at) use ($account, $thread) {
            $m = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'out', 'message_id' => uniqid().'@xcar.ru',
                'from_email' => 'deal@xcar.ru', 'subject' => 'Re: 0385/046/07142/26', 'date_at' => $at, 'sent_at' => $at]);
            MessageSent::dispatch($m);
        };
        $reply = fn (string $at) => AdvanceOnReply::mark($deal->fresh(), Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in',
            'from_email' => 'implementation@alfastrah.ru', 'subject' => 'RE: 0385/046/07142/26', 'date_at' => $at]));

        $send(now()->addMinutes(1)->toDateTimeString());
        $this->assertSame('sent', $position()->letterStatus());
        $reply(now()->addMinutes(2)->toDateTimeString());
        $this->assertSame('replied', $position()->letterStatus());
        $this->assertTrue($position()->needsUs());

        // Ответили «Да, актуально» — снова ждём поставщика, «Ваш ход» гаснет.
        $send(now()->addMinutes(3)->toDateTimeString());
        $this->assertSame('sent', $position()->letterStatus());
        $this->assertFalse($position()->needsUs());
        $this->assertFalse(Position::whereKey($position()->id)->needsUs()->exists());

        // Ответил снова — разбираем; «Ответ не нужен» — снова ждём.
        $reply(now()->addMinutes(4)->toDateTimeString());
        $this->assertSame('replied', $position()->letterStatus());
        $this->actingAs($admin)->post("http://crm.xcar.localhost/work/mail/{$thread->id}/answered")->assertRedirect();
        $this->assertSame('sent', $position()->letterStatus());

        // «Продал другому» из окна письма — сделка закрыта отказом поставщика.
        $this->actingAs($admin)->withHeaders(["Turbo-Frame" => "letters-frame"])->get("http://crm.xcar.localhost/work/mail/{$thread->id}/window")->assertOk()->assertSee('Продал другому');
        $this->actingAs($admin)->post("http://crm.xcar.localhost/work/deals/{$deal->id}/declined", ['note' => 'Поставщик продал другому: «Уже в работе.»', 'pickup' => 'drop'])->assertRedirect();
        $deal->refresh();
        $this->assertSame([DealState::Cancelled, 'supplier'], [$deal->state, $deal->close_reason]);
    }
}
