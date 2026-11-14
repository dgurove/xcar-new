<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Events\MessageSent;
use App\Mail\Jobs\SendMessage;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\SendState;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Actions\MailClaimDigest;
use App\Vendors\ClaimRequest;
use App\Vendors\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Номера «К запросу» письмом Андрею в 9:00 и 18:00 (06.11.2026): один раз на слот, пропущенный слот уходит позже, пусто —
 * ничего, номера помечены как кнопкой; не дошло письмом — в Telegram. Ошибка молчалива: номер не запросят у страховой.
 */
class ClaimDigestTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $andrey;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->vendor = Vendor::firstOrCreate(['name' => 'Т-Страхование']);
        $this->andrey = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000004', 'email' => 'andrey@example.com', 'roles' => [Role::Admin], 'approved_at' => now()]);
        config(['xcar.claim_digest' => ['vendors' => [$this->vendor->id], 'user' => $this->andrey->id, 'at' => ['09:00', '18:00'], 'account' => 'deal@xcar.test',
            'to' => ['yu.sedova@ins.test', 'e.martynov@ins.test'], 'bcc' => ['andrey@example.com'], 'subject' => 'Согласование реализации']]);
        Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => Scope::Offers, 'imap_host' => 'imap.test',
            'imap_username' => 'deal@xcar.test', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'deal@xcar.test', 'smtp_password' => 'x']);
    }

    private function pending(string $ref): Offer
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => $ref]);
        $buyer = User::create(['name' => 'Менеджер '.$ref, 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [Role::Manager], 'approved_at' => now()]);
        Interest::create(['offer_id' => $offer->id, 'user_id' => $buyer->id, 'state' => InterestState::New]);

        return $offer;
    }

    private function at(string $time): int
    {
        return app(MailClaimDigest::class)->tick(CarbonImmutable::parse('2026-11-06 '.$time, 'Europe/Moscow'));
    }

    public function test_once_per_slot_marks_numbers_and_skips_empty(): void
    {
        $this->pending('AUT-26-111111');
        $this->pending('AUT-26-222222');

        $this->assertSame(0, $this->at('08:55'));
        $this->assertSame(1, $this->at('09:05'));
        $this->assertSame(0, $this->at('09:10'), 'второй проход того же слота');
        Queue::assertPushed(SendMessage::class, 1);
        $this->assertSame(0, ClaimRequest::pendingFor($this->vendor)->count(), 'номера помечены отправленными');
        $request = ClaimRequest::sole();
        $this->assertSame(['AUT-26-111111', 'AUT-26-222222'], $request->refs);
        $this->assertSame($this->andrey->id, $request->user_id);

        // Пусто к 18:00 — письма нет.
        $this->assertSame(0, $this->at('18:05'));
        Queue::assertPushed(SendMessage::class, 1);
    }

    public function test_missed_slot_goes_later_but_not_too_late(): void
    {
        $this->pending('AUT-26-333333');
        $this->assertSame(1, $this->at('11:50'), 'выкладка в 9:00 — письмо уходит следующим проходом');
        $this->pending('AUT-26-444444');
        $this->assertSame(0, $this->at('12:30'), 'слот 9:00 уже отправлен');
        $this->assertNull(MailClaimDigest::dueSlot(CarbonImmutable::parse('2026-11-06 12:01', 'Europe/Moscow')), 'позже трёх часов — ждём 18:00');
    }

    /**
     * Письмо — самой страховой из почты CRM (14.11.2026): тема, кому, скрытая копия Андрею, текст как писал владелец, подпись —
     * Андрей; не ушло — повтор до трёх раз, потом номера в Telegram; ушло — владельцу «ушло».
     */
    public function test_letter_goes_to_vendor_from_crm_mailbox_and_retries(): void
    {
        config(['xcar.telegram.token' => 'test-token']);
        $said = [];
        \Illuminate\Support\Facades\Http::fake(function ($r) use (&$said) {
            $said[] = $r['text'] ?? '';

            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
        $owner = User::create(['name' => 'Дмитрий Гуров', 'phone' => '79000000003', 'roles' => [Role::Admin, Role::Owner], 'approved_at' => now()]);
        $owner->forceFill(['telegram_chat_id' => 555])->save();
        $this->pending('AUT-26-555555');
        $this->pending('AUT-26-555556');
        $this->at('09:01');

        $request = ClaimRequest::sole();
        $message = Message::with('addresses')->findOrFail($request->message_id);
        $this->assertSame('Согласование реализации', $message->subject);
        $this->assertSame('deal@xcar.test', $message->from_email);
        $this->assertEqualsCanonicalizing(['yu.sedova@ins.test', 'e.martynov@ins.test'], $message->addressesOfKind(\App\Mail\AddressKind::To)->pluck('email')->all());
        $this->assertSame(['andrey@example.com'], $message->addressesOfKind(\App\Mail\AddressKind::Bcc)->pluck('email')->all());
        $this->assertStringContainsString("Просим согласовать возможность реализации по убыткам:", $message->text_body);
        $this->assertStringContainsString("AUT-26-555555\nAUT-26-555556", $message->text_body);
        $this->assertStringContainsString('Оперативную оплату и вывоз гарантируем после проверки VIN.', $message->text_body);
        $this->assertStringEndsWith("С уважением,\nАндрей Кузнецов", trim($message->text_body));
        $this->assertSame($this->vendor->id, $message->thread->vendor_id, 'ответ страховой — в ветке вендора');

        // Не ушло — снова в очередь, третья неудача — номера в Telegram и владельцу «не ушло».
        $digest = app(MailClaimDigest::class);
        $message->forceFill(['send_state' => SendState::Failed, 'send_attempts' => 1])->save();
        $this->assertSame(1, $digest->retry());
        $this->assertSame(SendState::Queued, $message->fresh()->send_state);
        $message->refresh()->forceFill(["send_state" => SendState::Failed, "send_attempts" => 3])->save();
        $this->assertSame(0, $digest->retry());
        $this->assertTrue(collect($said)->contains(fn ($t) => str_contains($t, "письмо с номерами не ушло")), json_encode($said, JSON_UNESCAPED_UNICODE));
        $digest->retry();
        $this->assertSame(1, collect($said)->filter(fn ($t) => str_contains($t, 'не ушло'))->count(), 'сказали один раз');

        // Ушло — владельцу с номерами.
        event(new MessageSent($message->fresh()));
        $this->assertTrue(collect($said)->contains(fn ($t) => str_contains($t, 'ушло</b>') && str_contains($t, '<code>AUT-26-555555</code>')));
    }

    /** Кнопка «Отправить запрос» в 12:00 — письмо страховой сразу; в 18:00 — только новые. */
    public function test_manual_send_mails_now(): void
    {
        $owner = User::create(['name' => 'Дмитрий Гуров', 'phone' => '79000000003', 'roles' => [Role::Admin, Role::Owner], 'approved_at' => now()]);
        $a = $this->pending('AUT-26-666666');
        $this->actingAs($owner)->post(\App\Support\Surface::Crm->url('/settings/vendors/'.$this->vendor->id.'/requests'), ['offers' => [$a->id]])
            ->assertSessionHas('toast', fn ($t) => str_contains($t, 'письмом Т-Страхование'));
        Queue::assertPushed(SendMessage::class, 1);

        $this->pending('AUT-26-777777');
        $this->assertSame(1, $this->at('18:01'));
        $this->assertSame(['AUT-26-777777'], ClaimRequest::latest('id')->first()->refs, 'в 18:00 — только новые');
    }
}
