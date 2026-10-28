<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Template;
use App\Mail\Thread;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Письмо поставщику шага — ответом в его ветку (28.10.2026, владелец: «это не ответ (RE: тема), а отдельное письмо —
 * Альфа его потеряет»): тема «Re: …» ветки, а не шаблона; пока поставщик не писал — ответ на наше последнее письмо.
 * Прямой адрес редактора открывает окно на той же странице, а не уводит в «Почту».
 */
class VendorLetterReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_letter_is_reply_with_thread_subject(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '0760/046/14575/26', 'floor_price' => 900000]);
        $offer->forceFill(['number' => 2610289001])->save();
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $template = Template::create(['scope' => 'offers', 'name' => 'Поставщику: подтверждение покупки', 'subject' => '{{ claim_ref }}', 'body' => 'Подтверждаем актуальность']);
        $thread = Thread::create(['account_id' => $account, 'subject' => '0760/046/14575/26 Hongqi H5', 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $ours = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'out', 'message_id' => 'ours@xcar.ru',
            'from_email' => 'deal@xcar.ru', 'subject' => '0760/046/14575/26 Hongqi H5', 'date_at' => now()->subDay()]);
        $open = fn () => $this->actingAs($admin)->withHeaders(['Turbo-Frame' => 'letters-frame'])
            ->get("http://crm.xcar.localhost/work/mail/new?offer={$offer->number}&template={$template->id}")->assertOk()->getContent();

        // Поставщик ещё не писал — ответ на наше письмо: «Re:» и родитель.
        $page = $open();
        $this->assertStringContainsString('value="Re: 0760/046/14575/26 Hongqi H5"', $page);
        $this->assertStringContainsString('name="parent" value="'.$ours->id.'"', $page);

        // Поставщик ответил — ответ на его письмо.
        $theirs = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'message_id' => 'alfa@alfastrah.ru',
            'from_email' => 'expert@alfastrah.ru', 'subject' => 'RE: 0760/046/14575/26 Hongqi H5', 'date_at' => now()]);
        $page = $open();
        $this->assertStringContainsString('value="RE: 0760/046/14575/26 Hongqi H5"', $page);
        $this->assertStringContainsString('name="parent" value="'.$theirs->id.'"', $page);

        // Старая ссылка без окна — назад на ту же страницу с окном, а не в «Почту».
        $this->flushHeaders();
        $this->actingAs($admin)->withHeaders(['Referer' => 'http://crm.xcar.localhost/work/deals?peek=1'])
            ->get("http://crm.xcar.localhost/work/mail/new?offer={$offer->number}")
            ->assertRedirect('http://crm.xcar.localhost/work/deals?peek=1')->assertSessionHas('letters');
    }
}
