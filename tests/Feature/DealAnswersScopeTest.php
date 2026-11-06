<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ответ менеджера в шаге пути — только идущей сделки (06.11.2026): Макаров «передумал» в сделке № 83, машину отдали
 * Трофимову, а в шаге его сделки висело «Менеджер ответил «Я передумал»» — начальник решил, что передумал и Трофимов.
 */
class DealAnswersScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_deal_answer_is_not_shown_in_new_deal_step(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $first = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $second = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 779000, 'owner_price' => 750000, 'asking_price' => 850000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        foreach ([$first, $second] as $m) {
            $offer->viewers()->create(['user_id' => $m->id, 'opens_at' => now()]);
        }
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $old = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $first, 850000), $admin, 20000, scheme: DealScheme::OwnerDkp);
        $stage = $offer->fresh()->stage();
        app(CancelDeal::class)($old, $admin, keepBid: true);
        $offer->refresh()->forceFill(['state' => OfferState::Open, 'bids_close_at' => now()->addDay()])->save();
        $new = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $second, 860000), $admin, 20000, scheme: DealScheme::OwnerDkp);
        $stage = $offer->fresh()->stage();
        Requirement::create(['offer_id' => $offer->id, 'deal_id' => $old->id, 'stage_id' => $stage->id, 'user_id' => $first->id, 'title' => 'Согласие',
            'done_at' => now(), 'answer' => ['exit' => 'Я передумал', 'fields' => []]]);

        $page = fn () => $this->actingAs($admin)->get(Surface::Crm->url('/offers/'.$offer->number))->assertOk();
        $page()->assertDontSee('Я передумал');

        Requirement::create(['offer_id' => $offer->id, 'deal_id' => $new->id, 'stage_id' => $stage->id, 'user_id' => $second->id, 'title' => 'Согласие',
            'done_at' => now(), 'answer' => ['exit' => 'Беру в работу', 'fields' => []]]);
        $page()->assertSee('Денис Трофимов:', false)->assertSee('Беру в работу');
    }

    /**
     * Поставщик согласовал, менеджер передумал, машину приняли от другого на тех же условиях для поставщика — новая сделка
     * сразу спрашивает нового менеджера (06.11.2026: Трофимову вопрос не задали, «Спросить менеджера» никто не нажал).
     * Другая сумма поставщику — снова письмо о покупке.
     */
    public function test_new_manager_is_asked_at_once_when_supplier_already_agreed(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $first = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $second = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $third = User::create(['name' => 'Третий', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $brand = \App\Cars\Brand::create(['slug' => 'tank', 'name' => 'Tank']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->addMedia(\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');
        $offer->forceFill(['state' => OfferState::Open])->save();
        foreach ([$first, $second, $third] as $m) {
            $offer->viewers()->create(['user_id' => $m->id, 'opens_at' => now()]);
        }
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail();
        $refuse = function (User $m) use ($offer, $position) {
            $deal = $offer->fresh()->deal;
            app(TakeExit::class)($offer->fresh(), $position()->stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Я передумал'), Actor::Manager, $m);
            $offer->refresh()->forceFill(['bids_close_at' => now()->addDay()])->save();
        };

        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $first, 1000000), $admin, scheme: DealScheme::Prime);
        $this->assertSame('Письмо о покупке', $position()->stage->name);
        app(TakeExit::class)($offer->fresh(), $position()->stage->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $admin);
        // «Спросить менеджера» — ещё и сообщением от «Администрации XCar» в его чате по машине (06.11.2026).
        $asked = fn (User $m) => \App\Chats\Chat::platformWith($offer, $m)->first()?->messages()->where('author_kind', \App\Chats\AuthorKind::Staff)
            ->whereNull('author_id')->where('text', \App\Notifications\Notify::CONSENT_ASK)->count();
        $this->assertSame(1, $asked($first));
        $this->assertSame(1, \App\Chats\Chat::platformWith($offer, $first)->first()->unread_for_user);
        $refuse($first);

        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $second, 990000), $admin, scheme: DealScheme::Prime);
        $this->assertSame('Согласие менеджера', $position()->stage->name);
        $this->assertSame($second->id, Requirement::where('deal_id', $deal->id)->whereNull('done_at')->sole()->user_id);
        $this->assertSame(1, $asked($second), 'вопрос встал сам — и сообщение тоже');
        Notification::assertSentTo($second, \App\Notifications\ChatNotice::class, fn ($n) => $n->message()->text === \App\Notifications\Notify::CONSENT_ASK
            && in_array(\App\Notifications\Notify::CONSENT_ASK, $n->toTelegram()['lines'] ?? [], true));
        // Сотрудник ответил в чате — вопрос остаётся (стирается только автоответ).
        app(\App\Chats\Actions\PostMessage::class)(\App\Chats\Chat::platformWith($offer, $second)->first(), $admin, 'Ждём ответа');
        $this->assertSame(1, $asked($second));
        $chat = app(\App\Chats\Actions\OpenChat::class)($offer, $third, greet: false);
        app(\App\Chats\Actions\AutoReply::class)($chat);
        app(\App\Chats\Actions\PlatformMessage::class)($chat, 'Вопрос');
        app(\App\Chats\Actions\PostMessage::class)($chat, $admin, 'Ответ');
        $this->assertSame(['Вопрос', 'Ответ'], \App\Chats\Message::where('chat_id', $chat->id)->orderBy('seq')->pluck('text')->all(), 'автоответ стёрт, вопрос остался');

        // Поставщику другая сумма (вписали другую закупочную) — согласовывать заново.
        $refuse($second);
        app(\App\Offers\Actions\Give::class)(\App\Offers\Giving::bid(app(PlaceBid::class)($offer->fresh(), $third, 1000000), scheme: DealScheme::Prime, cost: 950000), $admin);
        $this->assertSame('Письмо о покупке', $position()->stage->name);
    }
}
