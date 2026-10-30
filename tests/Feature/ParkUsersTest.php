<?php

namespace Tests\Feature;

use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Пользователи» парковки — тот же экран, что в CRM (31.10.2026): админ парковки видит и правит только людей парковки,
 * роль на парковке меняется, роли CRM остаются; чужой человек — 404, управляющему экран закрыт. Ошибка здесь — утечка
 * людей CRM на парковку или потеря ролей при правке.
 */
class ParkUsersTest extends TestCase
{
    use RefreshDatabase;

    private const PARK = 'http://park.xcar.localhost';

    public function test_park_admin_manages_only_park_people(): void
    {
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $admin = User::create(['name' => 'Админ Парковки', 'first_name' => 'Админ', 'last_name' => 'Парковки', 'phone' => '79000000031', 'roles' => [Role::ParkAdmin], 'approved_at' => now()]);
        $keeper = User::create(['name' => 'Сергей Управляющий', 'first_name' => 'Сергей', 'last_name' => 'Управляющий', 'phone' => '79000000032', 'roles' => [Role::Buyer, Role::Parking], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер Чужой', 'first_name' => 'Менеджер', 'last_name' => 'Чужой', 'phone' => '79000000033', 'roles' => [Role::Manager], 'approved_at' => now()]);

        $this->actingAs($admin)->get(self::PARK.'/users')->assertOk()->assertSee('Сергей Управляющий')->assertDontSee('Менеджер Чужой');
        $this->actingAs($admin)->get(self::PARK.'/users?q=Чужой')->assertOk()->assertDontSee('Менеджер Чужой');
        $this->actingAs($admin)->get(self::PARK.'/users/'.$keeper->id)->assertOk()->assertSee('Не заходил');
        $this->actingAs($admin)->get(self::PARK.'/users/'.$manager->id)->assertNotFound();
        $this->actingAs($admin)->put(self::PARK.'/users/'.$manager->id, ['first_name' => 'X', 'last_name' => 'Y', 'phone' => '79000000033', 'kind' => 'manager'])->assertNotFound();

        $this->actingAs($admin)->put(self::PARK.'/users/'.$keeper->id, ['first_name' => 'Сергей', 'last_name' => 'Николаев', 'phone' => '+7 900 000-00-32', 'kind' => 'helper'])->assertRedirect();
        $keeper->refresh();
        $this->assertSame('Николаев', $keeper->last_name);
        $this->assertTrue($keeper->park_readonly);
        $this->assertTrue($keeper->hasRole(Role::Buyer) && $keeper->hasRole(Role::Parking));

        // Управляющему экран людей закрыт.
        $this->assertNotSame(200, $this->actingAs($keeper)->get(self::PARK.'/users')->status());

        // Закрыть доступ к парковке: роль парковки снимается, покупатель остаётся.
        $this->actingAs($admin)->post(self::PARK.'/users/'.$keeper->id.'/revoke')->assertRedirect('/users');
        $keeper->refresh();
        $this->assertFalse($keeper->hasRole(Role::Parking));
        $this->assertTrue($keeper->hasRole(Role::Buyer));
    }
}
