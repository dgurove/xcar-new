<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Mail\Account;
use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\LinkThread;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Chains\NodeTitle;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Reading\ReadLetter;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Почта CRM, 16.10.2026: письмо новой страховой «ТИТ» (её нет в справочнике) пересылкой Андрея лежало в «Прочем» —
 * без вендора, без цепочки, «нашим» письмом. Ошибка молчалива: письмо есть, а дела нет и его никто не видит.
 */
class MailUnknownSenderTest extends TestCase
{
    use RefreshDatabase;

    private function letter(string $subject, string $body, string $from = '6691111@mail.ru'): Message
    {
        $andrey = User::firstOrCreate(['phone' => '79006691111'], ['name' => 'Кузнецов Андрей', 'email' => '6691111@mail.ru', 'roles' => [Role::Admin], 'approved_at' => now()]);
        Cache::forget('mail:own-emails');
        Cache::forget('mail:own-mailboxes');
        $account = Account::firstOrCreate(['slug' => 'deal'], ['title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => $subject]);
        $message = Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In,
            'from_email' => $from, 'subject' => $subject, 'text_body' => $body, 'date_at' => now()]);
        app(ReadLetter::class)->apply($message);

        return $message->fresh();
    }

    private function tit(string $subject = 'Fwd: Годные остатки Tenet T4 Х871СВ763'): Message
    {
        return $this->letter($subject, "-------- Пересылаемое сообщение --------\nОт: Набойченко Артём <4959678612@mail.ru>\nКому: Андрей Кузнецов <6691111@mail.ru>\n"
            ."Тема: Годные остатки Tenet T4 Х871СВ763\n\nТС находится в г. Самара, принадлежит физ. лицу.\nЖдем Ваше предложение по стоимости годных остатков.\n"
            ."С Уважением,\nНабойченко Артём Владимирович\nООО \"Страховая компания \"ТИТ\"");
    }

    public function test_forward_from_unknown_insurer_starts_chain(): void
    {
        Event::fake();
        $message = $this->tit();

        $this->assertTrue($message->isForwardedByStaff(), 'пересылка от чужого адреса — письмо извне, хоть вендор и не узнан');
        $this->assertTrue($message->isFromVendor());
        $this->assertNotNull(app(ChainBuilder::class)->attach($message, files: false), 'письмо с машиной от незнакомой страховой — дело в «Из писем»');
    }

    public function test_brand_only_letter_starts_chain(): void
    {
        // 19.10.2026: «Годные Мазда 3» — ни номера, ни VIN, ни госномера, только марка; дела не было вовсе.
        Event::fake();
        Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda']);
        $message = $this->letter('Fwd: Годные Мазда 3', "-------- Пересылаемое сообщение --------\nОт: Набойченко Артём <4959678612@mail.ru>\nКому: Андрей Кузнецов <6691111@mail.ru>\n"
            ."Тема: Годные Мазда 3\n\nТС в Москве, пострадало от удара БПЛА. Год выпуска 2018. Ждем Ваше предложение.\n");

        $this->assertSame([], $message->keys());
        $this->assertNotNull(app(ChainBuilder::class)->attach($message, files: false), 'письмо извне с одной маркой — дело в «Из писем»');
    }

    public function test_staff_forward_revives_archived_chain(): void
    {
        // 20.10.2026: 677209/2026 Андрей убрал в архив, Катя переслала то же письмо снова — цепочка осталась в архиве, никто
        // её не видел. Новое письмо извне (и пересылка сотрудника) возвращает цепочку в «Из писем».
        Event::fake();
        $chain = app(ChainBuilder::class)->attach($this->tit(), files: false);
        $chain->update(['state' => CandidateState::Rejected]);
        app(ArchiveThread::class)->candidate($chain, true);

        $again = app(ChainBuilder::class)->attach($this->tit(), files: false);
        $this->assertTrue($again->is($chain));
        $this->assertSame(CandidateState::New, $chain->fresh()->state);
        $this->assertNull($again->threads()->first()?->fresh()->archived_at);
    }

    public function test_reply_in_correspondence_does_not_start_chain(): void
    {
        Event::fake();
        $message = $this->tit('Fwd: RE: Годные остатки Tenet T4 Х871СВ763');

        $this->assertNull(app(ChainBuilder::class)->attach($message, files: false));
    }

    public function test_vendor_set_by_hand_learns_sender(): void
    {
        // Без Event::fake: он глушит и события моделей, а `Vendor::saved` сбрасывает память `forSender`.
        $message = $this->tit();
        $offer = Offer::create(['brand_id' => null]);
        app(LinkThread::class)($message->thread, $offer, files: false);
        $tit = Vendor::create(['name' => 'ТИТ']);

        app(UpdateOffer::class)($offer->fresh(), ['vendor_id' => $tit->id], null);

        $this->assertContains('4959678612@mail.ru', $tit->fresh()->senders);
        $this->assertSame($tit->id, $message->thread->fresh()->vendor_id, 'ветка получает вендора по отправителю пересылки');
        $this->assertSame($tit->id, Vendor::forSender('4959678612@mail.ru', Scope::Offers)?->id);
    }

    public function test_bounce_waits_for_us_and_names_recipient(): void
    {
        $message = $this->letter('Ваше сообщение не доставлено. Mail failure.',
            "Это письмо создано автоматически сервером Mail.ru.\n\nК сожалению, Ваше письмо не может быть доставлено одному или нескольким получателям:\n\n  ivanton21@yandex.ru\n    host mx.yandex.ru\n    554 5.7.1 Message rejected under suspicion of SPAM",
            'mailer-daemon@corp.mail.ru');
        app(Threads::class)->refresh($message->thread);

        $this->assertSame(Intent::Bounce->value, $message->intent);
        $this->assertNotNull($message->thread->fresh()->needs_reply_at, 'недоставка — в «Требуют внимания»');
        $this->assertSame('Не дошло до ivanton21@yandex.ru', NodeTitle::words($message));
        $this->assertFalse(Intent::isBounce('ТС не доставлено на стоянку', 'claims@alfastrah.test'), 'заявка парковки — не недоставка');
    }
}
