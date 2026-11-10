<?php

namespace Tests\Feature;

use App\Notifications\BuyerAskNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
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
 * Покупатель — дело менеджера с «Беру в работу» (10.11.2026, владелец: «реквизиты требовать сразу, а не когда страховая
 * запросила»): дело и уведомление, форма открыта до «Нет реквизитов», запрос страховой — «Отправить реквизиты», шаг проходит
 * сам. Ошибка молчалива: реквизиты снова будут ждать менеджера в день, когда страховая их ждёт.
 */
class EarlyBuyerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $this->exit($offer, 'Поставщик согласовал');

        return [$offer->fresh(), $deal->fresh()];
    }

    private function exit(Offer $offer, string $label): void
    {
        $o = $offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
    }

    public function test_consent_asks_buyer_early_and_insurer_request_sends_requisites(): void
    {
        [$offer, $deal] = $this->deal();
        $this->assertSame(DealScheme::Insurer, $deal->scheme);
        $this->assertNull($deal->buyer_asked_at, 'до согласия не просим');

        // «Беру в работу» со страницы сделки — окно «Кто покупатель?» и дело.
        $exit = $offer->stage()->exits->first(fn ($e) => $e->takes());
        $this->actingAs($this->manager)->post('/deals/'.$deal->id.'/reply', ['exit' => $exit->id])
            ->assertRedirect($deal->href())->assertSessionHas('open_sheet', 'deal-buyer-ask-'.$deal->id);
        $deal = $deal->fresh();
        $this->assertNotNull($deal->buyer_asked_at);
        $this->assertSame('Подтверждение покупки', $offer->fresh()->stage()->name);
        $this->assertSame(['Укажите покупателя', 'buyer'], [$deal->managerTask()['title'], $deal->managerTask()['kind']]);
        $this->assertTrue(Deal::whereKey($deal->id)->whereRaw(Deal::MANAGER_TURN_SQL)->exists(), 'в «Ваш ход» и бейдж');
        Notification::assertSentTo($this->manager, BuyerAskNotice::class);
        $this->actingAs($this->manager)->get('/deals/'.$deal->id)->assertOk()->assertSee('Укажите покупателя')->assertSee('Кто покупатель?');

        // Покупатель до запроса страховой — сохраняется (раньше 404), паспорт со сканом — дело гаснет.
        $this->actingAs($this->manager)->put('/deals/'.$deal->id.'/contract', ['buyer_id' => 'me'])->assertSessionHasNoErrors();
        $this->passportFor($this->manager);
        $this->assertNull($deal->fresh()->buyer_asked_at);
        $this->assertNull($deal->fresh()->managerTask());

        // Страховая просит реквизиты — у нас «Отправить реквизиты», «Нет реквизитов» проходит сам.
        $this->actingAs($this->admin)->get(\App\Support\Surface::Crm->url('/offers/'.$offer->number))->assertOk()->assertSee('Отправить реквизиты');
        $this->exit($offer, 'Поставщик просит реквизиты');
        $this->assertSame('Письмо с реквизитами', $offer->fresh()->stage()->name);
    }

    public function test_later_keeps_task_and_cancel_clears_it(): void
    {
        [$offer, $deal] = $this->deal();
        $this->exit($offer, 'Беру в работу');
        $this->assertNotNull($deal->fresh()->buyer_asked_at);
        // Не дал — запрос страховой, как раньше, ведёт на «Нет реквизитов» у менеджера.
        $this->actingAs($this->admin)->get(\App\Support\Surface::Crm->url('/offers/'.$offer->number))->assertOk()
            ->assertSee('Менеджер не указал покупателя')->assertDontSee('Отправить реквизиты');
        $this->exit($offer, 'Поставщик просит реквизиты');
        $this->assertSame('Нет реквизитов', $offer->fresh()->stage()->name);
        $deal->fresh()->update(['state' => \App\Offers\DealState::Cancelled]);
        $this->assertNull($deal->fresh()->buyer_asked_at);
    }
}
