<?php

namespace Tests\Feature;

use App\Mail\Extraction\Extractor;
use App\Mail\Message;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Offers\Actions\CreateOffer;
use App\Offers\Actions\UpdateOffer;
use App\Mail\Actions\LinkThread;
use App\Mail\Attachment;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Письма ящика предложений, которые ни к чему не легли (04.11.2026, «почему так много неразобранных»): «RE: 631351-25»
 * ложится к 631351/2026; номер без года берёт год из имени договора. С 17.11.2026 в CRM «Ждёт ответа» нет вовсе (владелец:
 * «от „Требуют внимания“ избавиться, работать с прочитанными и непрочитанными»; правило «письмо вендора без машины ждёт
 * ответа» ловило автоответы «я в отпуске») — такое письмо видно непрочитанным во «Входящих».
 */
class StrayVendorLettersTest extends TestCase
{
    use RefreshDatabase;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        Vendor::create(['name' => 'Совком тест', 'senders' => ['sovcomins.ru']]);
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
    }

    private function letter(string $subject, string $body, string $from = 'a.lapshin@sovcomins.ru'): Thread
    {
        $thread = Thread::create(['account_id' => $this->account, 'subject' => $subject]);
        Message::create(['account_id' => $this->account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => $from,
            'subject' => $subject, 'text_body' => $body, 'date_at' => now(), 'parse_state' => 'parsed']);
        app(Threads::class)->refresh($thread);

        return $thread->refresh();
    }

    public function test_crm_letter_waits_for_nothing_and_lands_in_inbox(): void
    {
        $t = $this->letter('495181-2026', 'Добрый день коллеги просьба прислать документы, договор + п/передачи тс');
        $this->assertNull($t->needs_reply_at, 'в CRM «ждёт ответа» нет');
        $letter = Message::where('thread_id', $t->id)->first();
        $this->assertTrue($letter->outside, 'письмо извне — во «Входящих»');
        $this->assertFalse($letter->is_seen);
        $this->assertSame(1, \App\Mail\Boxes::unreadCount(\App\Mail\Scope::Offers));
    }

    public function test_reply_with_other_year_links_to_offer(): void
    {
        $thread = $this->letter('RE: 631351-25', 'Всем привет! Новая стоимость – 98 095 руб.');
        $offer = app(CreateOffer::class)(null);
        app(UpdateOffer::class)($offer, ['claim_ref' => '631351/2026', 'vendor_id' => Vendor::first()->id], null);
        $this->assertSame($offer->id, app(LinkThread::class)->auto(Message::where('thread_id', $thread->id)->first()->load('account', 'thread'))?->id, 'тот же номер с другим годом');
    }

    public function test_bare_number_takes_year_from_contract_name(): void
    {
        $files = [new Attachment(['filename' => 'Договор комиссии 837110-26.docx', 'is_inline' => true]), new Attachment(['filename' => 'оценка ГОТС.pdf', 'is_inline' => true])];
        $fields = app(Extractor::class)->extract('Вывоз тс убыток 837110  Саров', 'МАРКА МОДЕЛЬ KAIYI X7', 'a.lapshin@sovcomins.ru', now(), $files);
        $this->assertSame('837110/2026', $fields['code']['value'] ?? null);
    }
}
