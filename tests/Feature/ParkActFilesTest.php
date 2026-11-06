<?php

namespace Tests\Feature;

use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Окно приёма и выдачи (05.11.2026, Сергей: «можно было добавлять не только фото, но и файлы (pdf)»): одна кнопка
 * Скрепка «Фото или файл» (рядом камера — документ из фото, `x-ui.photo-pdf`) — кадр ложится в фото шага, PDF — в документы ТС со стадией, и окно показывает файлы своего шага.
 */
class ParkActFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_window_takes_pdf_into_papers_of_its_step(): void
    {
        Storage::fake('public');
        Storage::fake('private');
        $keeper = User::create(['name' => 'Сергей', 'phone' => '79000000046', 'roles' => [Role::Parking], 'approved_at' => now()]);
        $vehicle = Vehicle::create(['vendor_id' => Vendor::create(['name' => 'Абсолют тест'])->id, 'state' => VehicleState::Expected]);
        $this->actingAs($keeper);

        $this->get(Surface::Park->url("/cars/{$vehicle->id}/window/intake"))->assertOk()
            ->assertSee('data-photos-other-value="papers"', false)->assertSee('aria-label="Фото или файл"', false)->assertSee('Документ из фото');

        $pdf = UploadedFile::fake()->create('akt.pdf', 20, 'application/pdf');
        $this->post(Surface::Park->url("/cars/{$vehicle->id}/media"), ['file' => $pdf, 'collection' => 'papers', 'stage' => 'intake', 'files' => 1])
            ->assertOk()->assertSee('akt.pdf');
        $paper = $vehicle->fresh()->papers()->sole();
        $this->assertSame(PhotoStage::Intake->value, $paper->getCustomProperty('stage'));

        // Кадр из той же карточки: ответ рисует её снова со скрепкой, файл шага — под кадрами.
        $this->post(Surface::Park->url("/cars/{$vehicle->id}/media"), ['file' => UploadedFile::fake()->image('car.jpg', 400, 300), 'stage' => 'intake', 'files' => 1])
            ->assertOk()->assertSee('data-photos-other-value="papers"', false)->assertSee('akt.pdf');

        // Документ из дела («+ Документ») — без стадии: в окне шага его нет, в деле есть.
        $this->post(Surface::Park->url("/cars/{$vehicle->id}/media"), ['file' => UploadedFile::fake()->create('pts.pdf', 20, 'application/pdf'), 'collection' => 'papers'])
            ->assertOk()->assertSee('pts.pdf');
        $this->get(Surface::Park->url("/cars/{$vehicle->id}/window/intake"))->assertOk()->assertSee('akt.pdf')->assertDontSee('pts.pdf');
    }
}
