<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Scope;
use App\Notifications\OfferPublishedNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\LapseRefused;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidState;
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
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Отказ менеджера (19.11.2026, начальник): есть резерв — машина ждёт админа во вкладке «Менеджер отказался», никого не зовём;
 * нет — сутки в продаже всем, никто не подтвердил — в архив и поставщику «не актуально». Ошибка молчалива и дорога: машина
 * висит, поставщику никто не пишет.
 */
class RefusalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00', 'Europe/Moscow'));
        config(['xcar.telegram.token' => '', 'xcar.mercure.hub' => '']);
        Queue::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => Scope::Offers, 'imap_host' => 'imap.test',
            'imap_username' => 'deal@xcar.test', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'deal@xcar.test', 'smtp_password' => 'x']);
    }

    /** Машина на «Согласии менеджера» у $taker; $others подтвердили и остались резервом. */
    private function atConsent(User $taker, array $others = []): array
    {
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay(), 'contact_email' => 'claims@alfa.test']);
        $offer->forceFill(['state' => OfferState::Open, 'no_brand' => true])->save();
        $offer->forceFill(['number' => 2611990000 + $offer->id])->save();
        // Готова к продаже (марки нет — прицеп, кадр есть): иначе возврат в приём остановит «не хватает: марка, фото».
        $offer->addMedia(UploadedFile::fake()->image('1.jpg', 400, 300))->toMediaCollection('photos');
        foreach ([$taker, ...$others] as $m) {
            $offer->viewers()->create(['user_id' => $m->id, 'opens_at' => now()]);
        }
        app(EnterStage::class)($offer, Stage::where('workflow_id', $this->vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        foreach ($others as $m) {
            app(PlaceBid::class)($offer->fresh(), $m, 1000000);
        }
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $taker, 1000000), $this->admin, scheme: DealScheme::Prime);
        app(TakeExit::class)($offer->fresh(), $this->position($offer)->stage->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $this->admin);
        $this->assertSame('Согласие менеджера', $this->position($offer)->stage->name);

        return [$offer, $deal];
    }

    private function position(Offer $offer): Position
    {
        return Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail();
    }

    private function refuse(Offer $offer, User $manager): void
    {
        $exit = $this->position($offer)->stage->exits->first(fn ($x) => $x->isRetreat());
        $this->actingAs($manager)->post('/deals/'.$offer->fresh()->deal->id.'/reply', ['exit' => $exit->id])->assertSessionHasNoErrors();
    }

    private function manager(string $phone): User
    {
        return User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    public function test_refusal_with_reserve_waits_for_admin(): void
    {
        [$roman, $denis] = [$this->manager('79000000002'), $this->manager('79000000003')];
        [$offer, $deal] = $this->atConsent($roman, [$denis]);
        Notification::fake();

        $this->refuse($offer, $roman);

        $offer = $offer->fresh();
        $this->assertSame(OfferState::Open, $offer->state);
        $this->assertNotNull($offer->refused_at);
        $this->assertNull($offer->lapse_since);
        $this->assertTrue($offer->bids_close_at->lte(now()));
        $this->assertSame('refused', $deal->fresh()->close_reason);
        $this->assertSame(BidState::Active, $offer->bids()->where('user_id', $denis->id)->first()->state);
        Notification::assertNotSentTo($denis, OfferPublishedNotice::class);
        // Часы не трогают: решает админ.
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(0, app(LapseRefused::class)->due());
        $this->assertSame(OfferState::Open, $offer->fresh()->state);
    }

    public function test_admin_returns_refused_to_sale_with_lapse(): void
    {
        [$roman, $denis] = [$this->manager('79000000002'), $this->manager('79000000003')];
        [$offer] = $this->atConsent($roman, [$denis]);
        $this->refuse($offer, $roman);
        $this->assertSame('Менеджер отказался', $offer->fresh()->crmTab($this->admin));
        Carbon::setTestNow(now()->addMinutes(10));

        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$offer->number.'/refused-resale', ['hours' => 24])->assertSessionHasNoErrors()->assertRedirect();
        $offer = $offer->fresh();
        $this->assertNotNull($offer->lapse_since);
        $this->assertSame('2026-11-20 12:10', $offer->bids_close_at->format('Y-m-d H:i'));
        $this->assertSame('Есть подтверждения', $offer->crmTab($this->admin));

        // Резерв Дениса был до возврата — не «новое» подтверждение: через сутки снимаем и пишем поставщику.
        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->assertSame(1, app(LapseRefused::class)->due());
        $this->assertSame(OfferState::Archived, $offer->fresh()->state);
        $this->assertSame(BidState::Declined, $offer->bids()->where('user_id', $denis->id)->first()->state);
        $this->assertSame(1, Message::where('direction', Direction::Out)->count());
    }

    public function test_refusal_without_reserve_lapses_with_letter(): void
    {
        $roman = $this->manager('79000000002');
        [$offer] = $this->atConsent($roman);

        $this->refuse($offer, $roman);

        $offer = $offer->fresh();
        $this->assertSame(OfferState::Open, $offer->state);
        $this->assertNotNull($offer->lapse_since);
        $this->assertSame('2026-11-20 12:00', $offer->bids_close_at->format('Y-m-d H:i'));
        $this->assertSame(0, app(LapseRefused::class)->due());

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->assertSame(1, app(LapseRefused::class)->due());
        $this->assertSame(OfferState::Archived, $offer->fresh()->state);
        $letter = Message::where('direction', Direction::Out)->latest('id')->first();
        $this->assertNotNull($letter);
        $this->assertStringContainsString('не актуальна', strip_tags((string) $letter->html_body));
        $this->assertSame(0, app(LapseRefused::class)->due());
    }

    public function test_new_bid_during_lapse_keeps_car(): void
    {
        [$roman, $denis] = [$this->manager('79000000002'), $this->manager('79000000003')];
        [$offer] = $this->atConsent($roman);
        $offer->viewers()->firstOrCreate(['user_id' => $denis->id], ['opens_at' => now()]);

        $this->refuse($offer, $roman);
        Carbon::setTestNow(now()->addHour());
        app(PlaceBid::class)($offer->fresh(), $denis, 1000000);
        Carbon::setTestNow(now()->addDay());

        $this->assertSame(0, app(LapseRefused::class)->due());
        $this->assertSame(OfferState::Open, $offer->fresh()->state);
        $this->assertNull($offer->fresh()->lapse_since);
        $this->assertSame(0, Message::where('direction', Direction::Out)->count());
    }
}
