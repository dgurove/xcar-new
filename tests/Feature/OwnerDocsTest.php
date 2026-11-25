<?php

namespace Tests\Feature;

use App\Billing\Jobs\ReadPassport;
use App\Mail\Extraction\Code;
use App\Mail\Message;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\FillOwnerFromPassport;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Disclosures;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OwnerDocs;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Документы собственника из письма страховой (25.11.2026, владелец: «по Тиньку они нам вообще СТС и паспорт отправляют, нам
 * приходится вручную скачивать и в чате отправлять, это ж бред»): паспорт и СТС отмечены в «Показать менеджеру» сразу, паспорт —
 * сканом продавца ДКП собственника. Ошибка молчаливая: менеджер без документов, договор без продавца.
 */
class OwnerDocsTest extends TestCase
{
    use RefreshDatabase;

    /** Разные картинки: одинаковые склеились бы в один кадр (`PhotoIngest::twin`). */
    private static function png(int $shade = 0): string
    {
        $img = imagecreatetruecolor(400, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, $shade, $shade, $shade));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** @return array{Offer, Deal, Message} */
    private function deal(): array
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-966441', 'floor_price' => 500000, 'owner_price' => 500000, 'asking_price' => 650000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 600000), $admin, 0, scheme: DealScheme::OwnerDkp);

        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = \App\Mail\Thread::create(['account_id' => $account, 'subject' => $offer->claim_ref, 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $letter = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'p.roevnev@tbank.ru', 'date_at' => now(),
            'subject' => 'AUT-26-966441 || 2024 Geely Cityray (У107НХ198 / LB3G43S98RX307751) ||',
            'parsed' => ['keys' => ['code:'.Code::key($offer->claim_ref)], 'fields' => ['insured_name' => ['value' => 'Курочкин Роман Владимирович', 'source' => 'body'],
                'insured_phone' => ['value' => '+79213400657', 'source' => 'body']],
                'own_text' => "Добрый день! Документы во вложении\nКурочкин Роман Владимирович +7(921)340-06-57"]]);
        $car = $offer->fresh();
        foreach (['Паспорт1.png' => self::png(), 'паспорт2.png' => self::png(120), 'СТС.pdf' => '%PDF-1.4', 'Паспорт ТС.pdf' => '%PDF-1.4', 'images.pdf' => '%PDF-1.4'] as $name => $body) {
            $car->addMediaFromString($body)->usingFileName($name)->usingName(pathinfo($name, PATHINFO_FILENAME))->withCustomProperties(['letter' => $letter->id])->toMediaCollection('papers');
        }

        return [$offer->fresh(), $deal->fresh(), $letter];
    }

    public function test_owner_documents_are_recognised_and_picked_for_the_manager(): void
    {
        [$offer, $deal] = $this->deal();
        $kinds = $offer->getMedia('papers')->mapWithKeys(fn ($m) => [$m->file_name => OwnerDocs::kind($m)])->all();
        $this->assertSame('passport', $kinds['Паспорт1.png']);
        $this->assertSame('passport', $kinds['паспорт2.png']);
        $this->assertSame('sts', $kinds['СТС.pdf']);
        $this->assertSame('pts', $kinds['Паспорт-ТС.pdf'] ?? $kinds['Паспорт ТС.pdf']);
        $this->assertNull($kinds['images.pdf']);
        $this->assertSame('passport', OwnerDocs::kindOfText("РОССИЙСКАЯ ФЕДЕРАЦИЯ\nПАСПОРТ\nКод подразделения 770-001"));

        // «Показать контакты менеджеру»: паспорт и СТС — отмечены, «images.pdf» — нет.
        $proposals = Disclosures::proposals($deal, Disclosures::contactLetters($deal), contact: true);
        $picked = collect($proposals)->filter(fn ($p) => ($p['pick'] ?? false) && $p['kind'] === 'file')->pluck('value')->sort()->values()->all();
        $this->assertSame(['Паспорт ТС', 'Паспорт1', 'СТС', 'паспорт2'], $picked);
        $images = collect($proposals)->first(fn ($p) => $p['value'] === 'images');
        $this->assertFalse($images['pick'] ?? false);
    }

    public function test_passport_from_letter_becomes_dkp_seller_scan(): void
    {
        Queue::fake([ReadPassport::class]);
        [$offer, $deal] = $this->deal();

        $files = app(FillOwnerFromPassport::class)($deal);
        $this->assertCount(2, $files);
        $seller = DealContract::for($deal->fresh())->seller;
        $this->assertSame('Курочкин Роман Владимирович', $seller->name);
        $this->assertNotNull($seller->passportScan('main'));
        $this->assertNotNull($seller->passportScan('reg'));
        Queue::assertPushed(ReadPassport::class, 2);

        // Второй раз — ничего: скан уже есть, вписанное не трогаем.
        $seller->update(['passport' => '40 12 345678']);
        $this->assertNull(app(FillOwnerFromPassport::class)($deal->fresh()));
        $this->assertSame('40 12 345678', $seller->fresh()->passport);
    }
}
