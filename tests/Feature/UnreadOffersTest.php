<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Support\Nav;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Не просмотренные» в каталоге (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины»): включён каждый
 * заход, выключают на этот раз, открытое за заход остаётся на месте, вернувшееся в продажу — снова непросмотренное;
 * число на табе «Предложения» — непросмотренные.
 */
class UnreadOffersTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Просмотры и бот предложений пишут в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->manager = User::create(['name' => 'Менеджер Просмотров', 'phone' => '79001112233', 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    private function offer(): Offer
    {
        $this->n++;
        $brand = Brand::firstOrCreate(['slug' => 'haval'], ['name' => 'Haval']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'm'.$this->n, 'name' => 'M'.$this->n]);
        $vendor = Vendor::firstOrCreate(['name' => 'Т-Страхование тест'], ['deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'model_id' => $model->id, 'floor_price' => 500000, 'asking_price' => 600000]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subHour(), 'bids_close_at' => now()->addDays(2)])->save();
        OfferViewer::insert(['offer_id' => $offer->id, 'user_id' => $this->manager->id, 'opens_at' => now()->subHour()]);

        return $offer->fresh();
    }

    /** Номера предложений на странице каталога — по ключам строк и плиток. */
    private function numbers(string $url, array $headers = []): array
    {
        $html = $this->actingAs($this->manager)->get($url, ['Accept' => 'text/html'] + $headers)->assertOk()->getContent();
        preg_match_all('/data-viewed-key="(\d+)"/', $html, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    public function test_default_on_once_off_and_back_on_next_visit(): void
    {
        [$a, $b, $c] = [$this->offer(), $this->offer(), $this->offer()];
        DB::table('offer_reads')->insert(['user_id' => $this->manager->id, 'offer_id' => $a->id, 'read_at' => now()->subMinutes(40)]);

        $shown = $this->numbers('/offers');
        $this->assertNotContains($a->number, $shown);
        $this->assertContains($b->number, $shown);

        // Выключили — все; следующий заход (без параметра) — снова только непросмотренные: не запоминается.
        $this->assertContains($a->number, $this->numbers('/offers?unread=0'));
        $this->assertNotContains($a->number, $this->numbers('/offers', ['Referer' => 'http://xcar.localhost/deals']));
        $this->assertNull(data_get($this->manager->fresh()->list_prefs, 'catalog.unread'));
    }

    public function test_read_during_visit_stays_until_next_visit(): void
    {
        [$a, $b] = [$this->offer(), $this->offer()];
        $this->numbers('/offers');
        // Тот же заход — та же сессия (клиент теста cookie сессии не носит).
        $this->withSession(['catalog.since' => session('catalog.since'), 'catalog.seen' => session('catalog.seen')]);
        $this->travel(1)->seconds();
        // Открыл предложение из каталога — в этом заходе оно на месте.
        $this->actingAs($this->manager)->get("/offers/{$a->number}?list=catalog&unread=1", ['Accept' => 'text/html', 'Referer' => 'http://xcar.localhost/offers'])->assertOk();
        $this->assertTrue(DB::table('offer_reads')->where('offer_id', $a->id)->exists());
        // Страница выдала черновому номеру настоящий (`OfferNumber`) — дальше сверяем по нему.
        $a = $a->fresh();
        $this->assertContains($a->number, $this->numbers('/offers', ['Referer' => 'http://xcar.localhost/offers/'.$a->number]));

        // Пришёл из другого раздела — новый заход, просмотренного нет.
        $this->travel(1)->seconds();
        $this->assertNotContains($a->number, $this->numbers('/offers', ['Referer' => 'http://xcar.localhost/deals']));
        $this->assertContains($b->number, $this->numbers('/offers', ['Referer' => 'http://xcar.localhost/deals']));
    }

    public function test_back_on_sale_is_unread_again_and_tab_badge_counts_unread(): void
    {
        [$a, $b] = [$this->offer(), $this->offer()];
        DB::table('offer_reads')->insert(['user_id' => $this->manager->id, 'offer_id' => $a->id, 'read_at' => now()->subMinutes(30)]);
        $this->assertSame(1, Nav::badges($this->manager, Surface::Site)['/offers'] ?? 0);

        // Вышло снова — опубликовано позже просмотра.
        $a->forceFill(['published_at' => now()])->save();
        Nav::forget($this->manager);
        $this->assertSame(2, Nav::badges($this->manager->fresh(), Surface::Site)['/offers'] ?? 0);
    }
}
