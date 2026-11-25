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
use App\Offers\ManagerView;
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
 * Что видит менеджер — одно окно (25.11.2026, владелец: «„в сделку“ и „менеджеру“ — одно и то же; что из письма парсится — встаёт само,
 * а что у менеджера отображаем — сотрудникам видеть и решать, и после „показать“ — окошко, где видно, что он видит, с возможностью
 * убирать и добавлять»). Ошибка молчаливая: менеджер видит не то, что думает сотрудник.
 */
class ManagerViewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

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
        $this->admin = $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
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

    private function putView(Deal $deal, array $data)
    {
        return $this->actingAs($this->admin)->put('http://'.config('xcar.crm_host').'/work/deals/'.$deal->id.'/manager-view', $data);
    }

    public function test_show_and_hide_documents_in_one_window(): void
    {
        Queue::fake();
        [$offer, $deal] = $this->deal();
        $papers = $offer->getMedia('papers')->keyBy('file_name');
        $passport = $papers['Паспорт1.png'];
        $sts = $papers['СТС.pdf'];

        // Из письма в сделку — само, менеджеру — ничего.
        $items = collect(ManagerView::items($deal))->keyBy('key');
        $this->assertSame('Паспорт собственника', $items['file:'.$passport->id]['label']);
        $this->assertFalse($items['file:'.$passport->id]['visible']);
        $this->assertSame('', ManagerView::summary($deal));

        // Показали паспорт и СТС — одним сообщением в чат, у менеджера в документах.
        $this->putView($deal, ['show' => ['file:'.$passport->id, 'file:'.$sts->id]])->assertSessionHasNoErrors()->assertSessionHas('toast');
        $this->assertTrue(\App\Media\ForManagers::is($passport->fresh()));
        $chat = \App\Chats\Chat::where('offer_id', $offer->id)->where('user_id', $this->manager->id)->firstOrFail();
        $said = $chat->messages()->whereNull('deleted_at')->whereHas('files')->latest('id')->firstOrFail();
        $this->assertSame(2, $said->files()->count());
        $this->assertSame('СТС, паспорт собственника', ManagerView::summary($deal->fresh()));
        $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$passport->id}")->assertOk();

        // Скрыли паспорт — у менеджера его нет, сообщение — заново без него.
        $this->putView($deal, ['hide' => ['file:'.$passport->id]])->assertSessionHasNoErrors();
        $this->assertFalse(\App\Media\ForManagers::is($passport->fresh()));
        $this->assertNotNull($said->fresh()->deleted_at);
        $again = $chat->messages()->whereNull('deleted_at')->whereHas('files')->latest('id')->firstOrFail();
        $this->assertSame(1, $again->files()->count());
        $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$passport->id}")->assertNotFound();
        $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$sts->id}")->assertOk();

        // Ничего не выбрано — не сохраняем.
        $this->putView($deal, [])->assertSessionHasErrors('view');
    }

    public function test_letter_opens_same_window_with_its_documents_picked(): void
    {
        Queue::fake();
        [$offer, $deal, $letter] = $this->deal();
        \App\Offers\ReplyReview::create(['message_id' => $letter->id, 'deal_id' => $deal->id, 'offer_id' => $offer->id, 'state' => \App\Offers\ReplyReview::PENDING]);
        $papers = $offer->getMedia('papers')->keyBy('file_name');

        // Окно из письма: документы письма — отмечены (как раньше файлы открытого письма), картинки без вида — нет.
        $html = $this->actingAs($this->admin)->get('http://'.config('xcar.crm_host').'/offers/'.$offer->number)->assertOk()->getContent();
        $this->assertStringContainsString('Показать из письма', $html);
        $this->assertMatchesRegularExpression('/value="file:'.$papers['СТС.pdf']->id.'"[^>]*checked/', $html);

        // Показали из письма — разбор закрыт, менеджер видит.
        $this->putView($deal, ['reviews' => 1, 'show' => ['file:'.$papers['СТС.pdf']->id], 'text' => 'Документы собственника'])->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Offers\ReplyReview::pending()->where('deal_id', $deal->id)->count());
        $this->assertTrue(\App\Media\ForManagers::is($papers['СТС.pdf']->fresh()));
    }

    /** ПТС фото с айфона (.heic, `application/octet-stream`) легло скрытым кадром без письма — переносится в документы письма (25.11.2026). */
    public function test_hidden_letter_photo_moves_to_letter_documents(): void
    {
        Queue::fake();
        [$offer, $deal, $letter] = $this->deal();
        $body = self::png(60);
        $sha = hash('sha256', $body);
        $car = $offer->carRecord();
        $car->addMediaFromString($body)->usingFileName('image-23-09-26-03-33.png')->withCustomProperties(['hidden' => true, 'sha' => $sha])->toMediaCollection('photos');

        \App\Offers\Jobs\ShareReplyFiles::photoToDocument($car, $sha, ['letter' => $letter->id]);
        $moved = $offer->fresh()->getMedia('papers')->first(fn ($m) => $m->getCustomProperty('sha') === $sha);
        $this->assertNotNull($moved);
        $this->assertSame($letter->id, (int) $moved->getCustomProperty('letter'));
        $this->assertNull($moved->getCustomProperty('hidden'));
        $this->assertCount(0, $car->fresh()->getMedia('photos'));
    }

    /**
     * Любое вложение письма — показать менеджеру как есть (25.11.2026, владелец: «почему только одобренный формат?»): .heic с типом
     * «файл», неизвестный .xyz и PDF — все в окне письма, отмечены; показ кладёт их документами машины, менеджер их скачивает.
     */
    public function test_any_letter_attachment_can_be_shown_as_is(): void
    {
        Queue::fake();
        Storage::fake('cache');
        [$offer, $deal, $letter] = $this->deal();
        $files = ['image-23-09-26-03-33.heic' => ['application/octet-stream', 'HEICBYTES'], 'расчёт.xyz' => ['application/octet-stream', 'WHATEVER'], 'птс.pdf' => ['application/pdf', '%PDF-1.4 ptc']];
        $atts = [];
        foreach ($files as $name => [$mime, $body]) {
            $a = \App\Mail\Attachment::create(['message_id' => $letter->id, 'filename' => $name, 'mime' => $mime, 'size' => strlen($body), 'path' => 'att/'.md5($name)]);
            Storage::disk('cache')->put('att/'.md5($name), $body);
            $atts[$name] = $a;
        }
        \App\Offers\ReplyReview::create(['message_id' => $letter->id, 'deal_id' => $deal->id, 'offer_id' => $offer->id, 'state' => \App\Offers\ReplyReview::PENDING]);

        $html = $this->actingAs($this->admin)->get('http://'.config('xcar.crm_host').'/offers/'.$offer->number)->assertOk()->getContent();
        foreach ($atts as $a) {
            $this->assertMatchesRegularExpression('/value="att:'.$a->id.'"[^>]*checked/', $html);
        }

        $this->putView($deal, ['reviews' => 1, 'show' => array_map(fn ($a) => 'att:'.$a->id, array_values($atts))])->assertSessionHasNoErrors();
        foreach ($atts as $name => $a) {
            $m = $offer->fresh()->getMedia('papers')->first(fn ($m) => (int) $m->getCustomProperty('attachment') === $a->id);
            $this->assertNotNull($m, $name);
            $this->assertTrue(\App\Media\ForManagers::is($m));
            $this->assertSame($files[$name][1], file_get_contents($m->getPath()));
            $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$m->id}")->assertOk();
        }
    }
}
