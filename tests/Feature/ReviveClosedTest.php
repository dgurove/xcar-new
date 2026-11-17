<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Offers\Actions\ReviveClosed;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Письмо поставщика по машине из «Без подтверждений» возвращает её в «Без продажной цены» (18.11.2026, AUT-26-1013306:
 * письмо «Предлагаем принять участие в оценке ТС» легло к машине, а она лежала в «Без подтверждений», и его не увидели).
 */
class ReviveClosedTest extends TestCase
{
    use RefreshDatabase;

    private Offer $offer;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-1013306', 'floor_price' => 877883, 'asking_price' => 1030000]);
        $this->offer->forceFill(['state' => OfferState::Draft, 'published_at' => now()->subDays(12), 'intake_closed_at' => now()->subDays(9)])->save();
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'offer', 'title' => 'offer', 'email' => 'offer@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
    }

    private function letter(string $direction, $at): Message
    {
        return Message::create(['account_id' => $this->account, 'direction' => $direction, 'from_email' => $direction === 'in' ? 'm.kardysheva@tinsurance.ru' : 'offer@xcar.ru',
            'subject' => 'AUT-26-1013306_2024 GAC GS3', 'date_at' => $at, 'parsed' => ['own_text' => 'Предлагаем принять участие в оценке ТС']]);
    }

    public function test_supplier_letter_after_close_revives_into_unpriced(): void
    {
        // Письмо до закрытия приёма и наше письмо — не будят.
        ReviveClosed::onLetter($this->offer->fresh(), $this->letter('in', now()->subDays(10)));
        ReviveClosed::onLetter($this->offer->fresh(), $this->letter('out', now()));
        $this->assertNotNull($this->offer->fresh()->intake_closed_at);

        ReviveClosed::onLetter($this->offer->fresh(), $letter = $this->letter('in', now()));
        $offer = $this->offer->fresh();
        $this->assertNull($offer->intake_closed_at);
        $this->assertNull($offer->asking_price, 'в «Без продажной цены»');
        $this->assertSame(877883, $offer->floor_price);
        $was = $offer->revival();
        $this->assertSame([1030000, 0, $letter->id], [$was['asking'], $was['bids'], $was['letter']->id]);
        $this->assertTrue($offer->events()->where('type', 'updated')->get()->contains(fn ($e) => str_contains(preg_replace('/\s+/u', ' ', $e->text()), 'цена продажи 1 030 000')), 'цена — в «Истории»');

        // Второе письмо — уже не «Без подтверждений»: ничего не трогает.
        ReviveClosed::onLetter($offer, $this->letter('in', now()));
        $this->assertSame(1, $offer->events()->where('type', 'revived')->count());
    }
}
