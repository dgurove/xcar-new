<?php

namespace Tests\Feature;

use App\Billing\Invoice;
use App\Cars\Brand;
use App\Logistics\Client;
use App\Logistics\Transport;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Заявки логиста от своих заказчиков, страница исполнителя по ссылке, люди без ссылки (18.10.2026). Утечки молчаливые и
 * дорогие: заказчики Татьяны — её бизнес, админ XCar их видеть не должен ни списком, ни поиском, ни карточкой; XCar за них
 * не платит; погашенная ссылка не должна действовать.
 */
class LogisticsOrdersTest extends TestCase
{
    use RefreshDatabase;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'http://'.config('xcar.logistics_host');
        Notification::fake();
    }

    public function test_logist_order_is_hers_only_and_trip_link_walks_the_steps(): void
    {
        $admin = User::create(['name' => 'Ольга Админ', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $tanya = User::create(['name' => 'Татьяна Логист', 'phone' => '79000000004', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $driver = User::create(['name' => 'Пётр Водитель', 'phone' => '79000000005', 'roles' => [Role::Contractor], 'logist_id' => $tanya->id, 'approved_at' => now()]);
        $client = Client::create(['logist_id' => $tanya->id, 'name' => 'АвтоТранс Самара']);
        $brand = Brand::create(['name' => 'Kia', 'slug' => 'kia']);

        $this->actingAs($tanya)->post($this->host.'/transports', [
            'client_id' => $client->id, 'brand_id' => $brand->id, 'plate' => 'А123ВС163',
            'from_address' => 'Самара, Садовая 5', 'from_phone' => '+7 927 000-11-22', 'to_address' => 'Тольятти, Южное ш. 30',
            'client_price' => '25 000', 'executor_id' => $driver->id, 'executor_cost' => '18000',
        ])->assertRedirect();
        $t = Transport::where('origin', 'order')->sole();
        $this->assertSame($tanya->id, $t->logist_id);
        $this->assertSame(25000, (int) $t->client_price);
        $this->assertNotNull($t->trip_code);
        $this->assertSame(0, Invoice::count(), 'XCar за заявку логиста не должен');

        // Ни админу, ни модератору: список, поиск, карточка, действие.
        foreach ([$admin, $moderator] as $lead) {
            $this->assertFalse(Transport::visibleTo($lead)->whereKey($t->id)->exists());
            $this->actingAs($lead)->get($this->host.'/transports?preset=all&q=Kia')->assertOk()->assertDontSee('А123ВС163');
            $this->actingAs($lead)->get($this->host.'/transports?peek='.$t->id, ['Turbo-Frame' => 'detail'])->assertDontSee('АвтоТранс');
            $this->actingAs($lead)->post($this->host.'/transports/'.$t->id.'/cancel')->assertNotFound();
        }
        $this->assertTrue(Transport::visibleTo($tanya)->whereKey($t->id)->exists());
        $this->assertTrue(Transport::visibleTo($driver)->whereKey($t->id)->exists());

        // Страница исполнителя без входа: «Созвонились» → «Забрал» (фото обязательно — без него отказ) → «Привёз».
        Auth::logout();
        $trip = $this->host.'/trip/'.$t->trip_code;
        $this->get($trip)->assertOk()->assertSee('Kia')->assertDontSee('25 000');
        $this->post($trip.'/call', ['pickup_at' => now()->addDay()->format('Y-m-d\TH:i'), 'pickup_place' => 'Самара, Садовая 5'])->assertRedirect();
        $this->post($trip.'/pickup', ['keys' => 1, 'destination' => 'other', 'to_address' => $t->to_address])->assertSessionHasErrors('photos');
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $t->car->addMediaFromString((string) ob_get_clean())->usingFileName('a.jpg')->withCustomProperties(['stage' => 'pickup', 'transport' => $t->id, 'at' => 'pickup', 'hidden' => true])->toMediaCollection('photos');
        $this->post($trip.'/pickup', ['keys' => 1, 'destination' => 'other', 'to_address' => $t->to_address])->assertSessionHasNoErrors();
        $this->post($trip.'/deliver')->assertRedirect();
        $this->assertSame('delivered', $t->fresh()->state->value);
        $this->post($trip.'/deliver')->assertStatus(410);

        // Сменили исполнителя — прежняя ссылка гаснет.
        $other = Transport::create(['car_id' => $t->car_id, 'origin' => 'order', 'kind' => 'pickup', 'channel' => 'logist', 'logist_id' => $tanya->id, 'executor_id' => $driver->id, 'state' => 'assigned']);
        $old = $other->trip_code;
        $other->update(['executor_id' => $tanya->id]);
        $this->get($this->host.'/trip/'.$old)->assertStatus(410);
        $this->assertNotSame($old, $other->fresh()->trip_code);
    }

    public function test_manager_adds_buyer_by_data_and_buyer_logs_in(): void
    {
        $manager = User::create(['name' => 'Иван Петров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->actingAs($manager)->post('/buyers/invites', [
            'mode' => 'person', 'first_name' => 'Анна', 'last_name' => 'Смирнова', 'person_phone' => '8 916 123-45-67',
        ])->assertRedirect()->assertSessionHas('credentials');
        $buyer = User::where('phone', '79161234567')->sole();
        $this->assertTrue($buyer->isBuyer());
        $this->assertSame($manager->id, $buyer->manager_id);
        $this->assertTrue(Auth::validate(['phone' => '79161234567', 'password' => session('credentials')['password']]));

        // Тот же телефон второй раз — ошибка у поля, второго человека нет.
        $this->actingAs($manager)->post('/buyers/invites', ['mode' => 'person', 'first_name' => 'Анна', 'person_phone' => '+79161234567'])
            ->assertSessionHasErrors('person_phone');
        $this->assertSame(1, User::where('phone', '79161234567')->count());
    }
}
