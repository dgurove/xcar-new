<?php

namespace Tests\Feature;

use App\Mail\Console\SyncMail;
use App\Mail\Events\MessageParsed;
use App\Mail\Ingest;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Message;
use App\Mail\ParseState;
use App\Mail\Thread;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Telegram\Jobs\NotifyOwner;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Письмо, разбор которого оборвался посреди, дообрабатывается само (26.11.2026, владелец: «после выкладки сразу же продолжать»):
 * раньше ветка без файлов, уведомления и строка истории терялись навсегда. Ошибка молчаливая — письмо в базе «разобрано».
 */
class MessageResumeTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::forget('mail:resume:since');
    }

    public function test_cut_off_letter_is_finished_by_sync_and_its_steps_are_not_repeated(): void
    {
        $message = $this->letter();
        $fail = true;
        Event::listen(MessageParsed::class, function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('убили посреди');
            }
        });

        try {
            app(Ingest::class)->handle($message->fresh(['account', 'thread', 'attachments']));
        } catch (RuntimeException) {
        }
        $this->assertNull($message->fresh()->handled_at, 'оборвалось — не дообработано');
        $this->assertSame(1, $this->vehicle->events()->where('type', EventType::Letter)->count(), 'строка истории успела встать');

        $fail = false;
        $this->travel(5)->minutes();
        app(SyncMail::class)->resume(app(Ingest::class));

        $this->assertNotNull($message->fresh()->handled_at, 'дочитка довела до конца');
        $this->assertSame(1, $this->vehicle->events()->where('type', EventType::Letter)->count(), 'строка истории — одна');
        Queue::assertPushed(ImportThreadFiles::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_poison_letter_is_marked_after_three_tries_and_owner_is_told(): void
    {
        $message = $this->letter();
        Event::listen(MessageParsed::class, fn () => throw new RuntimeException('всегда падает'));
        $this->travel(5)->minutes();

        foreach ([1, 2, 3] as $i) {
            app(SyncMail::class)->resume(app(Ingest::class));
        }

        $this->assertNotNull($message->fresh()->handled_at, 'ядовитое не крутится вечно');
        Queue::assertPushed(NotifyOwner::class);
    }

    private function letter(): Message
    {
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $this->vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'state' => VehicleState::Stored]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => 'Z691/046/00001/26', 'vehicle_id' => $this->vehicle->id]);

        return Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'a@alfastrah.ru',
            'subject' => 'Документы по ТС', 'text_body' => 'Направляем документы', 'date_at' => now(), 'parse_state' => ParseState::Parsed]);
    }
}
