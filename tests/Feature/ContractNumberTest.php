<?php

namespace Tests\Feature;

use App\Offers\ContractNumber;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Номер договора «ГГГГММДД-NN» днём «Отправить менеджеру», одна очередь на ПРАЙМ и Р.О.С. (16.11.2026, владелец: «супер важная
 * штука»). Ошибка молчаливая и дорогая: два договора с одним номером или номер, сменившийся после того, как его распечатали.
 */
class ContractNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function contract(DealScheme $scheme): DealContract
    {
        $manager = User::create(['name' => 'Менеджер '.uniqid(), 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::firstOrCreate(['name' => 'Совкомбанк тест'], ['deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000]);
        $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager->id, 'amount' => 1000000, 'cost' => 900000, 'scheme' => $scheme]);

        return DealContract::create(['deal_id' => $deal->id, 'buyer_user_id' => $manager->id, 'price' => 1000000, 'ready_at' => now()]);
    }

    public function test_number_is_day_of_sending_one_queue_for_prime_and_ros_never_changes(): void
    {
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        // 16.11 23:30 по Москве = 20:30 UTC; 21:10 UTC — уже 17.11 по Москве.
        Carbon::setTestNow(Carbon::parse('2026-11-16 23:30', 'Europe/Moscow'));
        $prime = $this->contract(DealScheme::Prime);
        $ros = $this->contract(DealScheme::Ros);
        $later = $this->contract(DealScheme::Prime);
        $this->assertNull($prime->numberLabel());

        $this->assertSame('20261116-01', ContractNumber::assign($prime));
        $this->assertSame('20261116-02', ContractNumber::assign($ros));
        // Повторно — тот же номер, счётчик не двигается.
        $this->assertSame('20261116-01', ContractNumber::assign($prime->fresh()));

        Carbon::setTestNow(Carbon::parse('2026-11-17 00:10', 'Europe/Moscow'));
        $this->assertSame('20261117-01', ContractNumber::assign($later));
        $this->assertSame('20261116-01', $prime->fresh()->numberLabel());

        // Дубль номера база не примет.
        $this->expectException(QueryException::class);
        $this->contract(DealScheme::Prime)->forceFill(['code' => '20261116-01'])->save();
    }

    public function test_owner_form_has_no_number(): void
    {
        $contract = $this->contract(DealScheme::OwnerDkp);
        $this->assertSame('owner', $contract->form());
        $this->assertNull(ContractNumber::assign($contract));
        $this->assertNull($contract->fresh()->code);
    }
}
