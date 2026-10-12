<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\Actions\MarkReportSent;
use App\Park\Actions\SendIntakeReport;
use App\Park\IntakeReport;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Отчёт о приёме (12.10.2026): ответ в ветке заявки, фото JPEG-ами, сканы с именами «Альфа акт … 0367.pdf», частями
 * по объёму, шаг «Отчёт» сделан. Ошибка молчалива: вендор не получил документы, а дело считает отчёт отправленным.
 */
class IntakeReportTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(400, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), 120, 60));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /** @return array{0: Vehicle, 1: User} */
    private function vehicle(): array
    {
        foreach (['public', 'media', 'private', 'cache', 'hot'] as $disk) {
            Storage::fake($disk);
        }
        Queue::fake();
        $user = User::create(['name' => 'Управляющий', 'phone' => '79000000011', 'roles' => [Role::ParkAdmin, Role::Admin], 'approved_at' => now()]);
        // Миграции заводят Альфу сами — та же строка, документы отчёта задаём явно.
        $vendor = Vendor::firstOrCreate(['name' => 'АльфаСтрахование'], ['kind' => Kind::Insurer]);
        $vendor->forceFill(['kind' => Kind::Insurer, 'on_park' => true, 'intake_report_docs' => ['act', 'sts']])->save();
        $account = Account::create(['slug' => 'storage', 'title' => 'Парковка', 'email' => 'storage@xcar.test', 'scope' => 'park',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $vehicle = Vehicle::create(['vendor_id' => $vendor->id, 'vin' => 'LS5A2ASE6PD930367', 'ref' => '785R/046/00062/26', 'state' => VehicleState::Stored, 'accepted_at' => now()]);
        $thread = Thread::create(['account_id' => $account->id, 'subject' => 'заявка на прием ТС 785R/046/00062/26', 'vehicle_id' => $vehicle->id]);
        Message::create(['account_id' => $account->id, 'thread_id' => $thread->id, 'direction' => Direction::In, 'subject' => 'заявка на прием ТС 785R/046/00062/26',
            'from_email' => 'kolbasinamp@alfastrah.test', 'date_at' => now()->subDay(), 'text_body' => 'Прошу принять ТС.']);

        return [$vehicle, $user];
    }

    public function test_report_goes_as_reply_with_scans_named_like_by_hand_and_marks_step(): void
    {
        [$v, $user] = $this->vehicle();
        $photo = $v->addMediaFromString($this->jpeg())->usingFileName('kadr.jpg')->withCustomProperties(['stage' => 'intake', 'source' => 'app'])->toMediaCollection('photos');
        $act = $v->addMediaFromString('%PDF-1.4 act')->usingFileName('Альфа акт 1 чанган алсвин 0367.pdf')->toMediaCollection('papers');

        $report = IntakeReport::for($v->fresh());
        $this->assertSame('kolbasinamp@alfastrah.test', $report->to);
        $this->assertSame([$act->id], $report->slots['act']->pluck('id')->all(), 'скан с «акт» в имени встаёт в слот акта');
        $this->assertTrue($report->slots['sts']->isEmpty());

        $sent = app(SendIntakeReport::class)($report, $user, [$photo->id], ['act' => [$act->id]], '', $report->to);
        $this->assertCount(1, $sent);
        $m = $sent[0]->fresh('attachments');
        $this->assertStringStartsWith('Re: заявка на прием', $m->subject);
        $this->assertStringContainsString('скан-копии документов', (string) $m->html_body);
        $this->assertSame(['Альфа акт 0367.pdf', 'priem-1.jpg'], $m->attachments->pluck('filename')->all());
        $this->assertTrue(MarkReportSent::done($v));
    }

    public function test_large_report_splits_into_parts_with_scans_first(): void
    {
        [$v, $user] = $this->vehicle();
        $photos = collect(range(1, 3))->map(fn () => $v->addMediaFromString($this->jpeg())->usingFileName('kadr.jpg')->withCustomProperties(['stage' => 'intake'])->toMediaCollection('photos')->id)->all();
        $act = $v->addMediaFromString('%PDF-1.4 '.str_repeat('a', 2000))->usingFileName('акт.pdf')->toMediaCollection('papers');
        IntakeReport::$partBytes = 2500;
        try {
            $sent = app(SendIntakeReport::class)(IntakeReport::for($v->fresh()), $user, $photos, ['act' => [$act->id]], '', 'kolbasinamp@alfastrah.test');
        } finally {
            IntakeReport::$partBytes = 18 * 1024 * 1024;
        }
        $this->assertGreaterThan(1, count($sent));
        $this->assertStringEndsWith(' ч.1', $sent[0]->subject);
        $this->assertSame('Альфа акт 0367.pdf', $sent[0]->attachments()->first()->filename);
        $this->assertSame(4, collect($sent)->sum(fn ($m) => $m->attachments()->count()));
    }
}
