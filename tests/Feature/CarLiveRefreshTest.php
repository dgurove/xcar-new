<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Live\Jobs\PublishRefresh;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\UpdateVehicle;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Машина одна у всех дорожек: правка на парковке перечитывает открытую CRM, правка в CRM — парковку (`Live\RefreshCar`).
 * Ошибка здесь молчит — экраны просто показывают старое до ручного обновления.
 */
class CarLiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Offer $offer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $kia = Brand::firstOrCreate(['slug' => 'kia'], ['name' => 'Kia']);
        $this->offer = Offer::create(['claim_ref' => 'AUT-26-200001', 'floor_price' => 500000])->fresh();
        $this->vehicle = Vehicle::create(['ref' => 'AUT-26-200001', 'brand_id' => $kia->id, 'year' => 2020, 'state' => VehicleState::Stored]);
        // Заведение уже публиковало — окно сглаживания с чистого листа, хаб «есть».
        Cache::flush();
        config(['xcar.mercure.hub' => 'http://hub.test/.well-known/mercure', 'xcar.mercure.publisher_key' => str_repeat('k', 32)]);
        Http::fake(['hub.test/*' => Http::response('id')]);
    }

    /** @return list<array{topics: list<string>, type: string, data: array}> */
    private function published(): array
    {
        return collect(Http::recorded())->map(function (array $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $parts = [];
            foreach (explode('&', $request->body()) as $piece) {
                [$k, $v] = array_map('urldecode', explode('=', $piece, 2));
                $parts[$k][] = $v;
            }

            return ['topics' => $parts['topic'] ?? [], 'type' => $parts['type'][0] ?? '', 'data' => json_decode($parts['data'][0] ?? '[]', true)];
        })->all();
    }

    private function refreshes(string $topic): array
    {
        return collect($this->published())->filter(fn ($p) => $p['type'] === 'refresh' && in_array($topic, $p['topics'], true))
            ->flatMap(fn ($p) => $p['data']['paths'])->all();
    }

    public function test_park_edit_refreshes_crm(): void
    {
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);

        $staff = $this->refreshes('staff');
        $this->assertContains('/offers/'.$this->offer->number, $staff, 'редактор предложения той же машины');
        $this->assertContains('/', $staff, 'список предложений CRM');
        $this->assertContains('/cars/'.$this->vehicle->id, $this->refreshes('park'), 'и само дело ТС у других на парковке');
    }

    public function test_crm_edit_refreshes_park(): void
    {
        app(UpdateOffer::class)($this->offer->fresh(), ['year' => 2021], $this->admin);

        $this->assertContains('/cars/'.$this->vehicle->id, $this->refreshes('park'));
        $this->assertContains('/cars', $this->refreshes('park'), '«Наличие»');
    }

    public function test_batch_of_edits_refreshes_lists_once_and_schedules_one_tail(): void
    {
        $update = app(UpdateVehicle::class);
        $update($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);
        $update($this->vehicle->fresh(), ['color' => 'Красный'], $this->admin);
        $update($this->vehicle->fresh(), ['color' => 'Белый'], $this->admin);

        $this->assertSame(1, collect($this->refreshes('staff'))->filter(fn ($p) => $p === '/')->count(), 'список — один раз сразу');
        Queue::assertPushed(PublishRefresh::class, fn (PublishRefresh $job) => $job->paths === ['/', '/gallery', '/work/deals', '/work/garage']);
        $this->assertSame(1, collect(Queue::pushed(PublishRefresh::class))->filter(fn ($job) => $job->paths === ['/', '/gallery', '/work/deals', '/work/garage'])->count(), 'остальное — одним хвостом');
    }

    public function test_rolled_back_edit_publishes_nothing(): void
    {
        DB::beginTransaction();
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);
        DB::rollBack();

        $this->assertSame([], $this->published());
    }

    public function test_open_offer_redraws_site_card_only_when_on_screen(): void
    {
        $this->offer->forceFill(['state' => OfferState::Open])->saveQuietly();
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);

        $card = collect($this->published())->firstWhere('type', 'card');
        $this->assertNotNull($card);
        $this->assertContains('catalog', $card['topics']);
        $this->assertSame(['number' => $this->offer->number, 'only' => true], $card['data'], 'новой плиткой не въезжает');
    }

    public function test_author_request_id_rides_with_refresh(): void
    {
        $this->app['request']->headers->set('X-Turbo-Request-Id', 'abc-123');
        app(UpdateVehicle::class)($this->vehicle->fresh(), ['color' => 'Синий'], $this->admin);

        $refresh = collect($this->published())->firstWhere('type', 'refresh');
        $this->assertSame('abc-123', $refresh['data']['request']);
    }
}
