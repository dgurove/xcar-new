<?php

namespace Tests\Feature;

use App\Users\Impersonation;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Главный администратор (27.10.2026, владелец): входит «Войти как» и за администраторов, за него — никто; его карточку
 * другой админ не правит. Доступ: ошибка здесь — админ заходит под владельцем.
 */
class OwnerImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_enters_as_admins_and_nobody_as_owner(): void
    {
        $owner = User::create(['name' => 'Дмитрий', 'phone' => '79000000003', 'roles' => [Role::Admin, Role::ParkAdmin, Role::Owner], 'approved_at' => now()]);
        $admin = User::create(['name' => 'Екатерина', 'phone' => '79000000030', 'roles' => [Role::Admin, Role::Moderator, Role::Manager], 'approved_at' => now()]);
        $other = User::create(['name' => 'Андрей', 'phone' => '79000000004', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000017', 'roles' => [Role::Manager], 'approved_at' => now()]);

        $this->assertTrue(Impersonation::allowed($owner, $admin));
        $this->assertTrue(Impersonation::allowed($owner, $manager));
        $this->assertFalse(Impersonation::allowed($owner, $owner));
        $this->assertFalse(Impersonation::allowed($admin, $owner));
        $this->assertFalse(Impersonation::allowed($admin, $other));
        $this->assertTrue(Impersonation::allowed($admin, $manager));

        // Другой админ карточку главного не правит и роль ему не снимет.
        $this->actingAs($other)->post(\App\Support\Surface::Crm->url("/settings/users/{$owner->id}/access"), ['reject' => 1])->assertNotFound();
        $this->assertTrue($owner->fresh()->isApproved());
        // Тот же адрес за обычного человека работает — 404 выше от защиты, а не от адреса.
        $this->actingAs($other)->post(\App\Support\Surface::Crm->url("/settings/users/{$manager->id}/access"), ['reject' => 1])->assertRedirect();
    }
}
