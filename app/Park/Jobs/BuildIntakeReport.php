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
 * Не собралось — отчёт `failed` с причиной, отправлявшему уведомление, в деле «Повторить».
 */
final class BuildIntakeReport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $reportId)
    {
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(SendIntakeReport $send): void
    {
        $report = Report::find($this->reportId);
        if (! $report || $report->state !== Report::QUEUED) {
            return;
        }
        try {
            $send($report);
        } catch (Throwable $e) {
            Log::error('Парковка: отчёт о приёме не собрался', ['report' => $report->id, 'error' => $e->getMessage()]);
            self::fail($report, $e->getMessage());
        }
    }

    /** Не ушло: причина у отчёта и уведомление тому, кто отправлял. */
    public static function fail(Report $report, string $reason): void
    {
        $report->update(['state' => Report::FAILED, 'error' => mb_substr($reason, 0, 2000)]);
        $report->user?->notify(ParkNotice::reportFailed($report->vehicle, $reason));
    }
}
