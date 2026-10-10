<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Handover;
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
 * «Согласие менеджера» (10.10.2026, владелец): пришли контакт и паспорт — менеджер видит их только тизером, пока не нажмёт
 * «Беру в работу»; «Я передумал» — тихой строкой. Ошибка здесь — паспорт и телефон уходят тому, кто сделку не взял.
 */
class ManagerConsentTeaserTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_files_are_teaser_until_manager_takes_deal(): void
    {
        Storage::fake('private');
        Storage::fake('cache');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 779000, 'owner_price' => 750000, 'asking_price' => 850000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 850000), $admin, 20000, CommissionMode::Payout, scheme: DealScheme::OwnerDkp);

        // Поставщик согласовал (ответ с контактом) — «Согласие менеджера».
        $o = $offer->fresh();
        app(TakeExit::class)($o, $o->stage()->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $admin);
        $this->assertSame('Согласие менеджера', $offer->fresh()->stage()->name);
        $this->assertTrue($deal->fresh()->awaitsConsent());
        $this->assertFalse(Handover::for($deal->fresh())->open);

        // Паспорт из ответа страховой.
        $image = imagecreatetruecolor(200, 120);
        imagefilledrectangle($image, 0, 0, 200, 120, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image);
        $passport = $offer->fresh()->addMediaFromString((string) ob_get_clean())->usingFileName('passport.jpg')
            ->withCustomProperties(['managers' => true, 'letter' => 1])->toMediaCollection('papers', 'private');

        $this->actingAs($manager)->get('/files/'.$passport->id)->assertNotFound();
        $teaser = $this->actingAs($manager)->get('/deals/'.$deal->id.'/teaser/'.$passport->id)->assertOk();
        $this->assertSame('image/jpeg', $teaser->headers->get('Content-Type'));
        $this->assertNotSame(file_get_contents($passport->getPath()), $teaser->getContent());

        // Кнопки: «Беру в работу» — главной, «Я передумал» — тихой строкой.
        $page = $this->actingAs($manager)->get('/deals/'.$deal->id)->assertOk();
        $page->assertSee('Беру в работу')->assertSee('retreat-link', false)->assertSee('Я передумал');

        // Взял — всё открыто.
        $o = $offer->fresh();
        app(TakeExit::class)($o, $o->stage()->exitsFor(Actor::Manager, $deal->fresh())->first(fn ($e) => $e->takes()), Actor::Manager, $manager);
        $this->assertFalse($deal->fresh()->awaitsConsent());
        $this->actingAs($manager)->get('/files/'.$passport->id)->assertOk();
    }

    public function test_changed_mind_is_quiet_retreat_back_to_bidding(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 779000, 'owner_price' => 750000, 'asking_price' => 850000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 850000), $admin, 20000, CommissionMode::Payout, scheme: DealScheme::OwnerDkp);
        $o = $offer->fresh();
        app(TakeExit::class)($o, $o->stage()->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $admin);

        $exit = $offer->fresh()->stage()->exitsFor(Actor::Manager, $deal->fresh())->first(fn ($e) => $e->isRetreat());
        $this->assertSame('Я передумал', $exit?->label);
        $this->assertSame(OfferState::Open, $exit->to->offer_state);
        // Счетов до согласия нет: этап согласия — согласование (`Stage::isAgreement` по «Беру в работу»).
        $this->assertTrue(Stage::find($exit->stage_id)->isAgreement());
        $this->assertFalse($deal->fresh()->issuedInvoices()->exists());
    }
}
