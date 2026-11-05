<?php

namespace Tests\Feature;

use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Покупатель менеджера — по имени (05.11.2026, владелец: «менеджеры не хотят прикладывать данные покупателей, потому что
 * просит телефон — убери; почту тоже не надо»): шторка выбора и «Добавить покупателя» заводят его без телефона и входа,
 * вход — потом «Дать вход» с телефоном.
 */
class BuyerByNameTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->manager = User::create(['name' => 'Армен', 'phone' => '79000000001', 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    public function test_pick_sheet_adds_buyer_by_name(): void
    {
        $this->actingAs($this->manager)->postJson('http://xcar.localhost/people', ['kind' => 'buyer', '_person' => ['first_name' => 'Иван', 'last_name' => 'Петров']])
            ->assertOk()->assertJsonPath('credentials', null);
        $buyer = User::where('manager_id', $this->manager->id)->sole();
        $this->assertSame('Иван Петров', $buyer->name);
        $this->assertNull($buyer->phone);
        $this->assertTrue($buyer->isBuyer());
    }

    public function test_buyers_form_adds_by_name_and_gives_access_later(): void
    {
        $this->actingAs($this->manager)->post('http://xcar.localhost/buyers/invites', ['mode' => 'person', 'first_name' => 'Олег'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('credentials');
        $buyer = User::where('manager_id', $this->manager->id)->sole();
        $this->assertNull($buyer->phone);
        $this->get('http://xcar.localhost/buyers/'.$buyer->id)->assertOk()->assertSee('Дать вход');

        $this->post('http://xcar.localhost/buyers/'.$buyer->id.'/access', ['contact' => '+7 900 000-00-01'])->assertSessionHasErrors('contact');
        // Без телефона — почтой (владелец: «покупатели могут без телефона входить по почте»).
        $this->post('http://xcar.localhost/buyers/'.$buyer->id.'/access', ['contact' => 'Oleg@Mail.ru'])->assertSessionHas('credentials.via', 'Почта');
        $this->assertSame('oleg@mail.ru', $buyer->fresh()->email);
        $this->assertNull($buyer->fresh()->phone);
        $this->assertTrue($buyer->fresh()->mayHave('email'));
    }
}
