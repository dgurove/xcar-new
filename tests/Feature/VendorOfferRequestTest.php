<?php

namespace Tests\Feature;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ежедневный запрос номеров вендору (15.10.2026): «К запросу» — есть отклик, ещё продаётся, номер не отправляли;
 * «Отправить» метит только не отправленные этого вендора.
 */
class VendorOfferRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_marks_only_pending_offers_of_this_vendor(): void
    {
        Queue::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $tbank = Vendor::create(['name' => 'Т тест']);
        $other = Vendor::create(['name' => 'Другой тест']);
        $bid = Offer::create(['claim_ref' => 'AUT-26-100001', 'vendor_id' => $tbank->id]);
        $liked = Offer::create(['claim_ref' => 'AUT-26-100002', 'vendor_id' => $tbank->id]);
        $quiet = Offer::create(['claim_ref' => 'AUT-26-100003', 'vendor_id' => $tbank->id]);
        $sold = Offer::create(['claim_ref' => 'AUT-26-100004', 'vendor_id' => $tbank->id]);
        $sold->forceFill(['state' => OfferState::Sold])->saveQuietly();
        $foreign = Offer::create(['claim_ref' => 'AUT-26-100005', 'vendor_id' => $other->id]);
        foreach ([$bid, $sold, $foreign] as $o) {
            Bid::create(['offer_id' => $o->id, 'user_id' => $manager->id, 'amount' => 500000, 'state' => BidState::Declined]);
        }
        Interest::create(['offer_id' => $liked->id, 'user_id' => $manager->id, 'state' => InterestState::New]);
        $crm = 'http://crm.'.parse_url(config('app.url'), PHP_URL_HOST);

        $this->actingAs($admin)->get("{$crm}/settings/vendors/{$tbank->id}?pill=offers&todo=1")->assertOk()
            ->assertSee('Отправить 2 номера')->assertSee('AUT-26-100001')->assertSee('AUT-26-100002')
            ->assertDontSee('AUT-26-100003')->assertDontSee('AUT-26-100004');

        $this->post("{$crm}/settings/vendors/{$tbank->id}/offers/request", ['offers' => [$bid->id, $liked->id, $foreign->id]])->assertRedirect();
        $this->assertNotNull($bid->fresh()->requested_at);
        $this->assertNotNull($liked->fresh()->requested_at);
        $this->assertNull($foreign->fresh()->requested_at, 'чужой вендор не метится');

        $this->post("{$crm}/settings/vendors/{$tbank->id}/offers/request", ['offers' => [$bid->id]])->assertSessionHas('toast', 'Эти номера уже отправлены');
        $this->assertSame(1, $bid->fresh()->events()->where('type', 'requested')->count(), 'повтор не дублирует');

        $this->get("{$crm}/settings/vendors/{$tbank->id}?pill=offers&todo=1")->assertDontSee('Отправить');
        $this->delete("{$crm}/settings/vendors/{$tbank->id}/offers/{$bid->fresh()->number}/request")->assertRedirect();
        $this->assertNull($bid->fresh()->requested_at);
    }
}
