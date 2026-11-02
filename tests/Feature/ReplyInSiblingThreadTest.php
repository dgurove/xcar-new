<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Вендор спросил в одной ветке ТС, потом написал о той же ТС новой («RE: … СРОЧНО!»), мы ответили там — дело больше не
 * «Ждёт ответа» (02.11.2026: висело с ответом прямо под вопросом). Ответ в ветке другой ТС вопрос не снимает.
 */
class ReplyInSiblingThreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_our_letter_in_sibling_thread_answers_the_question(): void
    {
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $car = Vehicle::create(['vendor_id' => $vendor->id, 'state' => VehicleState::Stored]);
        $other = Vehicle::create(['vendor_id' => $vendor->id, 'state' => VehicleState::Stored]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $letter = fn (Thread $t, string $dir, string $at) => Message::create(['account_id' => $account, 'thread_id' => $t->id, 'direction' => $dir,
            'intent' => $dir === 'in' ? 'question' : 'other', 'from_email' => $dir === 'in' ? 'a@alfastrah.ru' : 'storage@xcar.ru', 'subject' => $t->subject, 'date_at' => $at]);
        $threads = app(Threads::class);

        $asked = Thread::create(['account_id' => $account, 'subject' => '0323 ч.1', 'vehicle_id' => $car->id]);
        $letter($asked, 'in', '2026-10-29 11:36');
        $threads->refresh($asked);
        $this->assertNotNull($asked->fresh()->needs_reply_at);

        // Ответ о другой ТС — вопрос висит.
        $foreign = Thread::create(['account_id' => $account, 'subject' => 'другая', 'vehicle_id' => $other->id]);
        $letter($foreign, 'out', '2026-11-01 10:00');
        $threads->refresh($foreign);
        $this->assertNotNull($asked->fresh()->needs_reply_at);

        // Новая ветка той же ТС, наш ответ в ней — вопрос снят и сразу, и при пересчёте старой ветки.
        $urgent = Thread::create(['account_id' => $account, 'subject' => 'RE: 0323 ч.1 СРОЧНО!', 'vehicle_id' => $car->id]);
        $letter($urgent, 'in', '2026-11-02 13:55');
        $letter($urgent, 'out', '2026-11-02 15:09');
        $threads->refresh($urgent);
        $this->assertNull($asked->fresh()->needs_reply_at);
        $threads->refresh($asked->fresh());
        $this->assertNull($asked->fresh()->needs_reply_at);

        // Вендор снова спросил в старой ветке — снова ждёт.
        $letter($asked, 'in', '2026-11-02 16:00');
        $threads->refresh($asked->fresh());
        $this->assertNotNull($asked->fresh()->needs_reply_at);
    }
}
