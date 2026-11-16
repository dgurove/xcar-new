<?php

namespace Tests\Feature;

use App\Mail\Composer;
use App\Mail\Message;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Mail\Twins;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ответ страховой — всем участникам переписки (16.11.2026, Альфа по Skoda Rapid 0770/046/01145/26: «Почему удаляете участников
 * переписки?» — ответы с парковки уходили только написавшему). И одно письмо в двух ветках — одним блоком «Ждут ответа».
 * Ошибка молчаливая и дорогая: страховая теряет людей из переписки и жалуется.
 */
class ParkReplyRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    private Vehicle $car;

    private function letter(Thread $t, string $at, array $cc = ['6691111@mail.ru', 'fartushniakd@alfastrah.ru'], string $text = 'Почему удаляете участников переписки?'): Message
    {
        $m = Message::create(['account_id' => $this->account, 'thread_id' => $t->id, 'direction' => 'in', 'intent' => 'question',
            'from_email' => 'tkachenkoev@alfastrah.ru', 'subject' => $t->subject, 'date_at' => $at, 'text_body' => $text]);
        $m->addresses()->create(['kind' => 'from', 'email' => 'tkachenkoev@alfastrah.ru', 'position' => 0]);
        $m->addresses()->create(['kind' => 'to', 'email' => 'storage@xcar.ru', 'position' => 1]);
        foreach ($cc as $i => $email) {
            $m->addresses()->create(['kind' => 'cc', 'email' => $email, 'position' => 2 + $i]);
        }

        return $m;
    }

    private function setUpCar(): void
    {
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $this->car = Vehicle::create(['vendor_id' => $vendor->id, 'state' => VehicleState::Stored]);
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'storage', 'title' => 'storage', 'email' => 'storage@xcar.ru', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
    }

    public function test_reply_keeps_everyone_in_copy(): void
    {
        $this->setUpCar();
        $thread = Thread::create(['account_id' => $this->account, 'subject' => '0770/046/01145/26 ч.1', 'vehicle_id' => $this->car->id]);
        $reply = app(Composer::class)->reply($this->letter($thread, '2026-11-10 15:58'));

        $this->assertSame('tkachenkoev@alfastrah.ru', $reply['to']);
        $cc = array_map('trim', explode(',', $reply['cc']));
        $this->assertEqualsCanonicalizing(['6691111@mail.ru', 'fartushniakd@alfastrah.ru'], $cc);
        $this->assertNotContains('storage@xcar.ru', $cc);
        // «Только ему» — по-прежнему можно.
        $this->assertSame('', app(Composer::class)->reply($this->letter($thread, '2026-11-10 16:00'), all: false)['cc']);
    }

    public function test_same_letter_in_two_threads_is_one_waiting_block(): void
    {
        $this->setUpCar();
        $one = Thread::create(['account_id' => $this->account, 'subject' => '0770/046/01145/26 ч.1', 'vehicle_id' => $this->car->id]);
        $three = Thread::create(['account_id' => $this->account, 'subject' => '0770/046/01145/26 ч.3', 'vehicle_id' => $this->car->id]);
        $a = $this->letter($one, '2026-11-16 16:10:17');
        $b = $this->letter($three, '2026-11-16 16:10:45');
        $other = $this->letter($three, '2026-11-16 17:00', [], 'Когда заберут?');
        app(Threads::class)->refresh($one);
        app(Threads::class)->refresh($three);

        $asks = Twins::collapse(collect([$a, $b]));
        $this->assertCount(1, $asks);
        $this->assertSame($b->id, $asks->first()->id);
        $this->assertSame(2, $asks->first()->twins);
        // Другие слова — отдельный блок.
        $this->assertCount(2, Twins::collapse(collect([$a, $other])));
    }
}
