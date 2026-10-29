<?php

namespace App\Park\Listeners;

use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\SendState;
use App\Park\Actions\MarkReportSent;
use App\Park\EventType;
use App\Park\Jobs\BuildIntakeReport;
use App\Park\Report;
use Illuminate\Events\Dispatcher;

/**
 * Отчёт о приёме ушёл — по факту SMTP, а не по нажатию (29.10.2026: шаг «Отчёт» ставился, когда письмо только встало в
 * очередь). Все письма отчёта ушли — отчёт `sent`, шаг «Отчёт» в деле (`MarkReportSent`; уже был — «Фото и документы
 * отправлены», дослали). Письмо не ушло — отчёт `failed` с причиной, уведомление, «Повторить».
 */
final class ReportDelivery
{
    public function __construct(private MarkReportSent $mark) {}

    public function subscribe(Dispatcher $events): array
    {
        return [MessageSent::class => 'sent', MessageFailed::class => 'failed'];
    }

    public function sent(MessageSent $e): void
    {
        $report = Report::ofMessage($e->message);
        if (! $report || $report->state === Report::SENT) {
            return;
        }
        $ids = $report->messages ?? [];
        if (Message::whereKey($ids)->where('send_state', '!=', SendState::Sent->value)->exists()) {
            return;
        }
        $report->update(['state' => Report::SENT, 'sent_at' => now(), 'error' => null]);
        $v = $report->vehicle;
        $mark = ['message' => $ids[0] ?? null, 'photos' => $report->photoCount(), 'docs' => $report->docCount()];
        // Первый отчёт — шаг «Отчёт» (его могло уже отметить само письмо — `ReportByLetters` по «принята»); следующие и
        // ответ на просьбу вендора — «Фото и документы отправлены».
        if (! MarkReportSent::done($v)) {
            ($this->mark)($v, $report->user, 'Акт приёма', now(), $e->message->thread_id, $mark);
        } elseif ($report->parent_id || Report::where('vehicle_id', $v->id)->where('state', Report::SENT)->where('id', '<', $report->id)->exists()) {
            $v->log(EventType::ReportSent, $report->user, ['what' => 'Фото и документы', 'thread' => $e->message->thread_id] + $mark);
        }
    }

    public function failed(MessageFailed $e): void
    {
        $report = Report::ofMessage($e->message);
        if ($report && $report->state !== Report::FAILED) {
            BuildIntakeReport::fail($report, (string) $e->message->send_error);
        }
    }
}
