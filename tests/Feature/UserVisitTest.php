<?php

namespace Tests\Feature;

use App\Cars\Car;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Users\Role;
use App\Users\User;
use App\Users\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Активность человека (30.10.2026): заход продлевает одну строку, после паузы — новая; экран
 * «Активность» — только админу. Ошибка здесь молчалива: журнал перестанет писаться — экран покажет «не заходил».
 */
class UserVisitTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private const SITE = 'http://xcar.localhost';

    public function test_visits_are_logged_and_shown_to_admin_only(): void
    {
        // Заход пишет в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $admin = User::create(['name' => 'Админ Тестов', 'phone' => '79000000021', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер Тестов', 'phone' => '79000000022', 'roles' => [Role::Manager], 'approved_at' => now()]);

        $this->actingAs($manager)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) Safari')->get(self::SITE.'/deals')->assertOk();
        $this->travel(5)->minutes();
        $manager->refresh();
        $this->actingAs($manager)->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) Safari')->get(self::SITE.'/deals')->assertOk();
        $visit = Visit::where('user_id', $manager->id)->sole();
        $this->assertSame(2, $visit->hits);
        $this->assertSame('Safari, iPhone', $visit->device());

        // Через час — новый заход.
        $this->travel(1)->hours();
        $manager->refresh();
        $this->actingAs($manager)->get(self::SITE.'/deals')->assertOk();
        $this->assertSame(2, Visit::where('user_id', $manager->id)->count());

        $car = Car::create(['vin' => 'EDEDB21B4RD000101']);
        OfferEvent::create(['car_id' => $car->id, 'user_id' => $manager->id, 'type' => OfferEventType::Created, 'payload' => ['migtorg' => true]]);

        $this->actingAs($admin)->get(self::CRM.'/settings/users/'.$manager->id.'?pill=activity')->assertOk()
            ->assertSee('В сети')->assertSee('Safari, iPhone')->assertSee('заведён с Мигторга');
        // Не админу CRM закрыта целиком (стена `auth.foreign`), на сайте экран человека — тоже не его.
        $this->actingAs($manager)->get(self::CRM.'/settings/users/'.$admin->id.'?pill=activity')->assertForbidden();
        $this->actingAs($manager)->get(self::SITE.'/account/users/'.$admin->id.'?pill=activity')->assertNotFound();
    }
}
