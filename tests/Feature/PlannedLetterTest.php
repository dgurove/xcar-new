<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\SendState;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\PlannedLetter;
use App\Offers\PlannedLetters;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «Спросить менеджера и запланировать письмо…» (27.11.2026, начальник; `Offers\PlannedLetters`): письмо шага после согласия ждёт
 * «Беру в работу» и уходит одно; отказ — не уходит. Ошибка молчалива и дорога: поставщику не ответили «актуально» или ответили,
 * хотя менеджер отказался.
 */
class PlannedLetterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    private Deal $deal;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.telegram.token' => '', 'xcar.mercure.hub' => '']);
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->account = Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => Scope::Offers, 'imap_host' => 'imap.test',
            'imap_username' => 'deal@xcar.test', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'deal@xcar.test', 'smtp_password' => 'x']);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        // Марка и фото — отказ менеджера возвращает машину в продажу (`ChangeOfferState::missing`).
        $this->offer->forceFill(['state' => OfferState::Open, 'no_brand' => true])->save();
        $this->offer->addMedia(\Illuminate\Http\UploadedFile::fake()->image('1.jpg', 400, 300))->toMediaCollection('photos');
        $this->offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($this->offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->manager, 1000000), $this->admin, scheme: DealScheme::Prime);
    }

    private function stage(): Stage
    {
        return Position::where('offer_id', $this->offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail()->stage;
    }

    /** Запланировать с «Спросить менеджера» — как «Запланировать» в окне письма. */
    private function plan(): PlannedLetter
    {
        $ask = $this->stage()->exits->firstWhere('label', 'Поставщик согласовал');
        $this->assertNotNull(PlannedLetters::letterFor($ask, $this->deal), 'после согласия есть письмо с шаблоном');

        return app(PlannedLetters::class)->plan($this->offer->fresh(), $ask, $this->account,
            ['to' => 'alfa@insurer.test', 'subject' => 'Актуально', 'body' => '<p>Подтверждаем актуальность</p>', 'offer' => $this->offer->id], $this->admin);
    }

    private function exit(string $label)
    {
        return $this->stage()->exits->firstWhere('label', $label);
    }

    public function test_letter_waits_for_take_and_goes_once(): void
    {
        $this->assertSame('Письмо о покупке', $this->stage()->name);
        $planned = $this->plan();
        $this->assertSame('Согласие менеджера', $this->stage()->name, 'менеджера спросили той же транзакцией');
        $this->assertSame(0, Message::where('subject', 'Актуально')->count(), 'до согласия письма нет');

        // Второй план на ту же сделку — нельзя.
        try {
            app(PlannedLetters::class)->plan($this->offer->fresh(), null, $this->account, ['to' => 'x@y.test', 'subject' => 'x', 'offer' => $this->offer->id], $this->admin);
            $this->fail('второе запланированное письмо');
        } catch (ValidationException) {
        }

        app(TakeExit::class)($this->offer->fresh(), $this->exit('Беру в работу'), Actor::Manager, $this->manager);

        $planned->refresh();
        $this->assertSame(PlannedLetter::SENT, $planned->state);
        $message = Message::find($planned->message_id);
        $this->assertNotNull($message);
        $this->assertSame(SendState::Queued, $message->send_state);
        $this->assertSame($this->offer->id, $message->thread->offer_id, 'ветка — машине: письмо засчитается шагу');

        // Повтор задания и подстраховка — второго письма нет.
        app(PlannedLetters::class)->release($planned->id);
        app(PlannedLetters::class)->sweep();
        $this->assertSame(1, Message::where('subject', 'Актуально')->count());
    }

    public function test_refusal_cancels_letter(): void
    {
        $planned = $this->plan();
        app(TakeExit::class)($this->offer->fresh(), $this->exit('Отказываюсь'), Actor::Manager, $this->manager);

        $this->assertSame(PlannedLetter::CANCELLED, $planned->fresh()->state);
        $this->assertSame(0, Message::where('subject', 'Актуально')->count());
    }

    public function test_letter_written_by_hand_is_not_sent_twice(): void
    {
        $planned = $this->plan();
        // Пока менеджер думал, письмо этого шаблона ушло руками (`MarkSupplierLetter` пишет его в «Историю»).
        $this->offer->log(\App\Offers\OfferEventType::SupplierLetter, $this->admin, ['template_id' => $planned->template_id]);
        app(TakeExit::class)($this->offer->fresh(), $this->exit('Беру в работу'), Actor::Manager, $this->manager);

        $this->assertSame(PlannedLetter::CANCELLED, $planned->fresh()->state);
        $this->assertSame(0, Message::where('subject', 'Актуально')->count());
    }

    public function test_sweep_sends_when_event_was_lost(): void
    {
        $planned = $this->plan();
        // Согласие прошло, а письмо по событию не ушло (выкладка посреди, очередь упала): запись ждёт.
        \Illuminate\Support\Facades\Event::forget(\App\Workflow\Events\StageEntered::class);
        app(TakeExit::class)($this->offer->fresh(), $this->exit('Беру в работу'), Actor::Manager, $this->manager);
        $this->assertSame(PlannedLetter::PENDING, $planned->fresh()->state);

        $this->travel(3)->minutes();
        app(PlannedLetters::class)->sweep();
        $this->assertSame(PlannedLetter::SENT, $planned->fresh()->state);
        $this->assertSame(1, Message::where('subject', 'Актуально')->count());
    }
}
