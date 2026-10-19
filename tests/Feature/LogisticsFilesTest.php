<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Channel;
use App\Logistics\FileAccess;
use App\Logistics\Transport;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Фото и документы машины в логистике (19.10.2026): ведущие глазом решают, что видит логист, логист — что видит исполнитель;
 * по умолчанию кадр виден, документ скрыт. Ошибка здесь — паспорт или оценка с ценой у постороннего водителя.
 */
class LogisticsFilesTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'http://logistics.xcar.localhost';

    private User $admin;

    private User $tanya;

    private User $ivan;

    private Transport $t;

    private Media $photo;

    private Media $paper;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['public', 'media', 'private', 'cache', 'hot'] as $disk) {
            Storage::fake($disk);
        }
        Queue::fake();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $this->ivan = User::create(['name' => 'Иван Петров', 'phone' => '79000000003', 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '483514/2026', 'floor_price' => 400000]);
        $t = app(OpenPickup::class)($offer->fresh(), $this->admin);
        app(AssignExecutor::class)($t, Channel::Logist, $this->tanya, $this->admin);
        $this->t = app(LogistAssign::class)($t->fresh(), ['executor_id' => $this->ivan->id, 'cost' => '65 000'], $this->tanya)->fresh();
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $car = $this->t->car;
        $this->photo = $car->addMediaFromString((string) ob_get_clean())->usingFileName('kadr.jpg')->withCustomProperties(['source' => 'mail'])->toMediaCollection('photos');
        $this->paper = $car->addMediaFromString('%PDF-1.4 оценка')->usingFileName('оценка ГОТС.pdf')->toMediaCollection('papers');
    }

    public function test_defaults_photos_visible_papers_hidden(): void
    {
        $this->assertTrue(FileAccess::visible($this->t, $this->tanya, $this->photo));
        $this->assertFalse(FileAccess::visible($this->t, $this->tanya, $this->paper));
        $this->assertTrue(FileAccess::visible($this->t, $this->admin, $this->paper));

        $this->actingAs($this->tanya)->get(self::LOG.'/files/'.$this->paper->id)->assertNotFound();
        $this->actingAs($this->admin)->get(self::LOG.'/transports?preset=all&peek='.$this->t->id, ['Turbo-Frame' => 'detail'])->assertOk()
            ->assertSee($this->paper->file_name)->assertSee('Видит логист');
    }

    public function test_chain_lead_to_logist_to_executor(): void
    {
        // Логист не может решать за себя.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$this->t->id.'/files/'.$this->paper->id.'/eye')->assertNotFound();

        // Админ открыл документ логисту.
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$this->t->id.'/files/'.$this->paper->id.'/eye')->assertOk();
        $this->paper->refresh();
        $this->assertTrue(FileAccess::visible($this->t, $this->tanya, $this->paper));
        $this->assertFalse(FileAccess::visible($this->t, $this->ivan, $this->paper));
        $this->actingAs($this->tanya)->get(self::LOG.'/files/'.$this->paper->id)->assertOk();

        // Логист открыл исполнителю — он видит и по ссылке без входа.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$this->t->id.'/files/'.$this->paper->id.'/eye')->assertOk();
        $this->paper->refresh();
        $this->assertTrue(FileAccess::visible($this->t, $this->ivan, $this->paper));
        Auth::logout();
        $trip = self::LOG.'/trip/'.$this->t->fresh()->trip_code;
        $this->get($trip)->assertOk()->assertSee($this->paper->file_name);
        $this->get($trip.'/files/'.$this->paper->id)->assertOk();

        // Админ закрыл логисту — исполнитель через логиста тоже не видит.
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$this->t->id.'/files/'.$this->paper->id.'/eye')->assertOk();
        $this->paper->refresh();
        $this->assertFalse(FileAccess::visible($this->t, $this->ivan, $this->paper));
        Auth::logout();
        $this->get($trip.'/files/'.$this->paper->id)->assertNotFound();

        // Кадр глазом ряда: логист скрыл исполнителю.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$this->t->id.'/files/'.$this->photo->id.'/hide')->assertOk();
        $this->assertFalse(FileAccess::visible($this->t, $this->ivan, $this->photo->refresh()));
        $this->assertTrue(FileAccess::visible($this->t, $this->tanya, $this->photo));
    }
}
