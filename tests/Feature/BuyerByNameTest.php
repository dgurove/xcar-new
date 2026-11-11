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
        // Без доступа (11.11.2026): ни «видит все», ни входа — строка «Без доступа» и «Дать доступ».
        $this->get('http://xcar.localhost/buyers/'.$buyer->id)->assertOk()->assertSee('Без доступа к предложениям')->assertDontSee('Видит все');
        $this->assertFalse($buyer->fresh()->hasBuyerAccess());

        $this->post('http://xcar.localhost/buyers/'.$buyer->id.'/access', ['contact' => '+7 900 000-00-01'])->assertSessionHasErrors('contact');
        // Без телефона — почтой (владелец: «покупатели могут без телефона входить по почте»).
        $this->post('http://xcar.localhost/buyers/'.$buyer->id.'/access', ['contact' => 'Oleg@Mail.ru', 'buyer_sees' => 'picked'])->assertSessionHas('credentials.via', 'Почта');
        $buyer = $buyer->fresh();
        $this->assertSame('oleg@mail.ru', $buyer->email);
        $this->assertNull($buyer->phone);
        $this->assertTrue($buyer->mayHave('email'));
        $this->assertTrue($buyer->hasBuyerAccess());
        $this->assertSame('picked', $buyer->buyer_sees);

        // Закрыть доступ: войти не может, покупателем остаётся.
        $this->delete('http://xcar.localhost/buyers/'.$buyer->id.'/access')->assertSessionHas('toast', 'Доступ закрыт');
        $buyer = $buyer->fresh();
        $this->assertFalse($buyer->hasBuyerAccess());
        $this->assertTrue($buyer->isBuyer());
        $this->assertSame($this->manager->id, $buyer->manager_id);
        $this->actingAs($buyer)->get('http://xcar.localhost/offers')->assertForbidden()->assertSee('Доступ закрыт');
    }

    /** «С доступом» при добавлении (11.11.2026): чем войдёт — сразу, пароль шторкой. */
    public function test_buyers_form_adds_with_access(): void
    {
        $this->actingAs($this->manager)->post('http://xcar.localhost/buyers/invites', ['mode' => 'person', 'first_name' => 'Ира', 'access' => 'open'])
            ->assertSessionHasErrors('contact');
        $this->assertSame(0, User::where('manager_id', $this->manager->id)->count());
        $this->post('http://xcar.localhost/buyers/invites', ['mode' => 'person', 'first_name' => 'Ира', 'access' => 'open', 'contact' => '+7 900 111-22-33'])
            ->assertSessionHas('credentials.via', 'Телефон');
        $this->assertTrue(User::where('manager_id', $this->manager->id)->sole()->hasBuyerAccess());
    }

    /** С паспортом — имя по желанию: «Новый покупатель», скан на его контрагенте читается, дальше — его страница (07.11.2026). */
    public function test_buyer_by_passport_alone(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAs($this->manager)->post('http://xcar.localhost/people', ['kind' => 'buyer', 'mode' => 'person', '_person' => ['first_name' => ''],
            'passport' => [\Illuminate\Http\UploadedFile::fake()->image('main.jpg', 400, 300), \Illuminate\Http\UploadedFile::fake()->image('reg.jpg', 300, 400)]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('go', fn ($go) => str_starts_with((string) $go, '/buyers/'));
        $buyer = User::where('manager_id', $this->manager->id)->sole();
        $this->assertSame(\App\Users\Actions\AddPerson::UNNAMED, $buyer->name);
        $this->assertNotNull($buyer->party?->passportScan('main'));
        $this->assertNotNull($buyer->party->passportScan('reg'));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Billing\Jobs\ReadPassport::class, 2);

        // Скан прочитан — ФИО контрагенту, «Имя Фамилия» аккаунту.
        $media = $buyer->party->passportScan('main');
        $media->setCustomProperty('fields', ['name' => 'Алиев Гусейн Магомедович'])->save();
        \App\Billing\Jobs\ReadPassport::passportName($media->fresh());
        $this->assertSame('Гусейн Алиев', $buyer->fresh()->name);
        $this->assertSame('Алиев Гусейн Магомедович', $buyer->party->fresh()->name);

        // Ни имени, ни паспорта — «Имя или паспорт».
        $this->postJson('http://xcar.localhost/people', ['kind' => 'buyer', '_person' => ['first_name' => '']])->assertJsonValidationErrors(['first_name' => 'Имя или паспорт']);
    }

    /** Серия и номер двумя полями — в одно `passport`. */
    public function test_passport_series_and_number_join(): void
    {
        $this->assertSame('8207 445349', \App\Billing\PartyRules::passportNumber('82 07', ' 445349'));
        $this->assertSame(['8207', '445349'], \App\Billing\PartyRules::passportParts('82 07 445349'));
        $this->assertSame('AB 1234567', \App\Billing\PartyRules::passportNumber('AB', '1234567'));
    }
}
