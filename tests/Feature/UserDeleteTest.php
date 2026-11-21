<?php

namespace Tests\Feature;

use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Удалить» человека в CRM (21.11.2026): проверка следов спрашивала снесённую 28.10 таблицу `offer_managers` — удаление падало
 * с 500 у любого. Без следов — удаляется, со следами — только закрыть доступ.
 */
class UserDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_deletes_person_without_traces_and_keeps_one_with_buyers(): void
    {
        config(['xcar.mercure.hub' => '']);
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $admin = User::create(['name' => 'Админ', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $empty = User::create(['name' => 'Пустой', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        User::create(['name' => 'Покупатель', 'roles' => [Role::Buyer], 'manager_id' => $manager->id, 'approved_at' => now()]);

        $this->actingAs($admin)->delete('http://crm.xcar.localhost/settings/users/'.$empty->id)->assertRedirect()->assertSessionHas('toast', 'Удалён');
        $this->assertNull(User::find($empty->id));
        $this->actingAs($admin)->delete('http://crm.xcar.localhost/settings/users/'.$manager->id)->assertRedirect();
        $this->assertNotNull(User::find($manager->id));
    }
}
