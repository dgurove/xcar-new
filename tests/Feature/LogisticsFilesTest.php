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

    public function test_logist_gets_commission_and_act_executor_never_by_default(): void
    {
        $car = $this->t->car;
        $commission = $car->addMediaFromString('%PDF-1.4 к')->usingFileName('Договор комиссии 483514.pdf')->toMediaCollection('papers');
        $act = $car->addMediaFromString('%PDF-1.4 а')->usingFileName('Акт приема-передачи.pdf')->toMediaCollection('papers');
        $inspection = $car->addMediaFromString('%PDF-1.4 о')->usingFileName('Акт осмотра.pdf')->toMediaCollection('papers');

        foreach ([$commission, $act] as $m) {
            $this->assertTrue(FileAccess::visible($this->t, $this->tanya, $m), $m->file_name.' — логисту по умолчанию');
            $this->assertFalse(FileAccess::visible($this->t, $this->ivan, $m), $m->file_name.' — исполнителю нет');
        }
        $this->assertFalse(FileAccess::visible($this->t, $this->tanya, $inspection));
        $this->actingAs($this->tanya)->get(self::LOG.'/files/'.$commission->id)->assertOk();
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$commission->id)->assertNotFound();
        Auth::logout();
        $this->get(self::LOG.'/trip/'.$this->t->fresh()->trip_code.'/files/'.$act->id)->assertNotFound();
        $this->get(self::LOG.'/trip/'.$this->t->fresh()->trip_code)->assertOk()->assertDontSee($act->file_name);
    }

    public function test_executor_not_let_in_by_other_doors(): void
    {
        // «Видно менеджеру» в CRM — не исполнителю вывоза: его дверь — только глаз логистики.
        $this->paper->setCustomProperty('managers', true)->save();
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$this->paper->id)->assertNotFound();
        // С сайта исполнитель уводится стеной — файла нет и там.
        $this->assertNotSame(200, $this->actingAs($this->ivan)->get('http://xcar.localhost/files/'.$this->paper->id)->status());
        // Скрытый от исполнителя кадр не отдаётся и через /files.
        $this->photo->setCustomProperty('executor', false)->save();
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$this->photo->id)->assertNotFound();

        // Сняли с задачи — открытое раньше больше не открывается.
        $this->paper->setCustomProperty('executor', true)->setCustomProperty('logist', true)->save();
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$this->paper->id)->assertOk();
        $this->t->forceFill(['state' => \App\Logistics\TransportState::Cancelled])->saveQuietly();
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$this->paper->id)->assertNotFound();
    }

    public function test_finished_task_gives_nothing(): void
    {
        // Отметки — у файла машины: открыли следующему отрезку — прошлый исполнитель, что уже привёз, не видит.
        $trip = self::LOG.'/trip/'.$this->t->fresh()->trip_code;
        $this->t->forceFill(['state' => \App\Logistics\TransportState::Delivered])->saveQuietly();
        $this->paper->setCustomProperty('executor', true)->setCustomProperty('logist', true)->save();
        $this->assertFalse(FileAccess::visible($this->t, $this->ivan, $this->paper));
        $this->assertFalse(FileAccess::visible($this->t, $this->tanya, $this->paper));
        $this->assertNull(FileAccess::toggleFor($this->t, $this->tanya));
        $this->actingAs($this->ivan)->get(self::LOG.'/files/'.$this->paper->id)->assertNotFound();
        $this->actingAs($this->tanya)->get(self::LOG.'/files/'.$this->paper->id)->assertNotFound();
        Auth::logout();
        $this->get($trip.'/files/'.$this->paper->id)->assertNotFound();
        $this->get($trip.'/files/'.$this->photo->id)->assertNotFound();
        // Ведущим — всё, как было.
        $this->actingAs($this->admin)->get(self::LOG.'/files/'.$this->paper->id)->assertOk();
    }
}
