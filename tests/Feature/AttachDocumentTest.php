<?php

namespace Tests\Feature;

use App\Media\DocumentRefused;
use App\Media\PhotosPdf;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Pdf;
use App\Support\PdfJoin;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Приложить» одним документом (24.11.2026, владелец: «из галереи — объединять в PDF, документ PDF — оставить и переименовать»;
 * Иван Орлов, сделка 351: договор фотками с айфона не прикладывался). Молчаливо и дорого: страница потерялась при склейке,
 * PDF пересобран и испорчен, договор лёг не туда или отметка «подписан» упала на втором файле.
 */
class AttachDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(int $pages, string $name = 'Квитанция.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tpdf-');
        file_put_contents($path, Pdf::render('park.report.scan', ['pages' => array_map(fn () => 'data:image/gif;base64,R0lGODlhAQABAAAAACw=', range(1, $pages))]));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_one_pdf_stays_as_is_and_photos_with_pdf_join_in_order(): void
    {
        $photos = app(PhotosPdf::class);
        $one = $this->pdf(2);
        $doc = $photos->document([$one]);
        $this->assertSame(file_get_contents($one->getRealPath()), $doc['body'], 'один PDF — байт в байт');
        $this->assertSame(2, $doc['pages']);

        if (! PdfJoin::available()) {
            $this->markTestSkipped('нет pdfunite');
        }
        $doc = $photos->document([UploadedFile::fake()->image('1.jpg', 900, 1200), $this->pdf(2), UploadedFile::fake()->image('2.jpg', 900, 1200)]);
        $this->assertSame(4, $doc['pages']);
        $this->assertSame('pdf', $doc['ext']);
        $path = tempnam(sys_get_temp_dir(), 'out-');
        file_put_contents($path, $doc['body']);
        $this->assertSame(4, PdfJoin::pages($path), 'фото, две страницы PDF, фото — четыре страницы');

        $this->expectException(DocumentRefused::class);
        $photos->document([UploadedFile::fake()->create('ДКП.docx', 20), UploadedFile::fake()->image('1.jpg')]);
    }

    public function test_contract_from_several_photos_is_one_document_named_by_car(): void
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван Орлов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, scheme: DealScheme::Prime);

        $pages = array_map(fn ($i) => UploadedFile::fake()->image("IMG_064{$i}.jpg", 900, 1200), range(4, 7));
        $r = $this->actingAs($manager)->post(Surface::Site->url('/deals/'.$deal->id.'/signed/doc'), ['pages' => $pages, 'name' => 'Договор купли-продажи '.$offer->title()]);
        $r->assertOk()->assertJson(['reload' => true, 'pages' => 4]);
        $media = $deal->fresh()->getMedia('signed_contract');
        $this->assertCount(1, $media, 'четыре фото — один документ');
        $this->assertSame('application/pdf', $media[0]->mime_type);
        $this->assertSame('Договор купли-продажи '.$offer->title(), $media[0]->getCustomProperty('title'));
        $this->assertStringNotContainsString((string) $offer->claim_ref ?: '—', $media[0]->file_name);
        $this->assertTrue($deal->fresh()->contractSigned());

        // Ещё раз — ложится вторым документом, отметка не падает.
        $this->actingAs($manager)->post(Surface::Site->url('/deals/'.$deal->id.'/signed/doc'), ['pages' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')], 'name' => 'Договор купли-продажи'])
            ->assertOk();
        $this->assertCount(2, $deal->fresh()->getMedia('signed_contract'));

        // Word с фото — словами, ничего не легло.
        $this->actingAs($manager)->postJson(Surface::Site->url('/deals/'.$deal->id.'/signed/doc'), ['pages' => [UploadedFile::fake()->create('ДКП.docx', 20), UploadedFile::fake()->image('c.jpg')], 'name' => 'Договор'])
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Word прикладывается отдельно: уберите фото или Word');
        $this->assertCount(2, $deal->fresh()->getMedia('signed_contract'));
    }
}
