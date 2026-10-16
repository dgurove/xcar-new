<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Mail\VendorPick;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Actions\BindSender;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Выбрать вендора» у письма (16.10.2026, владелец): письма с этого адреса — этого вендора, и уже пришедшие тоже. Ошибка
 * молчалива: шапка цепочки так и стоит без вендора, а следующее письмо снова не узнано.
 */
class BindSenderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function letter(string $subject, string $from = '4959678612@mail.ru'): Message
    {
        $this->admin ??= User::firstOrCreate(['phone' => '79006691111'], ['name' => 'Кузнецов Андрей', 'email' => '6691111@mail.ru', 'roles' => [Role::Admin], 'approved_at' => now()]);
        Cache::forget('mail:own-emails');
        Cache::forget('mail:own-mailboxes');
        $account = Account::firstOrCreate(['slug' => 'deal'], ['title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => $subject]);
        $message = Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In,
            'from_email' => '6691111@mail.ru', 'subject' => $subject, 'date_at' => now(),
            'text_body' => "-------- Пересылаемое сообщение --------\nОт: Набойченко Артём <{$from}>\n\nЖдем Ваше предложение по стоимости годных остатков.\nС Уважением,\nООО \"Страховая компания \"ТИТ\""]);
        app(ReadLetter::class)->apply($message);

        return $message->fresh();
    }

    public function test_picked_vendor_gets_thread_chain_and_next_letter(): void
    {
        Notification::fake();
        $first = $this->letter('Fwd: Годные остатки Tenet T4 Х871СВ763');
        $chain = app(ChainBuilder::class)->attach($first, quiet: true, files: false);
        $this->assertNotNull($chain);
        $tit = Vendor::create(['name' => 'ТИТ']);

        app(BindSender::class)($tit, '4959678612@mail.ru', Scope::Offers);

        $this->assertSame($tit->id, $first->thread->fresh()->vendor_id);
        $this->assertSame($tit->id, $chain->fresh()->vendor_id, 'шапка цепочки получает вендора');
        $this->assertSame($tit->id, $this->letter('Fwd: Годные остатки Haval F7 А123ВС77')->field('vendor_id'), 'следующее письмо с адреса узнано');
    }

    public function test_address_moves_from_other_vendor_and_free_domain_stays_exact(): void
    {
        $alfa = Vendor::create(['name' => 'Альфа', 'senders' => ['4959678612@mail.ru']]);
        $tit = Vendor::create(['name' => 'ТИТ']);
        $bind = app(BindSender::class);

        $bind($tit, '4959678612@mail.ru', Scope::Offers, domain: true);

        $this->assertSame('Альфа', $bind->movedFrom);
        $this->assertSame([], $alfa->fresh()->senders);
        $this->assertSame(['4959678612@mail.ru'], $tit->fresh()->senders, 'mail.ru — общий: только адрес, не домен');
        $this->assertNull(BindSender::ownDomain('a@mail.ru'));
        $this->assertSame('tit-ins.ru', BindSender::ownDomain('a@tit-ins.ru'));
    }

    public function test_new_vendor_from_letter_window(): void
    {
        Notification::fake();
        $message = $this->letter('Fwd: Годные остатки Tenet T4 Х871СВ763');
        $pick = VendorPick::of($this->admin, Scope::Offers, '/work/mail', $message->thread, [$message]);
        $this->assertSame('4959678612@mail.ru', $pick['sender']);
        $this->assertSame('ТИТ', $pick['name'], 'название из подписи письма');

        $this->actingAs($this->admin)->post('http://crm.xcar.localhost'.$pick['url'], ['mode' => 'new', 'name' => 'ТИТ', 'kind' => 'insurer', 'sender' => $pick['sender']])
            ->assertRedirect()->assertSessionHas('toast', 'Вендор «ТИТ» заведён');

        $tit = Vendor::named('ТИТ');
        $this->assertNotNull($tit);
        $this->assertSame($tit->id, $message->thread->fresh()->vendor_id);
    }
}
