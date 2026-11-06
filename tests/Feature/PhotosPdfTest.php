<?php

namespace Tests\Feature;

use App\Mail\Parts;
use App\Park\City;
use App\Park\ReportDoc;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Документ из фото (06.11.2026, Сергей: «проще сфоткать акт, чем сканить»): снимки одним PDF — по странице на снимок, под
 * названием с номером убытка; в бумаги ТС видом слота отчёта и во вложения письма.
 */
class PhotosPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media-library.disk_name'));
        Storage::fake(Parts::CACHE_DISK);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin, Role::ParkAdmin], 'approved_at' => now()]);
        $yard = Yard::create(['name' => 'Дорофеева', 'is_active' => true, 'settlement_id' => City::main()]);
        $this->vehicle = Vehicle::create(['yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => now()->subDay(),
            'vin' => 'XW8ZZZ61ZHG035220', 'ref' => '0760/046/02677/26', 'category' => 'passenger']);
    }

    private function pages(int $n): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("IMG_{$i}.jpg", 900, 1200), range(1, $n));
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_three_photos_become_one_named_pdf_in_vehicle_papers(): void
    {
        $r = $this->actingAs($this->admin)->post(Surface::Park->url('/cars/'.$this->vehicle->id.'/report/pdf'),
            ['pages' => $this->pages(3), 'name' => 'Акт приёма', 'kind' => ReportDoc::Act->value]);
        $r->assertOk()->assertJson(['name' => 'Акт приёма 0760-046-02677-26 VIN XW8ZZZ61ZHG035220.pdf', 'pages' => 3]);
        $m = $this->vehicle->fresh()->getMedia('papers')->firstWhere('id', $r->json('id'));
        $this->assertSame(ReportDoc::Act->value, $m->getCustomProperty('report_doc'));
        $this->assertSame('Акт приёма 0760-046-02677-26 VIN XW8ZZZ61ZHG035220.pdf', $m->getCustomProperty('title'));
        $this->assertSame(3, self::pageCount((string) file_get_contents($m->getPath())));
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->admin)->postJson(Surface::Park->url('/cars/'.$this->vehicle->id.'/report/pdf'),
            ['pages' => $this->pages(1), 'name' => '', 'kind' => ReportDoc::Act->value])
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Как назвать документ?');
    }

    public function test_one_photo_to_mail_attachment(): void
    {
        $r = $this->actingAs($this->admin)->post(Surface::Park->url('/mail/file/pdf'),
            ['pages' => $this->pages(1), 'name' => 'Акт выдачи', 'vehicle' => $this->vehicle->id]);
        $r->assertOk()->assertJson(['name' => 'Акт выдачи 0760-046-02677-26 VIN XW8ZZZ61ZHG035220.pdf', 'pages' => 1]);
        $this->assertStringContainsString('name="files[]"', $r->json('html'));
        preg_match('#value="(outbox/[^"]+)"#', $r->json('html'), $path);
        $this->assertSame(1, self::pageCount(Storage::disk(Parts::CACHE_DISK)->get($path[1])));
    }
}
