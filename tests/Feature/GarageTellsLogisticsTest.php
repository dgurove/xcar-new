<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Channel;
use App\Logistics\Transport;
use App\Logistics\TransportNotice;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Preset;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Отдать в гараж», пока вывоз уже у водителя (10.11.2026, админ: «нужно, чтобы исполнителя информировала, что ушла в гараж и
 * какому менеджеру»): везёт логистика — водителю «В гараж: …» с менеджером; «Менеджер сам» — водителю «Вывоз не нужен».
 */
class GarageTellsLogisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $driver;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->driver = User::create(['name' => 'Иван Водитель', 'phone' => '79000000003', 'roles' => [Role::Driver], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $this->offer = app(StartRoute::class)(Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 612345, 'asking_price' => 900000])->fresh(), $this->admin, Track::Sale)->fresh();
        $t = app(OpenPickup::class)($this->offer, $this->admin);
        app(AssignExecutor::class)($t, Channel::Staff, $this->driver, $this->admin);
        Notification::fake();
    }

    private function give(string $pickup): void
    {
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/give', [
            'to' => 'garage', 'whom' => 'manager', 'manager_id' => $this->manager->id, 'payer' => 'manager', 'where' => 'owner', 'pickup' => $pickup,
        ])->assertSessionHasNoErrors();
    }

    public function test_logistics_driver_hears_where_to_now(): void
    {
        $this->give('us');
        Notification::assertSentTo($this->driver, TransportNotice::class, fn ($n) => str_starts_with($n->title(), 'В гараж:')
            && str_contains((string) $n->text(), 'Корабельников'));
        Notification::assertNotSentTo($this->driver, TransportNotice::class, fn ($n) => str_starts_with($n->title(), 'Вывоз не нужен'));
        $this->assertTrue(Transport::openFor($this->offer->fresh())->isExecutedBy($this->driver));
    }

    /** И не только в гараж (10.11.2026): в сделку менеджеру — водителю «В сделку: …» с менеджером. */
    public function test_deal_tells_driver_too(): void
    {
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/give', [
            'to' => 'deal', 'manager_id' => $this->manager->id, 'amount' => '950 000', 'scheme' => 'prime', 'pickup' => 'us',
        ]);
        Notification::assertSentTo($this->driver, TransportNotice::class, fn ($n) => str_starts_with($n->title(), 'В сделку:')
            && str_contains((string) $n->text(), 'Корабельников'));
    }

    public function test_driver_hears_manager_picks_himself(): void
    {
        $this->give('manager');
        Notification::assertSentTo($this->driver, TransportNotice::class, fn ($n) => str_starts_with($n->title(), 'Вывоз не нужен:'));
        Notification::assertNotSentTo($this->driver, TransportNotice::class, fn ($n) => str_starts_with($n->title(), 'В гараж:'));
        $this->assertTrue(Transport::openFor($this->offer->fresh())->isExecutedBy($this->manager));
    }
}
