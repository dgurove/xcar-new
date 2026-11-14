<?php

namespace Tests\Feature;

use App\Garage\Car;
use App\Garage\CarState;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\Showing;
use App\Users\BuyerGroup;
use App\Users\Invite;
use App\Users\PasswordLink;
use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «Сделать менеджером…» (14.11.2026): обратная дверь к «Сделать покупателем…». Ошибка здесь молчалива: покупатель с
 * менеджерскими ролями остался бы с `manager_id` и покупательскими группами, а его гараж открыл бы закупочную.
 */
class MakeManagerTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private const SITE = 'http://xcar.localhost';

    private User $admin;

    private User $boss;

    private User $other;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Андрей Админов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->boss = User::create(['name' => 'Виталий Зайцев', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->other = User::create(['name' => 'Иван Другой', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->buyer = User::create([
            'name' => 'Олег Данилин', 'phone' => '79000000004', 'login' => 'oleg.danilin', 'password' => 'secret-pass',
            'roles' => [Role::Buyer], 'manager_id' => $this->boss->id, 'approved_at' => now(),
            'buyer_sees' => 'picked', 'buyer_access_at' => now(), 'contact_fields' => ['phone'],
        ]);
    }

    private function convert(array $data = [], ?User $user = null, string $base = self::CRM.'/settings/users'): TestResponse
    {
        return $this->actingAs($this->admin)->post($base.'/'.($user ?? $this->buyer)->id.'/manager', $data);
    }

    private function offer(): Offer
    {
        return Offer::create(['vendor_id' => Vendor::create(['name' => 'Тестовый вендор'])->id, 'asking_price' => 900000]);
    }

    private function garageCar(CarState $state = CarState::Repair): Car
    {
        return Car::create(['offer_id' => $this->offer()->id, 'manager_id' => $this->buyer->id, 'state' => $state, 'taken_at' => now()]);
    }

    public function test_buyer_becomes_manager_without_buyer_settings(): void
    {
        $invite = Invite::create(['manager_id' => $this->boss->id, 'created_by' => $this->boss->id, 'role' => Role::Buyer, 'code' => Invite::freshCode()]);
        $this->buyer->update(['invite_id' => $invite->id, 'access' => ['mail'], 'garage_share' => 60, 'deal_share' => 50]);
        $approved = $this->buyer->approved_at;

        $this->convert()->assertSessionHasNoErrors()
            ->assertRedirect(self::CRM.'/settings/users/'.$this->buyer->id)
            ->assertSessionHas('toast', 'Олег Данилин — менеджер');

        $manager = $this->buyer->fresh();
        $this->assertTrue($manager->isManager());
        $this->assertFalse($manager->isBuyer());
        $this->assertSame([Role::Manager->value], $manager->roles->map->value->all());
        $this->assertNull($manager->manager_id);
        $this->assertSame('all', $manager->buyer_sees);
        $this->assertNull($manager->buyer_access_at);
        $this->assertFalse($manager->buyer_show_vin);
        $this->assertNull($manager->invite_id);
        $this->assertSame([], $manager->contact_fields);
        $this->assertSame([], $manager->access);
        $this->assertNull($manager->vendor_id);
        $this->assertNull($manager->offer_regions);
        $this->assertNull($manager->garage_share);
        $this->assertNull($manager->deal_share);
        $this->assertFalse($manager->garage_enabled);
        $this->assertFalse($manager->garage_price_shown);
        $this->assertEquals($approved, $manager->approved_at);
        $this->assertSame(0, $this->boss->buyers()->count());
    }

    public function test_site_admin_screen_converts_too(): void
    {
        $this->convert([], null, self::SITE.'/account/users')
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::SITE.'/account/users/'.$this->buyer->id);

        $this->assertTrue($this->buyer->fresh()->isManager());
    }

    public function test_card_after_conversion_opens_without_the_door(): void
    {
        $this->convert()->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users/'.$this->buyer->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('id="to-manager-'.$this->buyer->id.'"', $html);
        $this->assertStringContainsString('Менеджер', $html);
    }

    public function test_partner_surfaces_stay_but_buyer_groups_and_showings_are_cleared(): void
    {
        $this->buyer->update(['roles' => [Role::Buyer, Role::Parking, Role::Contractor]]);

        $buyerGroup = BuyerGroup::create(['name' => 'Опт', 'manager_id' => $this->boss->id]);
        $this->buyer->groups()->attach($buyerGroup->id);
        $managerGroup = UserGroup::create(['name' => 'Москва', 'kind' => UserGroup::MANAGERS]);
        $this->buyer->userGroups()->attach($managerGroup->id);
        $showing = Showing::create(['offer_id' => $this->offer()->id, 'manager_id' => $this->boss->id, 'user_id' => $this->buyer->id, 'created_at' => now()]);

        $this->convert()->assertSessionHasNoErrors();

        $manager = $this->buyer->fresh();
        $roles = $manager->roles->map->value->all();
        $this->assertContains(Role::Manager->value, $roles);
        $this->assertContains(Role::Parking->value, $roles);
        $this->assertContains(Role::Contractor->value, $roles);
        $this->assertNotContains(Role::Buyer->value, $roles);
        $this->assertCount(0, $manager->groups);
        $this->assertCount(0, $manager->userGroups);
        $this->assertNull($showing->fresh());
    }

    public function test_car_in_garage_blocks_conversion(): void
    {
        $this->garageCar();

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->buyer->fresh()->isBuyer());
    }

    public function test_closed_garage_history_blocks_conversion(): void
    {
        $this->garageCar(CarState::Settled);

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->buyer->fresh()->isBuyer());
    }

    public function test_live_buyer_deal_blocks_conversion(): void
    {
        $deal = Deal::create(['offer_id' => $this->offer()->id, 'buyer_id' => $this->boss->id, 'state' => DealState::Active, 'cost' => 800000, 'amount' => 900000]);
        DealContract::create(['deal_id' => $deal->id, 'buyer_user_id' => $this->buyer->id]);

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->buyer->fresh()->isBuyer());
    }

    public function test_finished_buyer_deal_does_not_block(): void
    {
        $deal = Deal::create(['offer_id' => $this->offer()->id, 'buyer_id' => $this->boss->id, 'state' => DealState::Done, 'cost' => 800000, 'amount' => 900000]);
        DealContract::create(['deal_id' => $deal->id, 'buyer_user_id' => $this->buyer->id]);

        $this->convert()->assertSessionHasNoErrors();
        $this->assertTrue($this->buyer->fresh()->isManager());
    }

    public function test_password_link_is_issued_when_buyer_has_no_password(): void
    {
        $bare = User::create([
            'name' => 'Без Пароля', 'phone' => '79000000005', 'roles' => [Role::Buyer], 'manager_id' => $this->boss->id,
            'approved_at' => now(), 'buyer_access_at' => now(),
        ]);
        $this->assertNull($bare->password);

        $response = $this->convert(['password' => '1'], $bare)->assertSessionHasNoErrors();

        $link = session('password_link');
        $this->assertSame($bare->id, $link['user']);
        $this->assertStringContainsString('/password/link/', $link['url']);
        $this->assertSame(1, PasswordLink::where('user_id', $bare->id)->count());
        $response->assertRedirect(self::CRM.'/settings/users/'.$bare->id);
    }

    public function test_no_password_link_without_request(): void
    {
        $this->convert()->assertSessionHasNoErrors();

        $this->assertSame(0, PasswordLink::where('user_id', $this->buyer->id)->count());
        $this->assertNull(session('password_link'));
    }

    public function test_contact_is_needed_when_buyer_has_none(): void
    {
        $bare = User::create(['name' => 'Без Контактов', 'roles' => [Role::Buyer], 'manager_id' => $this->boss->id, 'approved_at' => now()]);
        $this->assertFalse($bare->hasSignInContact());

        $this->convert([], $bare)->assertSessionHasErrors('contact');
        $this->assertTrue($bare->fresh()->isBuyer());

        $this->convert(['contact' => '79000000009', 'password' => '1'], $bare)->assertSessionHasNoErrors();

        $manager = $bare->fresh();
        $this->assertTrue($manager->isManager());
        $this->assertSame('79000000009', $manager->phone);
        $this->assertSame([], $manager->contact_fields);
        $this->assertNotNull(session('password_link'));
    }

    public function test_manager_super_buyer_and_staff_are_not_converted(): void
    {
        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$this->boss->id.'/manager')->assertNotFound();

        $super = User::create(['name' => 'Супер Покупатель', 'phone' => '79000000006', 'roles' => [Role::SuperBuyer], 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$super->id.'/manager')->assertNotFound();

        $owner = User::create(['name' => 'Главный', 'phone' => '79000000007', 'roles' => [Role::Owner], 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$owner->id.'/manager')->assertNotFound();
    }

    public function test_only_admin_converts(): void
    {
        $this->actingAs($this->boss)->post(self::CRM.'/settings/users/'.$this->buyer->id.'/manager')->assertForbidden();
        $this->assertTrue($this->buyer->fresh()->isBuyer());
    }

    public function test_sheet_offers_the_separate_door(): void
    {
        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users?preset=buyers')->assertOk()->getContent();

        $this->assertStringContainsString('Сделать менеджером…', $html);
        $this->assertStringContainsString('id="to-manager-'.$this->buyer->id.'"', $html);
        $this->assertStringContainsString('/settings/users/'.$this->buyer->id.'/manager', $html);

        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users?preset=managers')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="to-manager-'.$this->buyer->id.'"', $html);
        $this->assertStringNotContainsString('id="to-manager-'.$this->boss->id.'"', $html);
    }

    public function test_sheet_warns_about_garage_blocker(): void
    {
        $this->garageCar();

        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users?preset=buyers')->assertOk()->getContent();

        $this->assertStringContainsString('Сейчас перевести нельзя', $html);
        $this->assertStringContainsString('машин в гараже: 1', $html);
    }
}
