<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Live\BannerChannel;
use App\Notifications\NewOffersNotice;
use App\Offers\Actions\NotifyNewOffers;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Telegram\Offers\Announce;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Новые предложения пачкой после 5 минут тишины (16.10.2026): у каждого свой счётчик по `notified_at`, срочное не
 * считается, поток не держит дольше 30 минут, в ленту не идёт.
 */
class NewOffersDigestTest extends TestCase
{
    use RefreshDatabase;

    private User $a;

    private User $b;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->a = $this->manager('Первый Менеджер');
        $this->b = $this->manager('Второй Менеджер');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function manager(string $name): User
    {
        return User::create(['name' => $name, 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    /** Открытое предложение: первому видно сейчас, второму — через `$later` минут. */
    private function offer(int $later = 0, bool $urgent = false): Offer
    {
        $this->n++;
        $brand = Brand::firstOrCreate(['slug' => 'haval'], ['name' => 'Haval']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'f'.$this->n, 'name' => 'F'.$this->n]);
        $vendor = Vendor::firstOrCreate(['name' => 'Т-Страхование тест'], ['deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'model_id' => $model->id,
            'floor_price' => 500000, 'asking_price' => 600000]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now(), 'bids_close_at' => now()->addDays(3),
            'urgent_at' => $urgent ? now() : null])->save();
        OfferViewer::insert([
            ['offer_id' => $offer->id, 'user_id' => $this->a->id, 'opens_at' => now()],
            ['offer_id' => $offer->id, 'user_id' => $this->b->id, 'opens_at' => now()->addMinutes($later)],
        ]);

        return $offer;
    }

    /** Минута часов `offers:tick`: волны, потом пачка. */
    private function tickAt(string $time): void
    {
        Carbon::setTestNow($time);
        app(NotifyViewers::class)->due();
        app(NotifyNewOffers::class)();
    }

    public function test_one_offer_after_five_quiet_minutes(): void
    {
        Notification::fake();
        $offer = $this->offer(later: 24 * 60);
        foreach (['10:00', '10:01', '10:02', '10:03', '10:04'] as $t) {
            $this->tickAt("2026-10-16 {$t}:00");
        }
        Notification::assertNotSentTo($this->a, NewOffersNotice::class);

        $this->tickAt('2026-10-16 10:05:00');
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
        Notification::assertSentTo($this->a, NewOffersNotice::class, fn (NewOffersNotice $n) => $n->title() === 'Новое предложение: '.$offer->titleWithYear()
            && $n->href() === "/offers/{$offer->fresh()->number}");
        Notification::assertNotSentTo($this->b, NewOffersNotice::class);

        $this->tickAt('2026-10-16 10:06:00');
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
    }

    public function test_second_offer_restarts_pause_and_each_manager_has_own_clock(): void
    {
        Notification::fake();
        $this->offer(later: 24 * 60);
        $this->tickAt('2026-10-16 10:00:00');
        foreach (['10:01', '10:02', '10:03'] as $t) {
            $this->tickAt("2026-10-16 {$t}:00");
        }
        Carbon::setTestNow('2026-10-16 10:04:00');
        $this->offer(later: 24 * 60);
        foreach (['10:04', '10:05', '10:06', '10:07', '10:08'] as $t) {
            $this->tickAt("2026-10-16 {$t}:00");
        }
        Notification::assertNotSentTo($this->a, NewOffersNotice::class);

        $this->tickAt('2026-10-16 10:09:00');
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
        Notification::assertSentTo($this->a, NewOffersNotice::class, fn (NewOffersNotice $n) => $n->title() === '2 новых предложения'
            && $n->href() === '/offers?view=fresh' && $n->text() === 'Haval F2, Haval F1');

        // Второму волна — через сутки: своё время, пачка та же.
        Notification::assertNotSentTo($this->b, NewOffersNotice::class);
        $this->tickAt('2026-10-17 10:00:00');
        $this->tickAt('2026-10-17 10:04:00');
        $this->tickAt('2026-10-17 10:04:59');
        Notification::assertNotSentTo($this->b, NewOffersNotice::class);
        $this->tickAt('2026-10-17 10:09:00');
        Notification::assertSentToTimes($this->b, NewOffersNotice::class, 1);
        Notification::assertSentTo($this->b, NewOffersNotice::class, fn (NewOffersNotice $n) => $n->title() === '2 новых предложения');
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
    }

    public function test_urgent_is_not_counted_and_does_not_move_the_clock(): void
    {
        Notification::fake();
        $this->offer();
        $this->tickAt('2026-10-16 10:00:00');
        Carbon::setTestNow('2026-10-16 10:03:00');
        $this->offer(urgent: true);
        $this->tickAt('2026-10-16 10:03:00');
        $this->tickAt('2026-10-16 10:04:00');
        Notification::assertNotSentTo($this->a, NewOffersNotice::class);

        $this->tickAt('2026-10-16 10:05:00');
        Notification::assertSentTo($this->a, NewOffersNotice::class, fn (NewOffersNotice $n) => str_starts_with($n->title(), 'Новое предложение: '));

        // Одно срочное — пачки нет вовсе.
        $this->tickAt('2026-10-16 10:20:00');
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
    }

    public function test_stream_every_four_minutes_fires_at_thirty(): void
    {
        Notification::fake();
        for ($m = 0; $m <= 30; $m++) {
            Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00')->addMinutes($m));
            if ($m % 4 === 0) {
                $this->offer(later: 24 * 60);
            }
            $this->tickAt(now()->toDateTimeString());
            if ($m < 30) {
                Notification::assertNotSentTo($this->a, NewOffersNotice::class);
            }
        }
        Notification::assertSentToTimes($this->a, NewOffersNotice::class, 1);
        Notification::assertSentTo($this->a, NewOffersNotice::class, fn (NewOffersNotice $n) => $n->title() === '8 новых предложений'
            && $n->text() === 'Haval F8, Haval F7 и ещё 6');
    }

    public function test_not_in_the_feed(): void
    {
        $channels = [];
        Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$channels) {
            if ($e->notification instanceof NewOffersNotice) {
                $channels[] = $e->channel;
            }
        });
        $this->offer(later: 24 * 60);
        $this->tickAt('2026-10-16 10:00:00');
        $this->tickAt('2026-10-16 10:05:00');

        $this->assertContains(BannerChannel::class, $channels);
        $this->assertNotContains('database', $channels);
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame('2026-10-16 10:00:00', (string) DB::table('users')->where('id', $this->a->id)->value('offers_digest_at'));
    }

    public function test_offers_bot_waits_for_the_same_pause(): void
    {
        Notification::fake();
        DB::table('offer_bot_chats')->insert(['user_id' => $this->a->id, 'chat_id' => 501, 'announced_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        $announces = fn () => array_values(array_filter(Announce::due(), fn (array $i) => $i['timer'] === 'announce'));
        $this->offer(later: 24 * 60);
        $this->tickAt('2026-10-16 10:00:00');
        $this->tickAt('2026-10-16 10:04:00');
        $this->assertSame([], $announces());

        $this->tickAt('2026-10-16 10:05:00');
        $this->assertSame([['timer' => 'announce', 'chat_id' => 501]], $announces());

        // Срочное — сразу.
        DB::table('offer_bot_chats')->update(['announced_at' => now()]);
        Carbon::setTestNow('2026-10-16 10:06:00');
        $this->offer(urgent: true);
        $this->tickAt('2026-10-16 10:06:00');
        $this->assertSame([['timer' => 'announce', 'chat_id' => 501]], $announces());
    }
}
