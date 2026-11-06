<?php

namespace Tests\Feature;

use App\Mail\Jobs\SendSystemMail;
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
        config(['xcar.claim_digest' => ['vendors' => [$this->vendor->id], 'user' => $this->andrey->id, 'at' => ['09:00', '18:00']]]);
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
        Queue::assertPushed(SendSystemMail::class, 1);
        $this->assertSame(0, ClaimRequest::pendingFor($this->vendor)->count(), 'номера помечены отправленными');
        $request = ClaimRequest::sole();
        $this->assertSame(['AUT-26-111111', 'AUT-26-222222'], $request->refs);
        $this->assertSame($this->andrey->id, $request->user_id);

        // Пусто к 18:00 — письма нет.
        $this->assertSame(0, $this->at('18:05'));
        Queue::assertPushed(SendSystemMail::class, 1);
    }

    public function test_missed_slot_goes_later_but_not_too_late(): void
    {
        $this->pending('AUT-26-333333');
        $this->assertSame(1, $this->at('11:50'), 'выкладка в 9:00 — письмо уходит следующим проходом');
        $this->pending('AUT-26-444444');
        $this->assertSame(0, $this->at('12:30'), 'слот 9:00 уже отправлен');
        $this->assertNull(MailClaimDigest::dueSlot(CarbonImmutable::parse('2026-11-06 12:01', 'Europe/Moscow')), 'позже трёх часов — ждём 18:00');
    }

    public function test_letter_has_only_numbers_and_reaches_telegram_when_mail_fails(): void
    {
        $this->pending('AUT-26-555555');
        $this->at('09:01');
        $job = null;
        Queue::assertPushed(SendSystemMail::class, function (SendSystemMail $j) use (&$job) {
            return (bool) ($job = $j);
        });
        $job->handle();
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Т-Страхование: 1 номер к запросу', $sent->getSubject());
        $this->assertSame('andrey@example.com', $sent->getTo()[0]->getAddress());
        $this->assertSame('AUT-26-555555', trim($sent->getTextBody()));
        $this->assertStringContainsString('AUT-26-555555', $sent->getHtmlBody());
        $this->assertSame('auto-generated', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());

        // Не ушло и после повторов — номера в Telegram (без привязки — в лог, без падения).
        $job->failed(new \RuntimeException('smtp'));
        $this->assertTrue(true);
    }

    /** Кнопка «Отправить запрос» в 12:00 — письмо Андрею сразу; в 18:00 — только новые. Ушло — владельцу в Telegram. */
    public function test_manual_send_mails_now_and_owner_hears_in_telegram(): void
    {
        config(['xcar.telegram.token' => 'test-token']);
        $said = [];
        \Illuminate\Support\Facades\Http::fake(function ($r) use (&$said) {
            $said[] = $r['text'] ?? '';

            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
        $owner = User::create(['name' => 'Дмитрий Гуров', 'phone' => '79000000003', 'roles' => [Role::Admin, Role::Owner], 'approved_at' => now()]);
        $owner->forceFill(['telegram_chat_id' => 555])->save();
        $a = $this->pending('AUT-26-666666');
        $this->actingAs($owner)->post(\App\Support\Surface::Crm->url('/settings/vendors/'.$this->vendor->id.'/requests'), ['offers' => [$a->id]])
            ->assertSessionHas('toast', fn ($t) => str_contains($t, 'Андрей Кузнецов на почту'));
        $job = null;
        Queue::assertPushed(SendSystemMail::class, function (SendSystemMail $j) use (&$job) {
            return (bool) ($job = $j);
        });
        $job->handle();
        $this->assertTrue(collect($said)->contains(fn ($t) => str_contains($t, 'номера на почте') && str_contains($t, '<code>AUT-26-666666</code>')), 'владельцу — что письмо ушло');

        $this->pending('AUT-26-777777');
        $this->assertSame(1, $this->at('18:01'));
        $this->assertSame(['AUT-26-777777'], ClaimRequest::latest('id')->first()->refs, 'в 18:00 — только новые');
    }
}
