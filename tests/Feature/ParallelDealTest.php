<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Http\Admin\MailController;
use App\Mail\Template;
use App\Notifications\BidAcceptedNotice;
use App\Notifications\ParallelBuyerNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\Give;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\TakeParallel;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Parallels;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Параллельная сделка (07.11.2026, Прадо 781R/046/01529/26: Тужиков и Зайцев). Утечки и деньги: второй менеджер не должен
 * узнать ни о первом, ни о письме с чужими реквизитами; переключение не сбрасывает шаг и перевыставляет счёт; закрытие.
 */
class ParallelDealTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $first;

    private User $second;

    private Offer $offer;

    private Bid $reserve;

    /** Альфа, сделка у первого; второй — в резерве. `$upTo` — до какого шага довести главную. */
    private function setUpDeal(string $upTo = 'Нет реквизитов'): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->first = User::create(['name' => 'Евгений Тужиков', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->second = User::create(['name' => 'Виталий Зайцев', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $brand = Brand::create(['slug' => 'toyota', 'name' => 'Toyota']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'prices_include_vat' => false, 'bids_close_at' => now()->addDay()]);
        $offer->addMedia(UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');
        $offer->forceFill(['state' => OfferState::Open])->save();
        foreach ([$this->first, $this->second] as $m) {
            $offer->viewers()->create(['user_id' => $m->id, 'opens_at' => now()]);
        }
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $this->first, 1200000);
        $this->reserve = app(PlaceBid::class)($offer->fresh(), $this->second, 1200000);
        $deal = app(AcceptBid::class)($bid, $this->admin, 50000, CommissionMode::Payout, scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $this->offer = $offer->fresh();
        foreach (['Поставщик согласовал', 'Беру в работу', 'Поставщик просит реквизиты'] as $label) {
            if ($this->offer->fresh()->stage()->name === $upTo) {
                break;
            }
            $this->exit($label);
        }
        $this->assertSame($upTo, $this->offer->fresh()->stage()->name);

        return $deal->fresh();
    }

    private function exit(string $label, ?User $as = null): void
    {
        $o = $this->offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $as ?? ($exit->actor === Actor::Manager ? $deal->buyer : $this->admin));
    }

    private function giveParallel(): Deal
    {
        return app(Give::class)(Giving::bid($this->reserve->fresh(), scheme: DealScheme::forOffer($this->offer->fresh()), ownerPrice: 900000,
            commission: 50000, parallel: true), $this->admin);
    }

    private function fillBuyer(Deal $deal, User $manager): void
    {
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $manager);
        $this->passportFor($manager);
    }

    private function page(User $who, Deal $deal): string
    {
        return $this->actingAs($who)->get(Surface::Site->url('/deals/'.$deal->id))->assertOk()->getContent();
    }

    public function test_parallel_has_own_steps_hides_main_and_switch_reissues(): void
    {
        $main = $this->setUpDeal();
        $par = $this->giveParallel();

        // Поручено: главная как была, у второго — своя сделка на «Нет реквизитов» со своей просьбой.
        $this->assertSame(DealState::Parallel, $par->state);
        $this->assertSame(BidState::Accepted, $this->reserve->fresh()->state);
        $this->assertSame('Нет реквизитов', $par->shadowStage->name);
        $this->assertSame('Заполните данные покупателя', $par->openRequirement()->first()?->title);
        $this->assertTrue($main->fresh()->isActive());
        $this->assertNotNull($main->openRequirement()->first(), 'просьба главной на месте');
        Notification::assertSentTo($this->second, BidAcceptedNotice::class, fn ($n) => $n->href() === '/deals/'.$par->id);

        // Главный заполнил, письмо ушло, счёт пришёл — у второго по-прежнему его «Нет реквизитов», ни слова о том.
        $this->fillBuyer($main, $this->first);
        $this->assertSame('Письмо с реквизитами', $this->offer->fresh()->stage()->name);
        $this->offer->fresh()->position(Track::Sale)->update(['waits_for' => null, 'payload' => ['letter_at' => now()->toIso8601String()]]);
        $this->exit('Счёт от поставщика получен');
        $this->assertSame('Оплата счёта', $this->offer->fresh()->stage()->name);
        $this->assertSame('Нет реквизитов', $par->fresh()->shadowStage->name);
        $html = $this->page($this->second, $par);
        $this->assertStringContainsString('Заполните данные покупателя', $html);
        foreach (['Тужиков', 'Оплата счёта', 'Платёжное поручение', 'Счёт от поставщика'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, 'параллельный видит: '.$leak);
        }
        // Его «Сделки»: своя строка в «Ваш ход».
        $this->actingAs($this->second)->get(Surface::Site->url('/deals'))->assertOk()->assertSee('Ваш ход')->assertSee('Заполните данные покупателя');

        // Второй прислал реквизиты: у него «Письмо с реквизитами» (письма нет), нам — громко; машина не сдвинулась.
        $this->fillBuyer($par, $this->second);
        $par = $par->fresh();
        $this->assertSame('Письмо с реквизитами', $par->shadowStage->name);
        $this->assertNull($par->openRequirement()->first());
        $this->assertSame('Оплата счёта', $this->offer->fresh()->stage()->name);
        $this->assertSame(0, $par->invoices()->count(), 'у параллельной счетов нет');
        Notification::assertSentTo($this->admin, ParallelBuyerNotice::class);
        $html = $this->page($this->second, $par);
        foreach (['Тужиков', 'Оплата счёта', 'Счёт от поставщика', 'Платёжное поручение'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, 'параллельный видит: '.$leak);
        }

        // CRM: блок «Параллельно» с «Взять в работу» и вопросом про перевыставление.
        $crm = $this->actingAs($this->admin)->get(Surface::Crm->url('/offers/'.$this->offer->number))->assertOk();
        $crm->assertSee('Параллельно')->assertSee('Взять в работу')->assertSee('перевыставить счёт');

        // Переключились: шаг — снова письмо с реквизитами, письмо «перевыставить», главный — параллельным, тихо.
        ['deal' => $taken, 'reissue' => $reissue] = app(TakeParallel::class)($par, $this->admin);
        $this->assertTrue($reissue);
        $this->assertTrue($taken->isActive());
        $this->assertSame($par->id, $taken->id, 'та же сделка: ссылки у менеджера живы');
        $main = $main->fresh();
        $this->assertSame(DealState::Parallel, $main->state);
        $this->assertSame('Письмо с реквизитами', $main->shadowStage->name);
        $offer = $this->offer->fresh();
        $this->assertSame('Письмо с реквизитами', $offer->stage()->name);
        $this->assertSame(WaitsFor::Us, $offer->position(Track::Sale)->waits_for, 'письмо ждёт отправки');
        $url = (string) $offer->vendorLetterUrl();
        $reissueTpl = Template::where('name', 'Поставщику: перевыставить счёт')->firstOrFail();
        $this->assertStringContainsString('template='.$reissueTpl->id, $url);
        $this->assertStringContainsString('{{ buyer }}', $reissueTpl->body);
        $this->assertStringContainsString('перевыставить', $reissueTpl->body);
        $this->assertStringContainsString('Виталий Зайцев', MailController::placeholders($offer)['buyer']);
        $html = $this->page($this->first, $main);
        foreach (['Зайцев', 'Оплата счёта', 'Счёт от поставщика'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, 'прежний главный видит: '.$leak);
        }
        // Его путь — с тем, что он прошёл до переключения (не обрыв на «Письме с реквизитами»).
        $this->assertStringContainsString('Согласование с поставщиком', $html);

        $this->assertNotNull($taken->reissue_at);
        // Письмо ушло, счёт пришёл заново.
        $offer->position(Track::Sale)->update(['waits_for' => null, 'payload' => ['letter_at' => now()->toIso8601String()]]);
        $this->exit('Счёт от поставщика получен');

        // Оплатили — переключаться больше не на кого: параллельная закрыта, у прежнего — отменённая сделка.
        $pay = $this->offer->fresh()->stage();
        app(EnterStage::class)($this->offer->fresh(), $pay->exitsFor(Actor::Manager, $taken)->first()->to, $this->second, [], $pay->exitsFor(Actor::Manager, $taken)->first());
        $this->assertSame(DealState::Cancelled, $main->fresh()->state);
        $this->assertSame(BidState::Declined, Bid::find($main->bid_id)->state);
        // Закрытая — без шагов машины: ни оплаты нового, ни его счёта.
        $closed = $this->actingAs($this->first)->get(Surface::Site->url('/deals/'.$main->id));
        if ($closed->status() === 200) {
            $closed->assertDontSee('Оплата счёта')->assertDontSee('Зайцев')->assertDontSee('Платёжное поручение');
        } else {
            $closed->assertRedirect();
        }
        $this->actingAs($this->first)->get(Surface::Site->url('/deals?view=archive'))->assertOk()->assertDontSee('Оплата счёта');
    }

    public function test_own_consent_retreat_and_switch_at_consent(): void
    {
        $main = $this->setUpDeal('Согласие менеджера');
        $par = $this->giveParallel();
        $this->assertSame('Согласие менеджера', $par->shadowStage->name);
        $req = $par->openRequirement()->firstOrFail();
        $exits = Parallels::exits($par, $req->stage);
        $this->assertSame(['Беру в работу', 'Я передумал'], $exits->pluck('label')->sort()->values()->all(), 'без «Забираю в гараж»');

        // Его «Беру в работу» двигает только его шаг.
        app(AnswerRequirement::class)($req, $exits->first(fn ($e) => $e->takes()), $this->second);
        $this->assertSame('Подтверждение покупки', $par->fresh()->shadowStage->name);
        $this->assertSame('Согласие менеджера', $this->offer->fresh()->stage()->name);
        // Его шаг впереди главной — ждём поставщика, а не «Ждём XCar».
        $this->assertSame(WaitsFor::Supplier, Parallels::position($par->fresh()->load('offer'))->waitsForManager());

        // Взяли его, пока главный не ответил: его согласие проходит шаг, у прежнего — его собственный вопрос.
        app(TakeParallel::class)($par, $this->admin);
        $this->assertSame('Подтверждение покупки', $this->offer->fresh()->stage()->name);
        $main = $main->fresh();
        $this->assertSame('Согласие менеджера', $main->shadowStage->name);
        $mainReq = $main->openRequirement()->firstOrFail();

        // Прежний «Я передумал» — закрывается только его, машина у нового.
        $retreat = Parallels::exits($main, $mainReq->stage)->first(fn ($e) => $e->isRetreat());
        app(AnswerRequirement::class)($mainReq, $retreat, $this->first);
        $this->assertSame(DealState::Cancelled, $main->fresh()->state);
        $this->assertTrue($par->fresh()->isActive());
        $this->assertSame(OfferState::Sold, $this->offer->fresh()->state);
    }

    public function test_main_retreat_keeps_parallel_and_it_can_be_taken(): void
    {
        $main = $this->setUpDeal('Согласие менеджера');
        $par = $this->giveParallel();
        // Главный передумал: машина снова в приёме, параллельная жива.
        $this->exit('Я передумал', $this->first);
        $this->assertSame(DealState::Cancelled, $main->fresh()->state);
        $this->assertSame(OfferState::Open, $this->offer->fresh()->state);
        $this->assertTrue($par->fresh()->isParallel());

        // В CRM она видна над подтверждениями и берётся в работу — сделка снова идёт, у него.
        $this->actingAs($this->admin)->get(Surface::Crm->url('/offers/'.$this->offer->number))->assertOk()->assertSee('Параллельно');
        app(TakeParallel::class)($par, $this->admin);
        $this->assertTrue($par->fresh()->isActive());
        $this->assertSame(OfferState::Sold, $this->offer->fresh()->state);
        $this->assertSame($par->id, $this->offer->fresh()->deal->id);
    }

    public function test_guards_and_drop(): void
    {
        $main = $this->setUpDeal();
        $par = $this->giveParallel();
        // Второй раз тому же — нельзя; тому, у кого главная, — тоже.
        try {
            $this->giveParallel();
            $this->fail('поручили дважды');
        } catch (ValidationException) {
        }
        // Шаг главной не закрывает просьбу параллельной.
        $this->fillBuyer($main, $this->first);
        $this->assertNotNull(Requirement::where('deal_id', $par->id)->whereNull('done_at')->first());

        // «Снять» — у него отменённая сделка, подтверждение отклонено, главной ничего.
        $this->actingAs($this->admin)->post(Surface::Crm->url('/work/deals/'.$par->id.'/drop'))->assertRedirect();
        $this->assertSame(DealState::Cancelled, $par->fresh()->state);
        $this->assertSame(BidState::Declined, $this->reserve->fresh()->state);
        $this->assertTrue($main->fresh()->isActive());
        $this->assertNull(Requirement::where('deal_id', $par->id)->whereNull('done_at')->first());
    }
}
