<?php

namespace Tests\Feature;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Users\Role;
use App\Users\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Скан паспорта одним PDF (19.10.2026, владелец): две страницы — разворот с фото и прописка — ложатся на обе плитки,
 * на какую плитку ни бросили; одна страница — как раньше, на свою.
 */
class PassportPdfTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(int $pages): UploadedFile
    {
        $pdf = new Dompdf;
        $pdf->loadHtml(implode('<div style="page-break-before:always"></div>', array_map(fn ($i) => '<h1>Страница '.$i.'</h1>', range(1, $pages))));
        $pdf->render();
        $path = tempnam(sys_get_temp_dir(), 'pp').'.pdf';
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, 'passport.pdf', 'application/pdf', null, true);
    }

    public function test_two_page_pdf_fills_both_pages(): void
    {
        Storage::fake('private');
        Queue::fake();
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $buyer = User::create(['name' => 'Покупатель', 'phone' => '79000000003', 'roles' => [Role::Buyer], 'manager_id' => $manager->id, 'approved_at' => now()]);
        $party = Party::create(['kind' => PartyKind::Person, 'name' => 'Покупатель']);
        $buyer->forceFill(['party_id' => $party->id])->save();

        $this->actingAs($manager)->post('http://xcar.localhost/parties/'.$party->id.'/passport/reg', ['file' => $this->pdf(2)])->assertOk()
            ->assertJsonPath('pages.main.state', 'reading')->assertJsonPath('pages.reg.state', 'reading');
        $party->refresh();
        $this->assertStringStartsWith('image/', $party->passportScan('main')->mime_type);
        $this->assertStringStartsWith('image/', $party->passportScan('reg')->mime_type);
        Queue::assertPushed(ReadPassport::class, 2);

        // Одна страница — только на свою плитку, разворот остаётся прежним.
        $main = $party->passportScan('main')->id;
        $this->actingAs($manager)->post('http://xcar.localhost/parties/'.$party->id.'/passport/reg', ['file' => $this->pdf(1)])->assertOk();
        $party->refresh();
        $this->assertSame($main, $party->passportScan('main')->id);
        $this->assertSame('application/pdf', $party->passportScan('reg')->mime_type);
    }
}
