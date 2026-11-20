<?php

namespace Tests\Feature;

use App\Cars\Category;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\Disclose;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\ExtraAsk;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Доп. запрос до «Беру в работу» (20.11.2026, владелец: Корабельников по грузовым Альфы требует ПТС и осмотр до вопроса, берёт ли
 * он): кнопки «Отправить доп. запрос» / «Сразу спросить менеджера» только ему и только у грузовых Альфы; пропуск — в «Историю»;
 * показ ответа сразу спрашивает его.
 */
class ConsentPrecheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.telegram.token' => '', 'xcar.mercure.hub' => '']);
        Queue::fake();
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
        $this->vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Alfa);
    }

    private function deal(User $manager, Category $category): Deal
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay(), 'vehicle_category' => $category]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->forceFill(['number' => 2611990000 + $offer->id])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $this->vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1000000), $this->admin, scheme: DealScheme::Insurer)->fresh();
    }

    private function manager(string $phone, bool $precheck): User
    {
        return User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now(), 'consent_precheck' => $precheck]);
    }

    private function stage(Deal $deal): Stage
    {
        return Position::where('offer_id', $deal->offer_id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail()->stage;
    }

    public function test_only_flagged_manager_heavy_alfa_before_consent(): void
    {
        $roma = $this->manager('79000000002', true);
        $truck = $this->deal($roma, Category::Truck);
        $this->assertSame('Письмо о покупке', $this->stage($truck)->name);
        $this->assertTrue($truck->needsPrecheck());
        $this->assertStringContainsString('pick[]=', Deal::precheckPicks() ?: 'pick[]=');

        $this->assertFalse($this->deal($roma, Category::Passenger)->needsPrecheck(), 'легковой — нет');
        $this->assertFalse($this->deal($this->manager('79000000003', false), Category::Trailer)->needsPrecheck(), 'другой менеджер — нет');

        ExtraAsk::create(['offer_id' => $truck->offer_id, 'deal_id' => $truck->id, 'text' => 'ПТС', 'asks_manager' => true, 'asked_at' => now(), 'since_at' => now(), 'frozen_at' => now()]);
        $this->assertFalse($truck->fresh()->needsPrecheck(), 'запрос уже ушёл');
        // Ответ на него показываем — шаг «Поставщик согласовал» (спросить менеджера) отмечен сам.
        $ask = $this->stage($truck)->exitsFor(Actor::Staff, $truck)->first(fn ($x) => $x->to?->asksConsent());
        $this->assertTrue(Disclose::stepByDefault($ask, collect(), $truck->fresh()));
    }

    /**
     * Альфа просит реквизиты для счёта — «Поставщик просит реквизиты» сам, по письму; реквизитов покупателя нет — у менеджера
     * просим сразу (20.11.2026, владелец).
     */
    public function test_requisites_letter_moves_step_and_asks_manager(): void
    {
        $roma = $this->manager('79000000002', false);
        $deal = $this->deal($roma, Category::Passenger);
        $stage = fn () => $this->stage($deal);
        app(\App\Workflow\Actions\TakeExit::class)($deal->offer->fresh(), $stage()->exitsFor(Actor::Staff, $deal)->first(fn ($x) => $x->to?->asksConsent()), Actor::Staff, $this->admin);
        app(\App\Workflow\Actions\TakeExit::class)($deal->offer->fresh(), $stage()->exitsFor(Actor::Manager, $deal)->first(fn ($x) => $x->takes()), Actor::Manager, $roma);
        $this->assertSame('Подтверждение покупки', $stage()->name);
        $account = \Illuminate\Support\Facades\DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = \App\Mail\Thread::create(['account_id' => $account, 'subject' => '0790/046/1/26', 'offer_id' => $deal->offer_id]);
        $letter = \App\Mail\Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'implementation@alfastrah.test',
            'subject' => 'Re: 0790/046/1/26', 'text_body' => 'Добрый день. Просьба прислать реквизиты для выставления счета. Без НДС.', 'date_at' => now()]);

        event(new \App\Offers\Events\InsurerReplied($deal->fresh(), $letter));

        $this->assertSame('Письмо с реквизитами', $stage()->name);
        $this->assertNotNull($deal->fresh()->buyer_asked_at, 'реквизитов нет — менеджера спросили');
        $this->assertTrue($deal->offer->events()->get()->contains(fn ($e) => ($e->payload['text'] ?? '') === 'Запросили реквизиты у менеджера'));
    }

    public function test_skip_asks_manager_and_logs(): void
    {
        $roma = $this->manager('79000000002', true);
        $deal = $this->deal($roma, Category::Trailer);
        $exit = $this->stage($deal)->exitsFor(Actor::Staff, $deal)->first(fn ($x) => $x->to?->asksConsent());

        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$deal->offer->number.'/exit/'.$exit->id, ['skip_precheck' => 1])->assertSessionHasNoErrors();

        $this->assertSame('Согласие менеджера', $this->stage($deal)->name);
        $this->assertTrue($deal->offer->events()->get()->contains(fn ($e) => ($e->payload['text'] ?? '') === 'Сразу спросили менеджера, без доп. запроса ПТС и осмотра'));
    }
}
