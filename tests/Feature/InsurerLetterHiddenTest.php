<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Notifications\InsurerReplyNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Events\InsurerReplied;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Письма страховой менеджер не видит ни при каких условиях (03.11.2026, владелец: X6 Митрофанова — в «урезанном» ответе Альфы
 * остались «сумма 5189000,00» и «максимальная ставка 5500000,00»). На странице сделки — только телефоны владельца; ответ без
 * телефона менеджеру не сообщается. Утечка тут молчалива и дорога.
 */
class InsurerLetterHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_only_owner_phones(): void
    {
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 5189000, 'asking_price' => 5600000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 5600000), $admin, scheme: DealScheme::Prime);
        $deal->forceFill(['agreed_at' => now()])->save();

        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => '0790/046/09068/26', 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $letter = fn (string $body, int $min) => Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in',
            'from_email' => 'implementation@alfastrah.ru', 'from_name' => 'implementation', 'subject' => 'RE: 0790/046/09068/26',
            'date_at' => now()->addMinutes($min), 'text_body' => $body]);
        $page = fn () => $this->actingAs($manager)->get("http://xcar.localhost/deals/{$deal->id}")->assertOk();

        // Ставка аукциона без телефона — ни уведомления, ни блока, ни слова из письма.
        $bid = $letter("Добрый день!\r\nВаше предложение в сумме 5189000,00 является не актуальным.\r\nМаксимальная ставка 5500000,00\r\n\r\nС уважением,\r\nБерсенева Елена\r\n", 1);
        InsurerReplied::dispatch($deal->fresh(), $bid);
        Notification::assertNotSentTo($manager, InsurerReplyNotice::class);
        $page()->assertDontSee('5189000')->assertDontSee('5500000')->assertDontSee('ставка')->assertDontSee('Контакты владельца');

        // Контакт владельца — только номер, текст вокруг (сумма, 8-800) не виден.
        $contact = $letter("Добрый день! Собственник Иванов Иван, +7 (900) 123-45-67, цена 5189000.\r\nГорячая линия 8-800-333-0-999\r\n", 2);
        InsurerReplied::dispatch($deal->fresh(), $contact);
        Notification::assertSentTo($manager, InsurerReplyNotice::class, fn ($n) => str_starts_with($n->title(), 'Контакты владельца'));
        $page()->assertSee('Контакты владельца')->assertSee('+7 900 123-45-67')
            ->assertDontSee('5189000')->assertDontSee('Иванов')->assertDontSee('800 333')->assertDontSee('Собственник');
    }
}
