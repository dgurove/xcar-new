<?php

namespace Tests\Feature;

use App\Mail\Account;
use App\Mail\Composer;
use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageSent;
use App\Mail\Extraction\Intent;
use App\Mail\Jobs\SendMessage;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Sender;
use App\Mail\SendState;
use App\Notifications\ParkNotice;
use App\Park\Actions\MarkReportSent;
use App\Park\Actions\ReportByLetters;
use App\Park\Actions\SendIntakeReport;
use App\Park\City;
use App\Park\IntakeReport;
use App\Park\Jobs\BuildIntakeReport;
use App\Park\Listeners\ReportDelivery;
use App\Park\Report;
use App\Park\ReportDoc;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Отчёт о приёме вендору (29.10.2026, Никита: «работает через раз, ошибки при отправлении, Альфа не видит вложений»).
 * Письмо без вложения, которое просили, не уходит никогда; документы в отчёте есть и у вендора парковки без своего списка;
 * файлы грузятся по одному, отчёт собирает очередь, шаг «Отчёт» — когда письма ушли, не ушли — «Повторить».
 */
class IntakeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media-library.disk_name'));
        Storage::fake('cache');
        Notification::fake();
        Queue::fake();
        $city = City::main();
        $yard = Yard::create(['name' => 'Краснопутиловская', 'is_active' => true, 'settlement_id' => $city]);
        $crm = Vendor::create(['name' => 'Альфа тест', 'intake_report_docs' => ['act', 'sts']]);
        $park = Vendor::create(['name' => 'Альфа тест СПб']);
        $park->forceFill(['crm_vendor_id' => $crm->id])->save();
        $this->vehicle = Vehicle::create(['vendor_id' => $park->id, 'yard_id' => $yard->id, 'state' => VehicleState::Stored, 'accepted_at' => now()->subHour()]);
        $this->manager = User::create(['name' => 'Никита', 'phone' => '79000000043', 'roles' => [Role::Parking], 'park_city_id' => $city, 'approved_at' => now()]);
        Account::create(['slug' => 'storage', 'title' => 'Парковка', 'email' => 'storage@xcar.test', 'scope' => Scope::Park, 'imap_host' => 'imap.test',
            'imap_username' => 'storage@xcar.test', 'imap_password' => 'x', 'smtp_host' => 'smtp.test', 'smtp_username' => 'storage@xcar.test', 'smtp_password' => 'x']);
    }

    private function url(string $path): string
    {
        return Surface::Park->url("/cars/{$this->vehicle->id}".$path);
    }

    public function test_park_vendor_without_list_takes_crm_vendor_docs(): void
    {
        $this->assertSame([ReportDoc::Act, ReportDoc::Sts], IntakeReport::kinds($this->vehicle->fresh('vendor')));
        $this->assertSame(ReportDoc::StorageAct, ReportDoc::ofName('Акт хранения ТС.pdf'));
        $this->assertSame(ReportDoc::Act, ReportDoc::ofName('акт п/п ГОТС.pdf'));
    }

    public function test_report_goes_through_queue_and_counts_as_sent_only_after_smtp(): void
    {
        // Файлы — по одному сразу при выборе: кадр в «Фото при приёме», две страницы «Снять» одного выбора, файл PDF.
        $photo = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('1000381624.jpg', 1200, 900)])
            ->assertOk()->json('id');
        $pages = collect([0, 1])->map(fn ($p) => $this->actingAs($this->manager)->post($this->url('/report/file'),
            ['kind' => 'act', 'scan' => 'scan-1', 'page' => $p, 'file' => UploadedFile::fake()->image("p{$p}.jpg", 800, 1100)])->assertOk()->json('id'));
        $sts = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'sts', 'file' => UploadedFile::fake()->createWithContent('стс.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF")])
            ->assertOk()->json('id');

        $this->actingAs($this->manager)->post($this->url('/report'), [
            'to' => 'kolbasinamp@alfastrah.test', 'photos' => [$photo], 'docs' => ['act' => $pages->all(), 'sts' => [$sts]],
        ])->assertRedirect();
        $report = Report::sole();
        $this->assertSame(Report::QUEUED, $report->state);
        Queue::assertPushed(BuildIntakeReport::class);
        $this->assertFalse(MarkReportSent::done($this->vehicle), 'шаг «Отчёт» до отправки не ставится');

        // Второй раз, пока первый в пути, — не заводится.
        $this->actingAs($this->manager)->post($this->url('/report'), ['to' => 'kolbasinamp@alfastrah.test', 'photos' => [$photo]]);
        $this->assertSame(1, Report::count());

        (new BuildIntakeReport($report->id))->handle(app(SendIntakeReport::class));
        $report->refresh();
        $this->assertNotNull($report->messages, (string) $report->error);
        $message = Message::with('attachments')->findOrFail($report->messages[0]);
        $names = $message->attachments->pluck('filename')->all();
        // Страницы «Снять» — одним PDF, он встал к ТС вместо снимков.
        $this->assertCount(3, $names, implode(', ', $names));
        $this->assertTrue(collect($names)->contains(fn ($n) => str_contains($n, 'акт') && str_ends_with($n, '.pdf')));
        $this->assertContains('priem-1.jpg', $names);
        $this->assertSame(1, $this->vehicle->fresh()->papers()->where('custom_properties.report_doc', 'act')->count());
        Queue::assertPushed(SendMessage::class, fn ($job) => $job->messageId === $message->id);

        // Ушло по SMTP — отчёт ушёл, шаг сделан.
        $message->forceFill(['send_state' => SendState::Sent])->save();
        MessageSent::dispatch($message);
        $this->assertSame(Report::SENT, $report->fresh()->state);
        $this->assertTrue(MarkReportSent::done($this->vehicle));
    }

    public function test_failed_letter_returns_report_with_retry_and_notice(): void
    {
        $photo = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg', 900, 600)])->json('id');
        $this->actingAs($this->manager)->post($this->url('/report'), ['to' => 'v@alfastrah.test', 'photos' => [$photo]]);
        $report = Report::sole();
        (new BuildIntakeReport($report->id))->handle(app(SendIntakeReport::class));
        $this->assertNotNull($report->fresh()->messages, (string) $report->fresh()->error);
        $message = Message::findOrFail($report->fresh()->messages[0]);

        $message->forceFill(['send_state' => SendState::Failed, 'send_error' => 'SMTP 552 too big'])->save();
        MessageFailed::dispatch($message);
        $this->assertSame(Report::FAILED, $report->fresh()->state);
        $this->assertFalse(MarkReportSent::done($this->vehicle));
        Notification::assertSentTo($this->manager, ParkNotice::class);
        $this->assertStringContainsString('Повторить', $this->actingAs($this->manager)->get($this->url('/window/report'))->getContent());

        $this->actingAs($this->manager)->post($this->url("/report/{$report->id}/retry"))->assertRedirect();
        $this->assertSame(Report::QUEUED, $report->fresh()->state);
        $this->assertSame(SendState::Queued, $message->fresh()->send_state);
    }

    public function test_report_counts_only_when_all_parts_left(): void
    {
        IntakeReport::$partBytes = 1;
        try {
            $ids = collect([600, 700])->map(fn ($h) => $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'photo', 'file' => UploadedFile::fake()->image("a{$h}.jpg", 900, $h)])->json('id'));
            $this->actingAs($this->manager)->post($this->url('/report'), ['to' => 'v@alfastrah.test', 'photos' => $ids->all()]);
            $report = Report::sole();
            (new BuildIntakeReport($report->id))->handle(app(SendIntakeReport::class));
        } finally {
            IntakeReport::$partBytes = 18 * 1024 * 1024;
        }
        $parts = Message::whereKey($report->fresh()->messages)->orderBy('id')->get();
        $this->assertCount(2, $parts);

        // Ушла ч.1 (и почта прочла её как «принята») — шаг ещё не сделан: ч.2 в пути.
        $parts[0]->forceFill(['send_state' => SendState::Sent, 'intent' => Intent::Accepted->value, 'date_at' => now()])->save();
        MessageSent::dispatch($parts[0]);
        app(ReportByLetters::class)($this->vehicle->fresh());
        $this->assertFalse(MarkReportSent::done($this->vehicle));
        $this->assertSame(Report::QUEUED, $report->fresh()->state);

        $parts[1]->forceFill(['send_state' => SendState::Sent])->save();
        MessageSent::dispatch($parts[1]);
        $this->assertSame(Report::SENT, $report->fresh()->state);
        $this->assertTrue(MarkReportSent::done($this->vehicle));
    }

    public function test_dead_build_fails_and_late_job_does_not_build_twice(): void
    {
        $photo = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg', 900, 600)])->json('id');
        $this->actingAs($this->manager)->post($this->url('/report'), ['to' => 'v@alfastrah.test', 'photos' => [$photo]]);
        $report = Report::sole();
        // Очередь стоит долго — отчёт в пути, второй не заводится; часы его не трогают, пока не прошло STALE_MINUTES.
        $this->travel(Report::STALE_MINUTES - 1)->minutes();
        $this->assertSame(0, app(ReportDelivery::class)->settle());
        $this->assertNotNull(Report::inFlight($this->vehicle));

        $this->travel(2)->minutes();
        $this->assertSame(1, app(ReportDelivery::class)->settle());
        $this->assertSame(Report::FAILED, $report->fresh()->state);
        Notification::assertSentTo($this->manager, ParkNotice::class);

        $this->actingAs($this->manager)->post($this->url("/report/{$report->id}/retry"));
        $this->assertSame(2, $report->fresh()->attempt);
        // Первая задача проснулась — отчёт уже не её: писем не собирает.
        (new BuildIntakeReport($report->id, 1))->handle(app(SendIntakeReport::class));
        $this->assertNull($report->fresh()->messages);
        (new BuildIntakeReport($report->id, 2))->handle(app(SendIntakeReport::class));
        $this->assertCount(1, $report->fresh()->messages);
        $this->assertSame(1, Message::count());
    }

    public function test_failed_build_keeps_scan_pages(): void
    {
        $pages = collect([0, 1])->map(fn ($p) => $this->actingAs($this->manager)->post($this->url('/report/file'),
            ['kind' => 'act', 'scan' => 'scan-1', 'page' => $p, 'file' => UploadedFile::fake()->image("p{$p}.jpg", 800, 1100)])->json('id'));
        // Второй документ пропал с диска — сборка падает после того, как PDF акта уже собран.
        $sts = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'sts', 'file' => UploadedFile::fake()->createWithContent('стс.pdf', "%PDF-1.4\n%%EOF")])->json('id');
        $report = Report::create(['vehicle_id' => $this->vehicle->id, 'user_id' => $this->manager->id, 'to' => 'v@alfastrah.test', 'photos' => [],
            'docs' => ['act' => $pages->all(), 'sts' => [$sts]], 'state' => Report::QUEUED, 'attempt' => 1]);
        $this->vehicle->papers()->firstWhere('id', $sts)->delete();

        (new BuildIntakeReport($report->id))->handle(app(SendIntakeReport::class));
        $this->assertSame(Report::FAILED, $report->fresh()->state);
        // Снимки на месте и отчёт на них же — «Повторить» есть из чего собрать; лишнего PDF у ТС нет.
        $this->assertSame($pages->all(), $report->fresh()->docs['act']);
        $this->assertSame(2, $this->vehicle->fresh()->papers()->where('custom_properties.report_doc', 'act')->count());
    }

    public function test_retry_rebuilds_when_letter_file_is_gone(): void
    {
        $photo = $this->actingAs($this->manager)->post($this->url('/report/file'), ['kind' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg', 900, 600)])->json('id');
        $this->actingAs($this->manager)->post($this->url('/report'), ['to' => 'v@alfastrah.test', 'photos' => [$photo]]);
        $report = Report::sole();
        (new BuildIntakeReport($report->id))->handle(app(SendIntakeReport::class));
        $message = Message::with('attachments')->findOrFail($report->fresh()->messages[0]);
        Storage::disk('cache')->delete($message->attachments->first()->path);
        $message->forceFill(['send_state' => SendState::Failed, 'send_error' => 'Нет файла вложения'])->save();
        MessageFailed::dispatch($message);

        $this->actingAs($this->manager)->post($this->url("/report/{$report->id}/retry"));
        $this->assertNull(Message::find($message->id), 'письмо без файла убрано');
        $this->assertNull($report->fresh()->messages);
        Queue::assertPushed(BuildIntakeReport::class, fn ($job) => $job->attempt === 2);
    }

    public function test_letter_never_leaves_without_attachment(): void
    {
        $account = Account::sole();
        try {
            app(Composer::class)->create($account, ['to' => 'v@alfastrah.test', 'subject' => 'Фото', 'body' => '.', 'files' => ['outbox/00000000-0000-0000-0000-000000000000-akt.pdf']], null, $this->manager);
            $this->fail('письмо без загруженного файла заведено');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Message::count());

        $path = 'outbox/'.str_repeat('a', 36).'-akt.pdf';
        Storage::disk('cache')->put($path, '%PDF-1.4 test');
        $message = app(Composer::class)->create($account, ['to' => 'v@alfastrah.test', 'subject' => 'Фото', 'body' => '.', 'files' => [$path]], null, $this->manager);
        Storage::disk('cache')->delete($path);
        $this->expectException(RuntimeException::class);
        app(Sender::class)->build($message->load('attachments', 'account', 'addresses'));
    }
}
