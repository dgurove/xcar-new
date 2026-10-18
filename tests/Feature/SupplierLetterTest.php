<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\LetterPrompt;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\SendState;
use App\Mail\Template;
use App\Mail\Thread;
use App\Notifications\SupplierLetterNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Письмо поставщику после «Принять» (18.10.2026: Андрей принял 8 подтверждений Альфы, окно открылось у 4). Окно ждёт в
 * очереди человека на сервере: предзагрузка его не забирает, страница — забирает один раз. Доставка — в шаге сделки: не
 * ушло — `failed`, вернулся отказ сервера — `bounced`, ушло — `sent`; тому, кто писал, — уведомление.
 */
class SupplierLetterTest extends TestCase
{
    use RefreshDatabase;

    public function test_letter_window_survives_neighbours_and_delivery_shows_in_step(): void
    {
        Notification::fake();
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '8622/046/00615/26', 'floor_price' => 700000, 'asking_price' => 900000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 900000), $admin, 0, CommissionMode::Withheld);
        $position = $offer->fresh()->position(Track::Sale);
        $position->stage->update(['template_id' => Template::create(['name' => 'Уведомление', 'scope' => Scope::Offers, 'subject' => '{{ claim_ref }}', 'body' => 'Выражаем интерес'])->id, 'waits_for' => WaitsFor::Supplier]);
        $position->update(['waits_for' => WaitsFor::Us]);
        $this->assertSame('unsent', $position->fresh()->letterStatus());

        // Окно: предзагрузка и парковка письмо CRM не забирают, страница CRM забирает один раз.
        $this->actingAs($admin);
        LetterPrompt::push($admin, '/work/mail/new?offer='.$offer->number.'&template=1&then=close');
        $at = function (string $host, array $server = []) use ($admin) {
            $this->app->instance('request', $r = Request::create('http://'.$host.'/', 'GET', [], [], [], $server));
            $r->setUserResolver(fn () => $admin);
            $this->app->instance(Surface::class, Surface::fromHost($host));
        };
        $at(config('xcar.crm_host'), ['HTTP_SEC_PURPOSE' => 'prefetch']);
        $this->assertNull(LetterPrompt::take(), 'предзагрузка письмо не забирает');
        $at(config('xcar.park_host'));
        $this->assertNull(LetterPrompt::take(), 'парковка письмо CRM не забирает');
        $at(config('xcar.crm_host'));
        $this->assertStringContainsString('then=close', (string) LetterPrompt::take());
        $at(config('xcar.crm_host'));
        $this->assertNull(LetterPrompt::take(), 'второй раз окно не открывается');

        // Доставка: не ушло → failed и уведомление; ушло → sent; вернулся отказ → bounced.
        $account = Account::create(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => Scope::Offers, 'is_active' => true, 'imap_host' => 'imap.test', 'imap_username' => 'deal', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'deal', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => '8622/046/00615/26', 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $letter = Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::Out, 'message_id' => 'a@xcar.ru',
            'subject' => '8622/046/00615/26', 'to_preview' => 'implementation@alfastrah.ru', 'send_state' => SendState::Failed, 'send_error' => 'Connection timed out', 'created_by' => $admin->id, 'date_at' => now()]);
        MessageFailed::dispatch($letter);
        $this->assertSame('failed', $position->fresh()->letterStatus());
        Notification::assertSentTo($admin, SupplierLetterNotice::class);

        MessageFailed::dispatch($letter->fresh());
        Notification::assertSentToTimes($admin, SupplierLetterNotice::class, 1);
        $letter->update(['send_state' => SendState::Sent, 'send_error' => null, 'date_at' => now()]);
        MessageSent::dispatch($letter->fresh());
        $p = $position->fresh();
        $this->assertSame('sent', $p->letterStatus());
        $this->assertArrayNotHasKey('letter_failed', $p->payload);

        // Отказ — своей веткой, письмо узнаётся по Message-ID в теле; старый отказ (раньше письма) и история ящика не в счёт.
        $other = Thread::create(['account_id' => $account->id, 'subject' => 'Undelivered Mail', 'last_message_at' => now()]);
        $old = Message::create(['account_id' => $account->id, 'thread_id' => $other->id, 'direction' => Direction::In, 'message_id' => 'old@mail.ru',
            'subject' => 'Undelivered Mail Returned to Sender', 'intent' => 'bounce', 'date_at' => now()->subDays(2), 'text_body' => 'Message-ID: <a@xcar.ru>']);
        MessageParsed::dispatch($old);
        MessageParsed::dispatch($bounce = Message::create(['account_id' => $account->id, 'thread_id' => $other->id, 'direction' => Direction::In, 'message_id' => 'q@mail.ru',
            'subject' => 'Undelivered Mail Returned to Sender', 'intent' => 'bounce', 'date_at' => now()->addMinute(), 'text_body' => 'could not be delivered to implementation@alfastrah.ru Message-ID: <a@xcar.ru>']), true);
        $this->assertSame('sent', $position->fresh()->letterStatus(), 'старый отказ и история ящика письмо не красят');
        MessageParsed::dispatch($bounce);
        $this->assertSame('bounced', $position->fresh()->letterStatus());
    }
}
