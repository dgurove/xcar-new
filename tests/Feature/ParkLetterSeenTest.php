<?php

namespace Tests\Feature;

use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Jobs\PushFlag;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Actions\RegisterCandidate;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Прочитано» — у человека (30.10.2026, управляющий парковкой: письма, которые он не открывал, в ящике storage@ уже
 * прочитаны). Заявка и ТС, заведённые по письмам (сами — `AutoRequest`, `StoreByLetters` — или «Завести»), флаг письма не
 * трогают ни у нас, ни в ящике: ошибка молчит, а заявку не видят.
 */
class ParkLetterSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_from_letters_keeps_them_unread(): void
    {
        Queue::fake();
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => 'Приём 0790/046/12295/26']);
        $letter = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'is_seen' => false,
            'from_email' => 'tkachenkoev@alfastrah.ru', 'subject' => 'Приём 0790/046/12295/26']);
        $candidate = Candidate::create(['scope' => 'park', 'code' => '0790/046/12295/26', 'key' => '0790046122952', 'state' => CandidateState::New,
            'message_id' => $letter->id, 'thread_id' => $thread->id, 'subject' => $letter->subject]);
        $candidate->messages()->attach($letter->id, ['created_at' => now()]);
        $vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'state' => VehicleState::Expected]);

        app(RegisterCandidate::class)->attach($candidate->fresh(), $vehicle);

        $this->assertFalse($letter->fresh()->is_seen);
        $this->assertSame($vehicle->id, $thread->fresh()->vehicle_id);
        Queue::assertNotPushed(PushFlag::class);
    }
}
