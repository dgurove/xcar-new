<?php

namespace Tests\Feature;

use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\Message;
use App\Notifications\ChatNotice;
use App\Notifications\Notify;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\ManagerAsk;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\AttachChatFiles;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Дела менеджера там, где он смотрит (10.11.2026, владелец: «пишут в чат, а не кнопку жмут»; «Забрал» за менеджеров жали
 * админы): ход — и сообщением площадки в чате сделки с кнопками шага, ответ оттуда закрывает шаг, устаревшая кнопка не
 * отвечает на новый; «Ещё нет» прячет вопрос до завтра; реквизиты фотографией — ход наш. Ошибки молчаливые: кнопка в чате
 * ответила бы не на тот шаг, а «Ждут вас» и CRM разошлись бы в том, чей ход.
 */
class ManagerAsksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    /** Сделка Альфы на «Согласии менеджера» — поставщик согласовал, ждём «Беру в работу». */
    private function deal(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'chat_enabled' => true])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $this->exit($offer, 'Поставщик согласовал');
        $this->assertSame('Согласие менеджера', $offer->fresh()->stage()->name);

        return [$offer->fresh(), $offer->fresh()->deal()->first()];
    }

    private function exit(Offer $offer, string $label): void
    {
        $o = $offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
    }

    public function test_step_message_in_chat_answers_from_chat_and_stale_key_does_nothing(): void
    {
        [$offer, $deal] = $this->deal();
        $requirement = $deal->openRequirement()->firstOrFail();
        $message = Message::where('requirement_id', $requirement->id)->first();
        $this->assertNotNull($message, 'ход менеджера — сообщением с кнопками в чате сделки');
        $this->assertSame(Notify::CONSENT_ASK, $message->text);
        $this->assertSame(1, Message::where('requirement_id', $requirement->id)->count());
        // Вопрос «Берём?» — и уведомлением чата, как с 06.11.2026.
        Notification::assertSentTo($this->manager, ChatNotice::class, fn ($n) => $n->message()->text === Notify::CONSENT_ASK);

        $chat = $message->chat;
        $this->actingAs($this->manager)->get("http://xcar.localhost/chats/{$chat->id}/messages?after=0")->assertOk()
            ->assertSee('name="requirement" value="'.$requirement->id.'"', false)->assertSee('Беру в работу');

        $take = $requirement->stage->exits->first(fn ($e) => $e->takes());
        $this->actingAs($this->manager)->post("http://xcar.localhost/deals/{$deal->id}/reply", ['exit' => $take->id, 'requirement' => $requirement->id, 'back' => "/deals/chats/{$chat->id}"])
            ->assertRedirect("/deals/chats/{$chat->id}");
        $this->assertNotNull($requirement->fresh()->done_at);
        $this->assertSame('Подтверждение покупки', $offer->fresh()->stage()->name);
        // Под сообщением — ответ, а не кнопки.
        $this->actingAs($this->manager)->get("http://xcar.localhost/chats/{$chat->id}/messages/{$message->seq}")->assertOk()
            ->assertDontSee('name="requirement"', false)->assertSee('msg-step-done', false);

        // Старая кнопка (вкладка не обновилась) на новый шаг не отвечает.
        $this->actingAs($this->manager)->post("http://xcar.localhost/deals/{$deal->id}/reply", ['exit' => $take->id, 'requirement' => $requirement->id])
            ->assertSessionHas('toast', 'Ответ уже не нужен');
        $this->assertSame('Подтверждение покупки', $offer->fresh()->stage()->name);
    }

    public function test_later_hides_question_until_tomorrow(): void
    {
        [, $deal] = $this->deal();
        $ask = ManagerAsk::for($deal->fresh());
        $this->assertTrue($ask->taps());
        $this->actingAs($this->manager)->post("http://xcar.localhost/deals/{$deal->id}/later")->assertRedirect();
        $this->assertTrue($deal->openRequirement()->first()->prompted_at->isToday());
    }

    public function test_requisites_by_photo_make_it_our_turn(): void
    {
        [$offer, $deal] = $this->deal();
        $this->exit($offer, 'Беру в работу');
        $this->exit($offer, 'Поставщик просит реквизиты');
        $this->assertSame('Нет реквизитов', $offer->fresh()->stage()->name);
        $this->assertNotNull(ManagerAsk::for($deal->fresh()));
        // Остальные шаги — сообщением с кнопками, но без второго уведомления: о ходе сказал `YourTurnNotice`.
        $step = Message::where('requirement_id', $deal->openRequirement()->firstOrFail()->id)->firstOrFail();
        $this->assertStringStartsWith('Привет! ', $step->text);
        Notification::assertNotSentTo($this->manager, ChatNotice::class, fn ($n) => $n->message()->is($step));

        $this->actingAs($this->manager)->post("http://xcar.localhost/deals/{$deal->id}/buyer-photos", [
            'kind' => 'company', 'files' => [UploadedFile::fake()->image('card.jpg', 800, 600)],
        ])->assertRedirect();
        $deal = $deal->fresh();
        $this->assertNotNull($deal->buyer_scans_at);
        $buyer = DealContract::for($deal)->buyer;
        $this->assertNotNull($buyer, 'покупатель встал в сделку');
        $this->assertSame($this->manager->id, $buyer->manager_id);
        $this->assertCount(1, $buyer->party->getMedia('card'));
        // У менеджера дела нет, у нас — «Внести реквизиты по фото».
        $this->assertNull($deal->managerTask());
        $this->assertFalse(Deal::whereKey($deal->id)->whereRaw(Deal::MANAGER_TURN_SQL)->exists());
        $this->assertSame('gap:photos', $offer->fresh()->position(Track::Sale)->todo()['key']);
    }

    public function test_chat_photo_goes_into_step_files_and_stays_in_chat(): void
    {
        [$offer, $deal] = $this->deal();
        $chat = Chat::where('offer_id', $offer->id)->where('user_id', $this->manager->id)->firstOrFail();
        $message = app(PostMessage::class)($chat, $this->manager, 'чек', [UploadedFile::fake()->image('check.jpg', 600, 800)]);
        $requirement = Requirement::where('deal_id', $deal->id)->whereNull('done_at')->firstOrFail();

        $this->assertSame(1, app(AttachChatFiles::class)($requirement, $message->load('files')));
        $this->assertCount(1, $requirement->fresh()->getMedia('files'));
        Storage::disk('private')->assertExists($message->files->first()->path);
    }
}
