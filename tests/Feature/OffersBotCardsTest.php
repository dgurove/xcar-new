<?php

namespace Tests\Feature;

use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Telegram\Offers\Cards;
use App\Telegram\Offers\Handler;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Бот предложений шлёт каждое новое предложение карточкой (03.11.2026). Это доступ: прислать машину раньше волны
 * показа или чужого региона — показать то, что человеку ещё не открыто, а лишняя карточка — PDF с ценой, который уйдёт
 * клиенту. Ошибка молчалива: никто не заметит, что машина пришла не тому или не тогда.
 */
class OffersBotCardsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 5501;

    private User $manager;

    private Offer $offer;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '', 'xcar.telegram.offers.token' => '1:test', 'xcar.telegram.offers.lanes' => 1]);
        // Бот пишет в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $n = 100;
        Http::fake(['api.telegram.org/*' => function ($r) use (&$n) {
            $this->sent[] = $r->url().' '.urldecode($r->body());

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$n]]);
        }]);
        $this->manager = User::create(['name' => 'Роман', 'phone' => '79000000501', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->offer = $this->openOffer();
        $this->subscribe($this->manager, self::CHAT, now()->subHour());
    }

    private function openOffer(): Offer
    {
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'Альфа тест'])->id, 'floor_price' => 900000,
            'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subMinutes(10)])->save();

        return $offer->fresh();
    }

    private function subscribe(User $user, int $chat, $since): void
    {
        DB::table('offer_bot_chats')->insert(['user_id' => $user->id, 'chat_id' => $chat, 'cards_since' => $since, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Его волна: открыто ему с `opens`, анонс — с `notified` (null — волна ещё не наступила). */
    private function wave(Offer $offer, User $user, $opens, $notified): void
    {
        $offer->viewers()->updateOrCreate(['user_id' => $user->id], ['opens_at' => $opens, 'notified_at' => $notified]);
    }

    /** @return list<int> предложения, которые пора прислать этому чату */
    private function due(int $chat = self::CHAT): array
    {
        return collect(Cards::due())->where('chat_id', $chat)->pluck('offer_id')->all();
    }

    private function deliver(): void
    {
        foreach (Cards::due() as $item) {
            app(Handler::class)->handle($item);
        }
    }

    public function test_card_comes_when_his_wave_comes_and_only_once(): void
    {
        // Волна в будущем — ни в очереди, ни на сайте.
        $this->wave($this->offer, $this->manager, now()->addHours(2), null);
        $this->assertSame([], $this->due());

        // Наступила — одна карточка: текст для клиента с ценой и кнопка, без вопросов.
        $this->wave($this->offer, $this->manager, now()->subMinute(), now()->subMinute());
        $this->assertSame([$this->offer->id], $this->due());
        $this->deliver();
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('chat_id='.self::CHAT, $this->sent[0]);
        $this->assertStringContainsString('1 000 000', $this->sent[0]);
        $this->assertStringContainsString('Открыть в XCar', $this->sent[0]);
        $this->assertNotNull(DB::table('offer_bot_sent')->where('user_id', $this->manager->id)->value('message_id'));

        // Второй обход часов и повтор той же записи дорожкой — ничего нового.
        $this->assertSame([], $this->due());
        app(Handler::class)->handle(['chat_id' => self::CHAT, 'offer_id' => $this->offer->id]);
        $this->assertCount(1, $this->sent);
    }

    public function test_foreign_region_read_bid_and_before_subscription_do_not_come(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinutes(5), now()->subMinutes(5));

        // Регион — фильтр человека: из чужого бот не шлёт.
        [$region, $other] = DB::table('regions')->orderBy('id')->limit(2)->pluck('id')->all();
        $this->offer->forceFill(['region_id' => $other])->saveQuietly();
        $this->manager->forceFill(['offer_regions' => [$region]])->save();
        $this->assertSame([], $this->due());
        $this->manager->forceFill(['offer_regions' => null])->save();
        $this->assertSame([$this->offer->id], $this->due());

        // Открыл машину на сайте после того, как она ему открылась, — карточка не нужна.
        DB::table('offer_reads')->insert(['user_id' => $this->manager->id, 'offer_id' => $this->offer->id, 'read_at' => now()]);
        $this->assertSame([], $this->due());
        DB::table('offer_reads')->delete();

        // Подписался позже, чем машина ему открылась, — старое не приходит.
        DB::table('offer_bot_chats')->where('user_id', $this->manager->id)->update(['cards_since' => now()]);
        $this->assertSame([], $this->due());
    }

    public function test_admin_gets_every_offer_at_publication(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000502', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->subscribe($admin, 5502, now()->subHour());
        // Волн у админа нет: открыто при публикации.
        $this->assertSame([$this->offer->id], $this->due(5502));

        // Снятое с продажи — нет.
        $this->offer->forceFill(['state' => OfferState::Draft])->save();
        $this->assertSame([], $this->due(5502));
    }

    public function test_card_is_retracted_when_offer_leaves_sale(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinute(), now()->subMinute());
        $this->deliver();
        $message = (int) DB::table('offer_bot_sent')->where('user_id', $this->manager->id)->value('message_id');

        $this->offer->forceFill(['state' => OfferState::Draft])->save();
        app()->call([new RetractOfferNotices($this->offer->id), 'handle']);
        $this->assertTrue(collect($this->sent)->contains(fn ($s) => str_contains($s, 'deleteMessage') && str_contains($s, 'message_id='.$message)));
        // Строка ушла: вернётся машина в продажу — карточка придёт снова.
        $this->assertFalse(DB::table('offer_bot_sent')->where('user_id', $this->manager->id)->exists());
    }
}
