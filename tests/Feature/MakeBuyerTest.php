<?php

namespace Tests\Feature;

use App\Garage\Car;
use App\Garage\CarState;
use App\Notifications\BuyerJoinedNotice;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Сделать покупателем…» (14.11.2026, Олег Данилин): покупатель — не ещё одна галка ролей, а отдельный переход.
 * Ошибка здесь молчалива: человек с покупателем и ролью менеджера остался бы в CRM, а его покупатели — без ведущего.
 */
class MakeBuyerTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    private const SITE = 'http://xcar.localhost';

    private User $admin;

    private User $manager;

    private User $boss;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Админов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Олег Данилин', 'phone' => '79000000002', 'login' => 'oleg.danilin', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->boss = User::create(['name' => 'Виталий Зайцев', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->other = User::create(['name' => 'Иван Другой', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    private function convert(array $data = [], ?User $user = null, string $base = self::CRM.'/settings/users'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post($base.'/'.($user ?? $this->manager)->id.'/buyer', array_merge([
            'manager_id' => $this->boss->id,
            'buyer_sees' => 'all',
            'access' => '1',
        ], $data));
    }

    private function offer(): Offer
    {
        return Offer::create(['vendor_id' => Vendor::create(['name' => 'Тестовый вендор'])->id, 'asking_price' => 900000]);
    }

    public function test_manager_becomes_buyer_of_chosen_manager_with_access(): void
    {
        $this->convert()->assertSessionHasNoErrors()
            ->assertRedirect(self::CRM.'/settings/users?preset=buyers&manager='.$this->boss->id)
            ->assertSessionHas('toast', 'Олег Данилин — покупатель Виталий Зайцев');

        $buyer = $this->manager->fresh();
        $this->assertTrue($buyer->isBuyer());
        $this->assertFalse($buyer->isManager());
        $this->assertSame([Role::Buyer->value], $buyer->roles->map->value->all());
        $this->assertSame($this->boss->id, $buyer->manager_id);
        $this->assertSame('all', $buyer->buyer_sees);
        $this->assertNotNull($buyer->buyer_access_at);
        $this->assertNull($buyer->invite_id);
        $this->assertSame([], $buyer->access);
        $this->assertNull($buyer->vendor_id);
        $this->assertNull($buyer->garage_share);
        $this->assertNull($buyer->deal_share);
        $this->assertNull($buyer->offer_regions);
        $this->assertFalse($buyer->garage_enabled);
        $this->assertFalse($buyer->garage_price_shown);
        Notification::assertSentTo($this->boss, BuyerJoinedNotice::class);
    }

    public function test_site_admin_screen_converts_too(): void
    {
        $this->convert([], null, self::SITE.'/account/users')
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::SITE.'/account/users?preset=buyers&manager='.$this->boss->id);

        $this->assertTrue($this->manager->fresh()->isBuyer());
    }

    public function test_roles_groups_and_invites_are_cleared_but_partner_surfaces_stay(): void
    {
        $this->manager->update(['roles' => [Role::Manager, Role::Moderator, Role::Parking, Role::Contractor]]);
        $managers = UserGroup::create(['name' => 'Москва', 'kind' => UserGroup::MANAGERS]);
        $moderators = UserGroup::create(['name' => 'XCar', 'kind' => UserGroup::MODERATORS]);
        $this->manager->userGroups()->attach([$managers->id, $moderators->id]);

        $buyerInvite = Invite::create(['manager_id' => $this->manager->id, 'created_by' => $this->manager->id, 'role' => Role::Buyer, 'code' => Invite::freshCode()]);
        $managerInvite = Invite::create(['manager_id' => $this->boss->id, 'created_by' => $this->manager->id, 'role' => Role::Manager, 'code' => Invite::freshCode()]);
        $foreign = Invite::create(['manager_id' => $this->other->id, 'created_by' => $this->other->id, 'role' => Role::Buyer, 'code' => Invite::freshCode()]);

        $this->convert()->assertSessionHasNoErrors();

        $buyer = $this->manager->fresh();
        $roles = $buyer->roles->map->value->all();
        $this->assertContains(Role::Buyer->value, $roles);
        $this->assertContains(Role::Parking->value, $roles);
        $this->assertContains(Role::Contractor->value, $roles);
        $this->assertNotContains(Role::Manager->value, $roles);
        $this->assertNotContains(Role::Moderator->value, $roles);
        $this->assertCount(0, $buyer->userGroups);
        $this->assertNotNull($buyerInvite->fresh()->disabled_at);
        $this->assertNotNull($managerInvite->fresh()->disabled_at);
        $this->assertNull($foreign->fresh()->disabled_at);
    }

    public function test_buyers_must_be_transferred_and_are_moved(): void
    {
        $first = User::create(['name' => 'Первый Покупатель', 'phone' => '79000000005', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now(), 'buyer_access_at' => now()]);
        $second = User::create(['name' => 'Второй Покупатель', 'phone' => '79000000006', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now(), 'buyer_access_at' => now()]);

        $this->convert()->assertSessionHasErrors('transfer_to');
        $this->assertTrue($this->manager->fresh()->isManager());
        $this->assertSame($this->manager->id, $first->fresh()->manager_id);

        $this->convert(['transfer_to' => $this->other->id])->assertSessionHasNoErrors();

        $this->assertTrue($this->manager->fresh()->isBuyer());
        $this->assertSame($this->other->id, $first->fresh()->manager_id);
        $this->assertSame($this->other->id, $second->fresh()->manager_id);
        Notification::assertSentTo($this->other, BuyerJoinedNotice::class);
    }

    public function test_open_deal_blocks_conversion(): void
    {
        $offer = $this->offer();
        $bid = Bid::create(['offer_id' => $offer->id, 'user_id' => $this->manager->id, 'amount' => 900000, 'state' => BidState::Declined]);
        Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $this->manager->id, 'state' => DealState::Active, 'cost' => 800000, 'amount' => 900000]);

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->manager->fresh()->isManager());
    }

    public function test_live_bid_blocks_conversion(): void
    {
        $offer = $this->offer();
        Bid::create(['offer_id' => $offer->id, 'user_id' => $this->manager->id, 'amount' => 900000, 'state' => BidState::Active]);

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->manager->fresh()->isManager());
    }

    public function test_car_in_garage_blocks_conversion(): void
    {
        $offer = $this->offer();
        Car::create(['offer_id' => $offer->id, 'manager_id' => $this->manager->id, 'state' => CarState::Repair, 'taken_at' => now()]);

        $this->convert()->assertSessionHasErrors('user');
        $this->assertTrue($this->manager->fresh()->isManager());
    }

    public function test_declined_bid_and_finished_deal_do_not_block(): void
    {
        $offer = $this->offer();
        $bid = Bid::create(['offer_id' => $offer->id, 'user_id' => $this->manager->id, 'amount' => 900000, 'state' => BidState::Declined]);
        Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $this->manager->id, 'state' => DealState::Done, 'cost' => 800000, 'amount' => 900000]);

        $this->convert()->assertSessionHasNoErrors();
        $this->assertTrue($this->manager->fresh()->isBuyer());
    }

    public function test_access_can_be_kept_closed_for_deals_only(): void
    {
        $this->convert(['access' => '0'])->assertSessionHasNoErrors();

        $buyer = $this->manager->fresh();
        $this->assertTrue($buyer->isBuyer());
        $this->assertNull($buyer->buyer_access_at);
        $this->assertFalse($buyer->hasBuyerAccess());
    }

    public function test_access_needs_contact_when_person_has_none(): void
    {
        $bare = User::create(['name' => 'Без Контактов', 'roles' => [Role::Manager], 'approved_at' => now()]);

        $this->convert([], $bare)->assertSessionHasErrors('contact');
        $this->assertTrue($bare->fresh()->isManager());

        $this->convert(['contact' => '79000000007'], $bare)->assertSessionHasNoErrors();
        $buyer = $bare->fresh();
        $this->assertSame('79000000007', $buyer->phone);
        $this->assertContains('phone', $buyer->contact_fields);
        $this->assertNotNull($buyer->buyer_access_at);
    }

    public function test_only_admin_another_person_and_not_owner_can_convert(): void
    {
        $this->actingAs($this->boss)->post(self::CRM.'/settings/users/'.$this->manager->id.'/buyer', ['manager_id' => $this->boss->id, 'buyer_sees' => 'all'])->assertForbidden();

        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$this->admin->id.'/buyer', ['manager_id' => $this->boss->id, 'buyer_sees' => 'all'])->assertNotFound();

        $owner = User::create(['name' => 'Главный', 'phone' => '79000000008', 'roles' => [Role::Owner], 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$owner->id.'/buyer', ['manager_id' => $this->boss->id, 'buyer_sees' => 'all'])->assertNotFound();

        $buyer = User::create(['name' => 'Уже Покупатель', 'phone' => '79000000009', 'roles' => [Role::Buyer], 'manager_id' => $this->boss->id, 'approved_at' => now()]);
        $this->actingAs($this->admin)->post(self::CRM.'/settings/users/'.$buyer->id.'/buyer', ['manager_id' => $this->boss->id, 'buyer_sees' => 'all'])->assertNotFound();
    }

    public function test_manager_must_be_a_live_manager_and_not_the_person(): void
    {
        $this->convert(['manager_id' => $this->manager->id])->assertSessionHasErrors('manager_id');
        $this->convert(['manager_id' => $this->admin->id])->assertSessionHasErrors('manager_id');
    }

    public function test_sheet_offers_the_separate_door(): void
    {
        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users?preset=managers')->assertOk()->getContent();

        $this->assertStringContainsString('Сделать покупателем…', $html);
        $this->assertStringContainsString('id="to-buyer-'.$this->manager->id.'"', $html);
        $this->assertStringContainsString('/settings/users/'.$this->manager->id.'/buyer', $html);
        // У покупателя и у себя кнопки нет.
        $buyer = User::create(['name' => 'Уже Покупатель', 'phone' => '79000000010', 'roles' => [Role::Buyer], 'manager_id' => $this->boss->id, 'approved_at' => now(), 'buyer_access_at' => now()]);
        $html = $this->actingAs($this->admin)->get(self::CRM.'/settings/users?preset=managers')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="to-buyer-'.$buyer->id.'"', $html);
        $this->assertStringNotContainsString('id="to-buyer-'.$this->admin->id.'"', $html);
    }
}
