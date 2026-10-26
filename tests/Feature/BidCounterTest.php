<?php

namespace Tests\Feature;

use App\Notifications\CounterAnsweredNotice;
use App\Notifications\CounterOfferedNotice;
use App\Offers\Actions\MakeCounter;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidState;
use App\Offers\CounterState;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Подтверждение ниже порога и контрпредложение (26.10.2026). Закупочная 900 000, продажная 1 000 000: порог 960 000,
 * недопустимо ниже 920 000. Ошибка тут молчалива и дорога: машина уходит в сделку по чужой цене или не той стороне.
 */
class BidCounterTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'http://xcar.localhost';

    private const CRM = 'http://crm.xcar.localhost';

    private User $admin;

    private User $roman;

    private User $denis;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->roman = User::create(['name' => 'Роман', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->denis = User::create(['name' => 'Денис', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        foreach ([$this->roman, $this->denis] as $m) {
            $offer->viewers()->firstOrCreate(['user_id' => $m->id], ['opens_at' => now()]);
        }
        $this->offer = $offer->fresh();
    }

    public function test_zones(): void
    {
        $this->assertSame(960000, $this->offer->minBid());
        $this->assertSame(920000, $this->offer->hardMinBid());
        $this->assertSame('ok', $this->offer->bidZone(960000));
        $this->assertSame('low', $this->offer->bidZone(950000));
        $this->assertSame('bad', $this->offer->bidZone(919000));
        // Узкий спред: что принималось, не становится недопустимым.
        $narrow = new Offer(['floor_price' => 900000, 'asking_price' => 910000]);
        $this->assertSame('ok', $narrow->bidZone($narrow->minBid()));
        // Без закупочной красной зоны нет.
        $this->assertNull((new Offer(['asking_price' => 1000000]))->hardMinBid());
    }

    public function test_low_bid_needs_consent_and_bad_is_refused(): void
    {
        $url = self::SITE.'/offers/'.$this->offer->number.'/confirm';
        $this->actingAs($this->roman)->post($url, ['amount' => '915 000'])->assertSessionHasErrors(['amount' => PlaceBid::TOO_LOW]);
        $this->actingAs($this->roman)->post($url, ['amount' => '950 000'])->assertSessionHasErrors(['amount' => PlaceBid::NEEDS_CONSENT]);
        $this->assertSame(0, $this->offer->bids()->count());

        $this->actingAs($this->roman)->post($url, ['amount' => '950 000', 'low_ok' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(950000, $this->offer->bids()->first()->amount);

        $this->actingAs($this->roman)->postJson($url.'/check', ['amount' => '950000'])->assertExactJson(['zone' => 'low']);
        $this->actingAs($this->roman)->postJson($url.'/check', ['amount' => '980000'])->assertExactJson(['zone' => 'ok']);
    }

    public function test_agree_to_counter_opens_deal_at_counter_price(): void
    {
        Notification::fake();
        $bid = app(PlaceBid::class)($this->offer, $this->roman, 965000);

        $this->actingAs($this->admin)->post(self::CRM.'/confirmations/'.$bid->id.'/counter', ['counter_amount' => '970 000', 'counter_comment' => 'Чуть выше'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($bid->fresh()->hasPendingCounter());
        Notification::assertSentTo($this->roman, CounterOfferedNotice::class);

        // Чужой менеджер ответить не может.
        $this->actingAs($this->denis)->post(self::SITE.'/confirmations/'.$bid->id.'/counter/answer', ['agree' => '1'])->assertSessionHasErrors('counter');

        $this->actingAs($this->roman)->post(self::SITE.'/confirmations/'.$bid->id.'/counter/answer', ['agree' => '1'])->assertSessionHasNoErrors();
        $bid = $bid->fresh();
        $this->assertSame(BidState::Accepted, $bid->state);
        $this->assertSame(CounterState::Accepted, $bid->counter_state);
        $deal = $this->offer->fresh()->deal;
        $this->assertSame(970000, $deal->amount);
        $this->assertTrue($deal->buyer->is($this->roman));
        Notification::assertSentTo($this->admin, CounterAnsweredNotice::class);
    }

    public function test_decline_keeps_own_price(): void
    {
        $bid = app(PlaceBid::class)($this->offer, $this->roman, 965000);
        app(MakeCounter::class)($bid, $this->admin, 970000);

        $this->actingAs($this->roman)->post(self::SITE.'/confirmations/'.$bid->id.'/counter/answer', ['agree' => '0'])->assertSessionHasNoErrors();
        $bid = $bid->fresh();
        $this->assertSame(BidState::Active, $bid->state);
        $this->assertSame(965000, $bid->amount);
        $this->assertTrue($bid->counterDeclined());
        $this->assertNull($this->offer->fresh()->deal);
    }

    public function test_one_live_counter_and_rebid_kills_it(): void
    {
        $roman = app(PlaceBid::class)($this->offer, $this->roman, 965000);
        $denis = app(PlaceBid::class)($this->offer, $this->denis, 962000);
        app(MakeCounter::class)($roman, $this->admin, 975000);
        app(MakeCounter::class)($denis, $this->admin, 970000);
        $this->assertSame(CounterState::Withdrawn, $roman->fresh()->counter_state);
        $this->assertTrue($denis->fresh()->hasPendingCounter());

        // Не выше цены менеджера — нельзя.
        $this->expectException(ValidationException::class);
        try {
            // Денис подтвердил заново — прежнее подтверждение заменено, контр к нему погас.
            app(PlaceBid::class)($this->offer->fresh(), $this->denis, 968000);
            $this->assertFalse($denis->fresh()->hasPendingCounter());
        } finally {
            app(MakeCounter::class)($roman->fresh(), $this->admin, 965000);
        }
    }

    public function test_answer_from_telegram_buttons(): void
    {
        config(['xcar.telegram.token' => 'test-token', 'xcar.mercure.hub' => '']);
        $sent = [];
        $n = 70;
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => function ($r) use (&$sent, &$n) {
            $sent[] = $r->url().' '.$r->body();

            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => ++$n]]);
        }]);
        $this->roman->forceFill(['telegram_chat_id' => 777, 'telegram_linked_at' => now()])->save();
        $bid = app(PlaceBid::class)($this->offer, $this->roman, 965000);
        app(MakeCounter::class)($bid, $this->admin, 970000);
        // Уведомление ушло в бот с кнопками ответа.
        $this->assertTrue(collect($sent)->contains(fn ($s) => str_contains($s, 'sendMessage') && str_contains($s, 'counter%3A'.$bid->id.'%3Ayes') || str_contains($s, 'counter:'.$bid->id.':yes')));

        $press = fn (string $from, string $action) => app(\App\Telegram\UpdateHandler::class)->handle(['update_id' => random_int(1, 9999), 'callback_query' => [
            'id' => 'q', 'from' => ['id' => (int) $from], 'data' => "counter:{$bid->id}:{$action}", 'message' => ['message_id' => 1, 'chat' => ['id' => (int) $from]]]]);
        $press('888', 'sure'); // чужой — мимо
        $this->assertTrue($bid->fresh()->hasPendingCounter());
        $press('777', 'yes'); // переспрашивает, сделки нет
        $this->assertNull($this->offer->fresh()->deal);
        $press('777', 'sure');
        $this->assertSame(970000, $this->offer->fresh()->deal->amount);
    }
}
