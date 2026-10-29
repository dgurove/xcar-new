<?php

namespace App\Park\Listeners;

use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\SendState;
use App\Notifications\ParkNotice;
use App\Park\Actions\MarkReportSent;
use App\Park\EventType;
use App\Park\Jobs\BuildIntakeReport;
use App\Park\Report;
use Illuminate\Events\Dispatcher;

/**
 * Отчёт о приёме ушёл — по факту SMTP, а не по нажатию (29.10.2026: шаг «Отчёт» ставился, когда письмо только встало в
 * очередь). Все письма отчёта ушли — отчёт `sent`, шаг «Отчёт» в деле (`MarkReportSent`; уже был — «Фото и документы
 * отправлены», дослали) — когда ушли все части, а не первая. Письмо не ушло — отчёт `failed` с причиной, уведомление,
 * «Повторить».
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
        if ($report = Report::ofMessage($e->message)) {
            $this->check($report);
        }
    }

    /** Часть не ушла — отчёт `failed`, когда остальные части уже не в пути: «Повторить» не шлёт идущее второй раз. */
    public function failed(MessageFailed $e): void
    {
        if ($report = Report::ofMessage($e->message)) {
            $this->check($report);
        }
    }

    /**
     * `park:tick`: событие потерялось (упал другой слушатель, выкладка) — отчёт по его письмам; не собрался за
     * `STALE_MINUTES` — задача сборки умерла: «не ушёл, Повторить» (её опоздавшая копия отчёт уже не возьмёт, `claim`).
     *
     * @return int сколько отчётов решено
     */
    public function settle(): int
    {
        $n = 0;
        foreach (Report::where('state', Report::QUEUED)->where('updated_at', '<', now()->subMinutes(Report::STALE_MINUTES))->get() as $report) {
            if ($report->messages) {
                $n += (int) ($this->check($report) !== Report::QUEUED);
            } elseif (Report::whereKey($report->id)->where('state', Report::QUEUED)->whereNull('messages')->where('updated_at', $report->updated_at)
                ->update(['state' => Report::FAILED, 'error' => 'Отчёт не собрался — повторите', 'updated_at' => now()])) {
                $report->user?->notify(ParkNotice::reportFailed($report->vehicle, 'отчёт не собрался — повторите'));
                $n++;
            }
        }

        return $n;
    }

    /** Отчёт по его письмам: все ушли — `sent` и шаг; одно не ушло, а остальные уже не в пути — `failed`. @return string состояние */
    private function check(Report $report): string
    {
        if ($report->state !== Report::QUEUED) {
            return $report->state;
        }
        $ids = $report->messages ?? [];
        $states = Message::whereKey($ids)->pluck('send_state')->map(fn (SendState $s) => $s->value);
        if ($states->isEmpty() || $states->contains(fn ($s) => in_array($s, [SendState::Queued->value, SendState::Sending->value], true))) {
            return Report::QUEUED;
        }
        if ($states->contains(SendState::Failed->value)) {
            BuildIntakeReport::fail($report, (string) Message::whereKey($ids)->where('send_state', SendState::Failed->value)->value('send_error'));

            return Report::FAILED;
        }
        // Два письма ушли разом — отмечает тот, кто первым перевёл отчёт.
        if (! Report::whereKey($report->id)->where('state', Report::QUEUED)->update(['state' => Report::SENT, 'sent_at' => now(), 'error' => null, 'updated_at' => now()])) {
            return Report::SENT;
        }
        $v = $report->vehicle;
        $thread = Message::whereKey($ids[0])->value('thread_id');
        $mark = ['message' => $ids[0], 'photos' => $report->photoCount(), 'docs' => $report->docCount()];
        // Первый отчёт — шаг «Отчёт» (его могло уже отметить письмо из почтовой программы — `ReportByLetters`); следующие и
        // ответ на просьбу вендора — «Фото и документы отправлены».
        if (! MarkReportSent::done($v)) {
            ($this->mark)($v, $report->user, 'Акт приёма', now(), $thread, $mark);
        } elseif ($report->parent_id || Report::where('vehicle_id', $v->id)->where('state', Report::SENT)->where('id', '<', $report->id)->exists()) {
            $v->log(EventType::ReportSent, $report->user, ['what' => 'Фото и документы', 'thread' => $thread] + $mark);
        }

        return Report::SENT;
    }
}
