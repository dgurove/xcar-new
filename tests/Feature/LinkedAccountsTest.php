<?php

namespace Tests\Feature;

use App\Park\City;
use App\Support\Surface;
use App\Users\Actions\LinkAccounts;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Один человек — два аккаунта (02.11.2026, владелец: Сергей — управляющий на park.xcar.ru и менеджер на xcar.ru, «разделить
 * чётко»): вход один, на хосте работает аккаунт, чей это дом (`UseLinkedAccount`). Тихая ошибка: роли управляющего видны на
 * сайте или стена «сюда только сотрудникам» у своего же человека.
 */
class LinkedAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $park;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->park = User::create(['name' => 'Сергей Николаев', 'login' => 'sergey.nikolaev', 'roles' => [Role::Parking], 'park_city_id' => City::main(), 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Сергей Николаев', 'phone' => '79266212026', 'roles' => [Role::Manager], 'approved_at' => now()]);
        app(LinkAccounts::class)($this->park, $this->manager);
    }

    public function test_each_host_runs_its_own_account(): void
    {
        // Вошёл управляющим — на сайте он менеджер, на парковке управляющий.
        $this->actingAs($this->park)->get(Surface::Site->url('/deals'))->assertOk();
        $this->assertSame($this->manager->id, auth()->id());
        $this->actingAs($this->park)->get(Surface::Park->url('/cars'))->assertOk();
        $this->assertSame($this->park->id, auth()->id());

        // Вошёл менеджером — парковка открыта управляющим, без стены.
        $this->actingAs($this->manager)->get(Surface::Park->url('/cars'))->assertOk();
        $this->assertSame($this->park->id, auth()->id());
    }

    public function test_switchers_lead_to_linked_host(): void
    {
        $this->actingAs($this->manager);
        $this->assertContains('Парковка', array_column(\App\Support\Nav::cabinetFor($this->manager, 'phone', Surface::Site)['Переходы'] ?? [], 'label'));
    }

    public function test_same_home_cannot_be_linked(): void
    {
        $other = User::create(['name' => 'Другой менеджер', 'phone' => '79000000099', 'roles' => [Role::Manager], 'approved_at' => now()]);

        $this->expectException(ValidationException::class);
        app(LinkAccounts::class)($this->manager, $other);
    }

    public function test_unlinked_park_manager_is_sent_home_from_site(): void
    {
        app(\App\Users\Actions\UnlinkAccounts::class)($this->park);

        $this->actingAs($this->park->fresh())->get(Surface::Site->url('/deals'))->assertRedirect();
    }
}
