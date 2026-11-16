<?php

namespace Tests\Feature;

use App\Chats\Actions\PostMessage;
use App\Chats\Message;
use App\Notifications\ChatNotice;
use App\Notifications\TelegramChannel;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Всё, что приходит менеджеру в чат, — в Telegram (16.11.2026, владелец: «администратор, модератор, покупатель — всё в телегу»):
 * дело шага от площадки («нужно указать покупателя») идёт уведомлением чата целиком, короткое «Указать покупателя: …» в Telegram
 * тогда не дублируется; выключенные в настройках чаты Telegram менеджеру не выключают. Ошибка молчаливая и дорогая: менеджер не
 * узнал о деле, сделка стоит.
 */
class ChatTelegramTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): Offer
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        // Telegram привязан, а чаты он выключил — и в ленте, и в Telegram.
        $this->manager->forceFill(['telegram_chat_id' => 555, 'notification_settings' => ['off' => ['chats'], 'telegram_off' => ['chats']]])->save();
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'chat_enabled' => true])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        foreach (['Поставщик согласовал'] as $label) {
            $o = $offer->fresh();
            $deal = $o->deal()->first();
            $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
            $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
            app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
        }

        // Дело менеджера не о согласии — «Подписание» (ждём менеджера): входом в этап, как обычным ходом сделки.
        app(EnterStage::class)($offer->fresh(), Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Подписание')->firstOrFail());

        return $offer->fresh();
    }

    public function test_step_message_and_staff_message_reach_manager_telegram(): void
    {
        $offer = $this->deal();
        $this->assertTrue($offer->stage()->waits_for === \App\Workflow\WaitsFor::Manager && ! $offer->stage()->asksConsent(), 'шаг менеджера, не согласие: '.$offer->stage()->name);
        $requirement = $offer->deal()->first()->openRequirement()->firstOrFail();
        $step = Message::where('requirement_id', $requirement->id)->firstOrFail();

        // Дело шага в чате — менеджеру уведомлением чата, и в Telegram, хотя чаты он выключил.
        Notification::assertSentTo($this->manager, ChatNotice::class, function (ChatNotice $n, array $channels) use ($step) {
            return $n->message()->is($step) && in_array(TelegramChannel::class, $channels, true);
        });
        // Короткое «Указать покупателя: …» — без Telegram: то же самое уже пришло сообщением.
        Notification::assertSentTo($this->manager, YourTurnNotice::class, fn ($n, array $channels) => ! in_array(TelegramChannel::class, $channels, true));

        // Сообщение админа — целиком, и в Telegram.
        $long = str_repeat('Длинное сообщение администрации. ', 60);
        app(PostMessage::class)($step->chat, $this->admin, $long);
        Notification::assertSentTo($this->manager, ChatNotice::class, function (ChatNotice $n, array $channels) use ($long) {
            return $n->message()->text === trim($long) && in_array(TelegramChannel::class, $channels, true)
                && str_contains(implode("\n", $n->toTelegram()['lines']), mb_substr(trim($long), -40));
        });
    }
}
