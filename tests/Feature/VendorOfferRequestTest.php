<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\ClaimRequest;
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
        $mailed = Offer::create(['claim_ref' => 'AUT-26-100006', 'vendor_id' => $tbank->id]);
        Bid::create(['offer_id' => $mailed->id, 'user_id' => $manager->id, 'amount' => 500000, 'state' => BidState::Active]);
        $account = Account::create(['slug' => 'offer', 'title' => 'offer', 'email' => 'offer@test.ru', 'scope' => 'offers', 'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'offer_id' => $mailed->id, 'subject' => 'AUT-26-100006']);
        Message::create(['thread_id' => $thread->id, 'account_id' => $account->id, 'direction' => 'in', 'subject' => 'AUT-26-100006', 'from_email' => 'x@tinsurance.ru']);
        Interest::create(['offer_id' => $liked->id, 'user_id' => $manager->id, 'state' => InterestState::New]);
        $crm = 'http://crm.'.parse_url(config('app.url'), PHP_URL_HOST);

        $this->actingAs($admin)->get("{$crm}/settings/vendors/{$tbank->id}?pill=requests")->assertOk()
            ->assertSee('Отправить 2 номера')->assertSee('AUT-26-100001')->assertSee('AUT-26-100002')
            ->assertDontSee('AUT-26-100003')->assertDontSee('AUT-26-100004')->assertDontSee('AUT-26-100006');

        $this->post("{$crm}/settings/vendors/{$tbank->id}/requests", ['offers' => [$bid->id, $liked->id, $foreign->id]])->assertRedirect();
        $request = ClaimRequest::sole();
        $this->assertSame(['AUT-26-100001', 'AUT-26-100002'], $request->refs);
        $this->assertSame($request->id, $bid->fresh()->claim_request_id);
        $this->assertSame($request->id, $liked->fresh()->claim_request_id);
        $this->assertNull($foreign->fresh()->claim_request_id, 'чужой вендор не метится');

        $this->post("{$crm}/settings/vendors/{$tbank->id}/requests", ['offers' => [$bid->id]])->assertSessionHas('toast', 'Эти номера уже отправлены');
        $this->assertSame(1, $bid->fresh()->events()->where('type', 'requested')->count(), 'повтор не дублирует');

        $this->get("{$crm}/settings/vendors/{$tbank->id}?pill=requests")->assertSee('Новых откликов нет')->assertSee('2 номера');
        $this->delete("{$crm}/settings/vendors/{$tbank->id}/offers/{$bid->fresh()->number}/request")->assertRedirect();
        $this->assertNull($bid->fresh()->claim_request_id);
        $this->assertSame(['AUT-26-100001', 'AUT-26-100002'], $request->fresh()->refs, 'снимок не трогается');
    }
}
