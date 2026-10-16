<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Events\MessageParsed;
use App\Mail\Message;
use App\Mail\Thread;
use App\Telegram\Jobs\NotifyOwner;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Письмо Альфы на deal@ — админам в Telegram (17.10.2026): молча не дойти — пропущенный ответ по сделке. */
class VendorLetterTelegramTest extends TestCase
{
    use RefreshDatabase;

    private function letter(string $slug, ?int $reply = null): void
    {
        Cache::forget('mail:own-emails');
        $account = Account::create(['slug' => $slug, 'title' => $slug, 'email' => "{$slug}@xcar.test", 'scope' => 'offers', 'reply_account_id' => $reply,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => 'Убыток 0810/046/03364/25']);
        $message = Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In,
            'from_email' => 'ivanova@alfastrah.ru', 'from_name' => 'Иванова Ольга', 'subject' => 'Убыток 0810/046/03364/25', 'date_at' => now(),
            'text_body' => "Добрый день. Покупку согласовали, контакт владельца +7 900 111-22-33.\n\nС уважением"]);
        MessageParsed::dispatch($message);
    }

    public function test_alfa_letter_on_deal_goes_to_telegram(): void
    {
        Queue::fake();
        $this->assertTrue(Vendor::where('name', 'АльфаСтрахование')->value('letters_to_telegram'), 'миграция включила Альфе');

        $this->letter('deal');

        Queue::assertPushed(NotifyOwner::class, fn (NotifyOwner $job) => str_contains($job->message->text(), 'АльфаСтрахование')
            && str_contains($job->message->text(), 'Покупку согласовали'));
    }

    public function test_offer_box_and_vendor_without_flag_stay_quiet(): void
    {
        Queue::fake();
        Vendor::where('name', 'АльфаСтрахование')->update(['letters_to_telegram' => false]);
        $this->letter('deal');
        Vendor::query()->update(['letters_to_telegram' => true]);
        Vendor::forgetSenders();
        $deal = Account::where('slug', 'deal')->value('id');
        $this->letter('offer', $deal);

        Queue::assertNotPushed(NotifyOwner::class);
    }
}
