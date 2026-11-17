<?php

namespace Tests\Feature;

use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корень CRM — редирект на `/offers` (17.11.2026: космос не прижился, `archive/space`). Молча ломается то, чего глазами не
 * увидеть: старые ссылки на список (`/?preset=…` в Telegram и пушах), вход не в предложения, логотип не к колесу вкладок.
 */
class CrmHomeTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    public function test_root_redirects_to_offers_and_logo_opens_wheel(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Катя', 'phone' => '79000000050', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Мод', 'phone' => '79000000051', 'roles' => [Role::Moderator], 'approved_at' => now()]);

        // Старая ссылка — тот же адрес под `/offers`, ещё до входа: вернуться после входа надо в список.
        $this->get(self::CRM.'/?preset=bids&vid=table&peek=5')->assertStatus(302)->assertRedirect(self::CRM.'/offers?peek=5&preset=bids&vid=table');
        // Гость с корня — в предложения, оттуда на вход.
        $this->get(self::CRM.'/')->assertRedirect('/offers');

        foreach ([$admin, $moderator] as $user) {
            $this->flushHeaders();
            $this->actingAs($user)->get(self::CRM.'/')->assertRedirect('/offers');
            $this->actingAs($user)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get(self::CRM.'/')->assertRedirect('/offers');
            $this->actingAs($user)->get(self::CRM.'/offers?preset=nofloor')->assertOk()->assertSee('href="/offers#tabs"', false)->assertSee('data-controller="tab-wheel"', false);
        }
        $this->actingAs($admin)->get(self::CRM.'/space/conveyor')->assertNotFound();
        auth()->logout();

        $admin->forceFill(['password' => 'secret-pass'])->save();
        $this->get(self::CRM.'/offers')->assertRedirect('/login');
        $this->post(self::CRM.'/login', ['login' => '79000000050', 'password' => 'secret-pass'])->assertRedirect('/offers');
    }
}
