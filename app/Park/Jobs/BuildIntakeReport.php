<?php

namespace App\Park\Jobs;

use App\Notifications\ParkNotice;
use App\Park\Actions\SendIntakeReport;
use App\Park\Report;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Письма отчёта о приёме собираются здесь, а не в запросе формы (29.10.2026): JPEG-и кадров, PDF сканов, части по 18 МБ.
 * Не собралось — отчёт `failed` с причиной, отправлявшему уведомление, в деле «Повторить». Задача несёт номер сборки
 * (`attempt`): опоздавшая (очередь стояла, отчёт уже повторили) отчёт не берёт.
 */
final class BuildIntakeReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $reportId, public int $attempt = 1)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(SendIntakeReport $send): void
    {
        $report = Report::find($this->reportId);
        if (! $report?->claim($this->attempt)) {
            return;
        }
        try {
            $send($report);
        } catch (Throwable $e) {
            Log::error('Парковка: отчёт о приёме не собрался', ['report' => $report->id, 'error' => $e->getMessage()]);
            self::fail($report, $e->getMessage());
        }
    }

    /** Не ушло: причина у отчёта и уведомление тому, кто отправлял, — один раз, даже если не ушли две части разом. */
    public static function fail(Report $report, string $reason): void
    {
        $reason = trim($reason) ?: 'причина неизвестна';
        if (! Report::whereKey($report->id)->where('state', Report::QUEUED)->update(['state' => Report::FAILED, 'error' => mb_substr($reason, 0, 2000), 'updated_at' => now()])) {
            return;
        }
        $report->forceFill(['state' => Report::FAILED, 'error' => mb_substr($reason, 0, 2000)])->syncOriginal();
        $report->user?->notify(ParkNotice::reportFailed($report->vehicle, $reason));
    }
}
