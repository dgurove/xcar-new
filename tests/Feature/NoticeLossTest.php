<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Jobs\SendMessage;
use App\Mail\Scope;
use App\Notifications\DealEventNotice;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Уведомления и сводки не теряются (26.11.2026, ошибки молчаливые): битый байт в тексте не роняет строку ленты (за неделю 30
 * `DealEventNotice` в `failed_jobs`, «Подписанный догово\xD1»); сводка, упавшая посреди, уходит следующим тиком, а не через слот.
 */
class NoticeLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_broken_utf8_still_reaches_feed(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'АльфаСтрахование'])->id, 'claim_ref' => '0399/046/02681/26']);

        // «…договор» в конце: `trim` с «—» в списке срезал байт 0x80 у «р».
        $admin->notifyNow(DealEventNotice::pickupLetter($offer, 'Подписанный договор', $admin), ['database']);
        // Битый байт в самом тексте (письмо, обрезка) — строка всё равно пишется.
        $admin->notifyNow(DealEventNotice::pickupLetter($offer, "Письмо \xD1 поставщику", $admin), ['database']);

        $rows = DB::table('notifications')->where('notifiable_id', $admin->id)->pluck('data')->map(fn ($d) => json_decode($d, true));
        $this->assertCount(2, $rows);
        $signed = $rows->first(fn ($r) => str_contains($r['title'], 'договор'));
        $this->assertSame('Андрей Кузнецов: подписанный договор', $signed['brief'] ?? null);
        $letter = $rows->first(fn ($r) => str_contains($r['title'], 'поставщику'));
        $this->assertTrue(mb_check_encoding($letter['title'], 'UTF-8'));
    }

    public function test_digest_killed_midway_goes_next_tick(): void
    {
        Queue::fake();
        $vendor = Vendor::firstOrCreate(['name' => 'Т-Страхование']);
        $andrey = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000004', 'email' => 'andrey@example.com', 'roles' => [Role::Admin], 'approved_at' => now()]);
        config(['xcar.claim_digest' => ['vendors' => [$vendor->id], 'user' => $andrey->id, 'at' => ['09:00', '18:00'], 'account' => 'deal@xcar.test',
            'to' => ['yu.sedova@ins.test'], 'bcc' => [], 'subject' => 'Согласование реализации']]);
        Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => Scope::Offers, 'imap_host' => 'imap.test',
            'imap_username' => 'deal@xcar.test', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'deal@xcar.test', 'smtp_password' => 'x']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-555555']);
        $buyer = User::create(['name' => 'Менеджер', 'phone' => '79000000005', 'roles' => [Role::Manager], 'approved_at' => now()]);
        Interest::create(['offer_id' => $offer->id, 'user_id' => $buyer->id, 'state' => InterestState::New]);
        $tick = fn (string $t) => app(MailClaimDigest::class)->tick(CarbonImmutable::parse('2026-11-06 '.$t, 'Europe/Moscow'));

        // Задачу убили посреди отправки.
        $boom = true;
        ClaimRequest::creating(function () use (&$boom) {
            if ($boom) {
                $boom = false;
                throw new \RuntimeException('убили');
            }
        });
        try {
            $tick('09:05');
            $this->fail('отправка должна была упасть');
        } catch (\RuntimeException $e) {
            $this->assertSame('убили', $e->getMessage());
        }
        Queue::assertNotPushed(SendMessage::class);

        $this->assertSame(1, $tick('09:10'), 'следующий тик того же слота отправляет');
        $this->assertSame(0, $tick('09:15'), 'и только раз');
        Queue::assertPushed(SendMessage::class, 1);
    }
}
