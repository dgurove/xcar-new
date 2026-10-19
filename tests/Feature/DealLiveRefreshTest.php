<?php

namespace Tests\Feature;

use App\Billing\Events\InvoiceIssued;
use App\Billing\Invoice;
use App\Garage\GaragePayer;
use App\Live\PublishLiveUpdates;
use App\Offers\Actions\SettleContract;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Сделка без правки машины тоже перечитывается у всех (19.10.2026, владелец: «чтобы экран не обновлять»): договор,
 * счёт, адрес осмотра — CRM и страница менеджера. Ошибка молчит: экраны показывают старое до ручного обновления.
 */
class DealLiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->offer = Offer::create(['claim_ref' => 'AUT-26-300001', 'vin' => 'XTA21099000000555', 'year' => 2024, 'asking_price' => 900000])->fresh();
        Cache::flush();
        config(['xcar.mercure.hub' => 'http://hub.test/.well-known/mercure', 'xcar.mercure.publisher_key' => str_repeat('k', 32)]);
        Http::fake(['hub.test/*' => Http::response('id')]);
    }

    private function deal(array $extra = []): Deal
    {
        return Deal::create(['offer_id' => $this->offer->id, 'buyer_id' => $this->manager->id, 'state' => DealState::Active, 'cost' => 800000, 'amount' => 900000] + $extra);
    }

    private function refreshes(string $topic): array
    {
        return collect(Http::recorded())->map(function (array $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $parts = [];
            foreach (explode('&', $request->body()) as $piece) {
                [$k, $v] = array_map('urldecode', explode('=', $piece, 2));
                $parts[$k][] = $v;
            }

            return ['topics' => $parts['topic'] ?? [], 'type' => $parts['type'][0] ?? '', 'data' => json_decode($parts['data'][0] ?? '[]', true)];
        })->filter(fn ($p) => $p['type'] === 'refresh' && in_array($topic, $p['topics'], true))->flatMap(fn ($p) => $p['data']['paths'])->all();
    }

    public function test_contract_change_refreshes_crm_and_manager(): void
    {
        $deal = $this->deal();
        app(SettleContract::class)(DealContract::for($deal));

        $this->assertContains('/offers/'.$this->offer->number, $this->refreshes('staff'));
        $this->assertContains('/deals/'.$deal->id, $this->refreshes('user/'.$this->manager->id));
    }

    public function test_issued_invoice_refreshes_manager_deal(): void
    {
        $deal = $this->deal();
        app(PublishLiveUpdates::class)->invoice(new InvoiceIssued(new Invoice(['deal_id' => $deal->id])));

        $this->assertContains('/deals/'.$deal->id, $this->refreshes('user/'.$this->manager->id));
        $this->assertContains('/offers/'.$this->offer->number, $this->refreshes('staff'));
    }

    public function test_garage_deal_invoice_refreshes_garage_page_not_deals(): void
    {
        $deal = $this->deal(['garage_payer' => GaragePayer::Manager]);
        app(PublishLiveUpdates::class)->invoice(new InvoiceIssued(new Invoice(['deal_id' => $deal->id])));

        $paths = $this->refreshes('user/'.$this->manager->id);
        $this->assertContains('/garage/cars/'.$this->offer->number, $paths);
        $this->assertNotContains('/deals/'.$deal->id, $paths);
    }

    public function test_inspection_address_reaches_manager_deal(): void
    {
        $deal = $this->deal();
        app(UpdateOffer::class)($this->offer->fresh(), ['inspection_address' => 'Москва, Профсоюзная 1'], $this->admin);

        $this->assertContains('/deals/'.$deal->id, $this->refreshes('user/'.$this->manager->id));
    }
}
