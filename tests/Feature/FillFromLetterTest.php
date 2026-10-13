<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\FillFromLetter;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Письмо к уже заведённому предложению дописывает пустое (13.10.2026, начальник: у Ranger письмо Т-Страхования пришло,
 * а VIN в черновик не встал). Пересылка сотрудника — тоже письмо вендора; вписанное руками не трогается.
 */
class FillFromLetterTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private function offer(array $attrs = []): Offer
    {
        $this->vendor = Vendor::firstOrCreate(['name' => 'Т-Страхование'], ['kind' => Kind::Insurer]);
        $brand = Brand::firstOrCreate(['slug' => 'ford'], ['name' => 'Ford']);
        $model = CarModel::firstOrCreate(['brand_id' => $brand->id, 'slug' => 'ranger'], ['name' => 'Ranger']);

        return Offer::create($attrs + ['claim_ref' => 'AUT-26-790483', 'vendor_id' => $this->vendor->id, 'brand_id' => $brand->id, 'model_id' => $model->id])->fresh();
    }

    /** Пересылка Андрея с личной почты: в цитате письмо Т-Страхования. */
    private function letter(array $fields, string $code = 'aut26790483'): Message
    {
        User::firstOrCreate(['phone' => '79006691111'], ['name' => 'Кузнецов Андрей', 'email' => '6691111@mail.ru', 'roles' => [Role::Admin], 'approved_at' => now()]);
        Cache::forget('mail:own-emails');
        $account = Account::firstOrCreate(['slug' => 'deal'], ['title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => 'Fwd: AUT-26-790483 | 2024 | Ford Ranger']);
        $wrap = fn (array $f) => array_map(fn ($v) => ['value' => $v, 'source' => 'subject'], $f);

        return Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In,
            'from_email' => '6691111@mail.ru', 'subject' => 'Fwd: AUT-26-790483 | 2024 | Ford Ranger | LJXED3F15RTP11721',
            'text_body' => "-------- Пересылаемое сообщение --------\nОт: Anastasiya Dmitruk <a.dmitruk@tinsurance.ru>\n\n> Предлагаем принять участие в оценке ТС.\n> Цена за Лот 2 650 000",
            'date_at' => now(), 'parsed' => ['keys' => ['code:'.$code, 'vin:LJXED3F15RTP11721'], 'fields' => $wrap($fields + [
                'sender' => 'a.dmitruk@tinsurance.ru', 'vendor_id' => $this->vendor->id,
            ])]]);
    }

    public function test_forward_fills_empty_vin_year_and_contact_but_keeps_manual(): void
    {
        $offer = $this->offer(['floor_price' => 2500000]);
        $letter = $this->letter(['vin' => 'LJXED3F15RTP11721', 'year' => '2024', 'floor_price' => 2650000]);
        app(FillFromLetter::class)($offer, $letter);

        $offer->refresh();
        $this->assertSame('LJXED3F15RTP11721', $offer->vin);
        $this->assertSame(2024, (int) $offer->year);
        $this->assertSame(2500000, (int) $offer->floor_price, 'вписанная руками закупочная не трогается');
        $this->assertSame('a.dmitruk@tinsurance.ru', $offer->contact_email);
    }

    public function test_letter_about_other_claim_writes_nothing(): void
    {
        $offer = $this->offer();
        app(FillFromLetter::class)($offer, $this->letter(['vin' => 'LJXED3F15RTP11721', 'year' => '2024'], code: 'aut26111111'));

        $this->assertNull($offer->fresh()->vin);
    }

    public function test_published_offer_gets_only_contact(): void
    {
        $offer = $this->offer();
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()])->save();
        app(FillFromLetter::class)($offer->fresh(), $this->letter(['vin' => 'LJXED3F15RTP11721', 'insured_phone' => '+79001112233']));

        $this->assertNull($offer->fresh()->vin);
        $this->assertSame('+79001112233', $offer->fresh()->insured_phone);
    }

    public function test_vin_of_other_live_offer_is_not_written(): void
    {
        $this->offer(['claim_ref' => 'AUT-26-000001', 'vin' => 'LJXED3F15RTP11721']);
        $offer = $this->offer();
        app(FillFromLetter::class)($offer, $this->letter(['vin' => 'LJXED3F15RTP11721']));

        $this->assertNull($offer->fresh()->vin);
    }
}
