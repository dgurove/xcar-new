<?php

namespace Tests\Feature;

use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Главная CRM — космос, предложения — на `/offers` (04.11.2026). Молча ломается то, чего глазами не увидеть: старые
 * ссылки на список (`/?preset=…` в Telegram и пушах) вели бы в космос, а вход — не в предложения.
 */
class CrmHomeTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    public function test_root_is_space_and_offers_moved(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Катя', 'phone' => '79000000050', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $moderator = User::create(['name' => 'Мод', 'phone' => '79000000051', 'roles' => [Role::Moderator], 'approved_at' => now()]);

        // Старая ссылка — тот же адрес под `/offers`, ещё до входа: вернуться после входа надо в список.
        $this->get(self::CRM.'/?preset=bids&vid=table&peek=5')->assertStatus(302)->assertRedirect(self::CRM.'/offers?peek=5&preset=bids&vid=table');
        // Гость с корня — на вход без «вернуться сюда».
        $this->get(self::CRM.'/')->assertRedirect('/login');

        foreach ([$admin, $moderator] as $user) {
            $this->flushHeaders();
            // Открыли CRM снаружи (закладка, адрес руками) — предложения; логотипом изнутри — космос.
            $this->actingAs($user)->get(self::CRM.'/')->assertRedirect('/offers');
            $this->actingAs($user)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get(self::CRM.'/')->assertOk()->assertSee('data-controller="space"', false)->assertSee('href="/"', false);
            $this->actingAs($user)->withHeaders(['Sec-Fetch-Site' => 'none', 'Referer' => self::CRM.'/'])->get(self::CRM.'/')->assertOk();
            $this->actingAs($user)->get(self::CRM.'/offers?preset=nofloor')->assertOk()->assertSee('href="/offers"', false);
        }
        auth()->logout();

        $admin->forceFill(['password' => 'secret-pass'])->save();
        $this->get(self::CRM.'/');
        $this->post(self::CRM.'/login', ['login' => '79000000050', 'password' => 'secret-pass'])->assertRedirect('/offers');
    }
}
