<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Actions\AcceptByLetters;
use App\Park\EventType;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ждущая ТС встаёт на хранение по нашему письму «фотоотчёт … по принятому ТС» (`AcceptByLetters`, 02.11.2026). Тихая
 * ошибка: не принимает — «Заявки» копятся, а счёт за хранение не идёт; принимает лишнее — сутки хранения не те.
 */
class AcceptByLettersTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    private Thread $thread;

    /** Ждущая ТС с заявкой на приём от 10.10 и веткой ящика парковки. */
    private function vehicle(): Vehicle
    {
        $vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'state' => VehicleState::Expected]);
        $request = Request::create(['vehicle_id' => $vehicle->id, 'type' => 'intake', 'state' => RequestState::New]);
        $request->forceFill(['created_at' => Carbon::parse('2026-10-10 12:00')])->save();
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $this->thread = Thread::create(['account_id' => $this->account, 'subject' => '0760/046/15089/26', 'vehicle_id' => $vehicle->id]);

        return $vehicle;
    }

    private function ours(string $at): Message
    {
        return Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'out', 'from_email' => 'storage@xcar.ru',
            'subject' => 'Re: 0760/046/15089/26', 'text_body' => 'Добрый день. Направляю Вам фотоотчет и скан-копии документов по принятому ТС.',
            'intent' => 'accepted', 'date_at' => Carbon::parse($at)]);
    }

    public function test_accepts_with_the_letter_date(): void
    {
        $vehicle = $this->vehicle();
        $this->ours('2026-10-16 13:50');

        $this->assertNotNull(app(AcceptByLetters::class)($vehicle));

        $vehicle->refresh();
        $this->assertSame(VehicleState::Stored, $vehicle->state);
        $this->assertSame('2026-10-16 13:50', $vehicle->accepted_at->format('Y-m-d H:i'));
        $this->assertSame(RequestState::Done, Request::where('vehicle_id', $vehicle->id)->first()->state);
    }

    public function test_skips_without_our_letter(): void
    {
        $vehicle = $this->vehicle();
        Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru',
            'subject' => 'Заявка на приём', 'intent' => 'intake', 'date_at' => Carbon::parse('2026-10-11 10:00')]);

        $this->assertNull(app(AcceptByLetters::class)($vehicle));
        $this->assertSame(VehicleState::Expected, $vehicle->fresh()->state);
    }

    public function test_skips_a_letter_before_the_request(): void
    {
        $vehicle = $this->vehicle();
        $this->ours('2026-09-01 10:00');

        $this->assertNull(app(AcceptByLetters::class)($vehicle));
        $this->assertSame(VehicleState::Expected, $vehicle->fresh()->state);
    }

    public function test_skips_after_manual_undo(): void
    {
        $vehicle = $this->vehicle();
        $this->ours('2026-10-16 13:50');
        $this->assertNotNull(app(AcceptByLetters::class)($vehicle));
        // «Отменить приём»: ТС снова ждём, письмо её больше не принимает — ни сразу, ни часами `park:tick`.
        $vehicle->refresh()->update(['state' => VehicleState::Expected, 'accepted_at' => null]);
        $vehicle->log(EventType::IntakeUndone, null, []);

        $this->assertNull(app(AcceptByLetters::class)($vehicle->refresh()));
        $this->assertSame(0, app(AcceptByLetters::class)->all());
        $this->assertSame(VehicleState::Expected, $vehicle->fresh()->state);
    }

    public function test_suspects_skip_letters_before_the_open_request(): void
    {
        $vehicle = $this->vehicle();
        $this->ours('2026-09-01 10:00');

        $this->assertCount(0, app(AcceptByLetters::class)->suspects());
    }

    public function test_skips_when_the_vendor_cancelled_after(): void
    {
        $vehicle = $this->vehicle();
        $this->ours('2026-10-16 13:50');
        Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru',
            'subject' => 'Re: 0760/046/15089/26', 'intent' => 'hold', 'date_at' => Carbon::parse('2026-10-17 10:00')]);

        $this->assertNull(app(AcceptByLetters::class)($vehicle));
        $this->assertSame(VehicleState::Expected, $vehicle->fresh()->state);
    }

    public function test_signed_release_act_accepts_on_vendor_request_and_releases(): void
    {
        $vehicle = $this->vehicle();
        Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru',
            'subject' => 'Просьба организовать прием ТС', 'intent' => 'intake', 'date_at' => Carbon::parse('2026-09-02 12:58')]);
        Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'out', 'from_email' => 'storage@xcar.ru',
            'subject' => 'Re: 0760/046/15089/26', 'text_body' => 'Добрый день. Направляю Вам подписанный АПП.',
            'intent' => 'released', 'date_at' => Carbon::parse('2026-10-26 17:44')]);

        $this->assertCount(1, app(AcceptByLetters::class)->suspects());
        $this->assertNotNull(app(AcceptByLetters::class)($vehicle));

        $vehicle->refresh();
        $this->assertSame(VehicleState::Released, $vehicle->state);
        $this->assertSame('2026-09-02', $vehicle->accepted_at->toDateString());
        $this->assertSame('2026-10-26', $vehicle->released_at->toDateString());
    }
}
