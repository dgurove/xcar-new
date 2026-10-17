<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\CarModel;
use App\Logistics\Transport;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Request as ParkRequest;
use App\Park\RequestType;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Purchases\Car as PurchaseCar;
use App\Purchases\Purchase;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;
use Throwable;

/**
 * Дымовой проход (16.10.2026): каждый GET-экран четырёх хостов под каждой ролью и гостем — не 500. Параметры маршрутов —
 * последняя запись своей модели; где записи нет или параметр не модель — маршрут пропускается. Ленивая загрузка в
 * цикле (N+1) бросает исключение, поэтому записей в списках по две и больше: на одной Laravel её не ловит.
 * Файлы, выгрузки, PDF и служебные адреса (вход по ссылке, пароли, `/media`) не ходятся — у них свои тесты или их нет.
 */
class SmokeRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const SKIP = '~(pdf|photo/|/slip|/file|attachments|export|xlsx|/print|/image|dev/login|^admin|fallback|^media/|^hot/|^storage/'
        .'|login/as|password/|telegram/open|^i/|unsubscribe|/typing|/live$|marked|/mark$|registry|/body$|^up$|/thumb|/scan/live|^P/|^p/|^pay/|^pickup/)~';

    /** Параметры, которые не модель: шаг окна действия, вид акта, номер сообщения. */
    private const FIXED = ['kind' => 'intake', 'step' => 'intake', 'seq' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Notification::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('media');
        Storage::fake('private');
        // Отметки просмотра и сессии пишутся в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    public function test_every_get_screen_answers_without_500(): void
    {
        $users = $this->world();
        Model::preventLazyLoading();

        $failures = [];
        $site = parse_url(config('app.url'), PHP_URL_HOST);
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && ! preg_match(self::SKIP, $r->uri()));
        $this->assertGreaterThan(100, $routes->count(), 'маршруты на месте');

        $count = 0;
        foreach ([null, ...$users] as $user) {
            foreach ($routes as $route) {
                $path = $this->path($route);
                if ($path === null) {
                    continue;
                }
                $url = 'http://'.($route->getDomain() ?? $site).'/'.ltrim($path, '/');
                $who = $user ? $user->rolesLabel() : 'гость';
                $this->app['auth']->forgetGuards();
                if ($user) {
                    $this->actingAs($user);
                }
                // Каждый запрос — в своей точке сохранения: упавший SQL не обрывает транзакцию теста для следующих.
                DB::beginTransaction();
                try {
                    $response = $this->get($url);
                    $count++;
                    if ($response->getStatusCode() >= 500) {
                        $e = $response->exception;
                        $failures[] = "{$who} GET {$url}: ".($e ? $e::class.': '.mb_substr($e->getMessage(), 0, 300).' @ '.$e->getFile().':'.$e->getLine() : $response->getStatusCode());
                    }
                } catch (Throwable $e) {
                    $failures[] = "{$who} GET {$url}: ".$e::class.': '.mb_substr($e->getMessage(), 0, 300);
                } finally {
                    DB::rollBack();
                }
            }
        }

        $this->assertGreaterThan(500, $count, 'экраны действительно пройдены');
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /** Путь маршрута с подставленными параметрами; null — подставить нечего. */
    private function path(Route $route): ?string
    {
        $path = $route->uri();
        $lot = null;
        foreach ($route->parameterNames() as $name) {
            $value = match (true) {
                isset(self::FIXED[$name]) => self::FIXED[$name],
                // Машина закупки — в своей закупке (`purchases/{purchase}/{car}`).
                $name === 'car' && str_contains($path, 'purchases/') => ($lot ??= PurchaseCar::latest('id')->first())?->getRouteKey(),
                $name === 'purchase' && str_contains($route->uri(), '{car}') => ($lot ??= PurchaseCar::latest('id')->first())?->purchase?->getRouteKey(),
                default => $this->modelKey($route, $name),
            };
            if ($value === null) {
                return null;
            }
            $path = preg_replace('~\{'.$name.'\??\}~', (string) $value, $path);
        }

        return $path;
    }

    /** Ключ последней записи модели, которую ждёт метод контроллера под этим именем. */
    private function modelKey(Route $route, string $name): string|int|null
    {
        $action = $route->getAction('uses');
        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }
        foreach ((new ReflectionMethod(...explode('@', $action)))->getParameters() as $p) {
            $type = $p->getType();
            if ($p->getName() === $name && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $class = $type->getName();

                return $class::query()->withoutGlobalScopes()->latest((new $class)->getKeyName())->first()?->getRouteKey();
            }
        }

        return null;
    }

    /** Данные: по две записи там, где экран — список, иначе N+1 не ловится. @return list<User> */
    private function world(): array
    {
        $user = fn (string $name, array $roles, array $extra = []) => User::create(['name' => $name, 'phone' => '7900'.random_int(1000000, 9999999),
            'roles' => $roles, 'approved_at' => now()] + $extra);
        $admin = $user('Андрей Кузнецов', [Role::Admin, Role::ParkAdmin]);
        $manager = $user('Иван Петров', [Role::Manager]);
        $other = $user('Олег Сидоров', [Role::Manager]);
        $users = [$admin, $manager,
            $user('Мария Модератор', [Role::Moderator]),
            $user('Пётр Покупатель', [Role::Buyer], ['manager_id' => $manager->id]),
            $user('Света Супер', [Role::SuperBuyer]),
            $user('Катя Парковка', [Role::Parking]),
            $logist = $user('Татьяна Логист', [Role::Logist]),
            $driver = $user('Семён Водитель', [Role::Driver]),
            $user('Пётр Исполнитель', [Role::Contractor], ['logist_id' => $logist->id, 'cdek_point' => 'Самара, Ленина 1']),
            $user('Роза Проверяющая', [Role::Reviewer]),
        ];

        $brand = Brand::create(['slug' => 'kia', 'name' => 'Kia']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'rio', 'name' => 'Rio']);
        // Вендоры заводят и миграции — берём их же.
        $vendors = [Vendor::findOrCreateByName('Т-Страхование'), Vendor::findOrCreateByName('Тест-вендор')];
        foreach ($vendors as $v) {
            $v->update(['on_park' => true, 'is_active' => true]);
        }
        $yard = Yard::create(['name' => 'Краснопутиловская', 'is_active' => true]);

        $offers = [];
        foreach ([0, 1, 2, 3] as $i) {
            $offer = Offer::create(['vendor_id' => $vendors[$i % 2]->id, 'brand_id' => $brand->id, 'model_id' => $model->id, 'claim_ref' => 'AUT-26-90000'.$i,
                'year' => 2020, 'floor_price' => 500000 + $i * 1000, 'asking_price' => 600000 + $i * 1000]);
            Media::create(['model_type' => Car::class, 'model_id' => $offer->car_id, 'collection_name' => 'photos', 'name' => 'кадр', 'file_name' => "k{$i}.jpg",
                'mime_type' => 'image/jpeg', 'disk' => 'media', 'size' => 10, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => []]);
            $offers[] = $offer;
        }
        // Два в продаже с подтверждениями, одно в сделке, одно черновиком.
        foreach ([0, 1, 2] as $i) {
            $offers[$i]->forceFill(['state' => OfferState::Open, 'published_at' => now()->subHour(), 'bids_close_at' => now()->addDay(), 'number' => 2610160000 + $i])->save();
            foreach ([$manager, $other] as $n => $m) {
                Bid::create(['offer_id' => $offers[$i]->id, 'user_id' => $m->id, 'amount' => 610000 + $n * 5000, 'state' => BidState::Active]);
            }
        }
        $offers[2]->forceFill(['state' => OfferState::Sold])->save();
        Deal::create(['offer_id' => $offers[2]->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 502000, 'amount' => 615000]);
        // Вывозы: без исполнителя, у логиста, у нашего человека с созвоном и документами СДЭКом.
        Transport::create(['car_id' => $offers[0]->car_id, 'origin' => 'sale', 'kind' => 'pickup', 'state' => 'new']);
        Transport::create(['car_id' => $offers[1]->car_id, 'origin' => 'sale', 'kind' => 'pickup', 'state' => 'logist', 'executor' => 'logist', 'coordinator_id' => $logist->id]);
        Transport::create(['car_id' => $offers[2]->car_id, 'origin' => 'sale', 'kind' => 'pickup', 'state' => 'agreed', 'executor' => 'staff', 'executor_id' => $driver->id,
            'agreed_at' => now(), 'pickup_at' => now()->addDay(), 'pickup_place' => 'Москва', 'docs_by_cdek' => true, 'docs_track' => '123456789']);

        foreach ([0, 1] as $i) {
            $v = Vehicle::create(['vendor_id' => $vendors[$i]->id, 'yard_id' => $yard->id, 'brand_id' => $brand->id, 'model_id' => $model->id,
                'ref' => 'AUT-26-80000'.$i, 'state' => VehicleState::Stored, 'accepted_at' => now()->subDays(3)]);
            ParkRequest::create(['vehicle_id' => $v->id, 'type' => RequestType::Release]);
            Transport::create(['car_id' => $v->car_id, 'origin' => 'park', 'kind' => 'move', 'to_yard_id' => $yard->id, 'destination' => 'yard']);
        }
        foreach ([0, 1] as $i) {
            $v = Vehicle::create(['vendor_id' => $vendors[$i]->id, 'brand_id' => $brand->id, 'ref' => 'AUT-26-70000'.$i, 'state' => VehicleState::Expected]);
            ParkRequest::create(['vehicle_id' => $v->id, 'type' => RequestType::Intake]);
        }

        $purchase = Purchase::create(['number' => 7, 'vendor_id' => $vendors[0]->id, 'title' => 'Закупка']);
        foreach ([0, 1] as $i) {
            $purchase->cars()->create(['dl' => "ДЛ 1234{$i}/2023", 'ref' => $i + 1, 'year' => 2021]);
        }

        return $users;
    }
}
