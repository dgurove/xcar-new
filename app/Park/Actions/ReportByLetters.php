<?php

namespace App\Park\Actions;

use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Park\EventType;
use App\Park\Vehicle;
use Illuminate\Support\Collection;

/**
 * Отчёт о приёме, отправленный не из приложения (12.10.2026, Z691/046/11600/26: «Re: заявка на приём» с актом и фото
 * ушла из почтовой программы, а дело всё равно просило «Нужно отправить отчёт»): наше письмо со смыслом
 * `Intent::Accepted` в ветках парковки ТС, не раньше дня приёма, делает шаг — `ReportSent` «Акт приёма», бумаги вендору
 * «отправлено» тем письмом. Пара к `ReleaseByLetters` для выдачи. Зовут `Mail\OnMessage` (наше письмо в ветку ТС —
 * синхронизацией и из приложения) и `park:tick` (письмо ушло раньше, чем записали приём).
 */
final class ReportByLetters
{
    public function __construct(private MarkReportSent $mark) {}

    /** Письмо, которым отчёт о приёме уже отправлен, — или null (отчёт не нужен, уже отмечен или письма нет). */
    public function letter(Vehicle $vehicle): ?Message
    {
        if (! $vehicle->accepted_at || ! ($vehicle->vendor?->kind->billable() ?? false) || $this->done($vehicle)) {
            return null;
        }

        return Message::whereIn('thread_id', Thread::park()->where('vehicle_id', $vehicle->id)->select('id'))
            ->where('intent', Intent::Accepted->value)->where('date_at', '>=', $vehicle->accepted_at->copy()->startOfDay())
            ->orderByDesc('date_at')->get()->first(fn (Message $m) => $m->isOurs());
    }

    public function __invoke(Vehicle $vehicle): ?Message
    {
        if (! ($letter = $this->letter($vehicle))) {
            return null;
        }
        ($this->mark)($vehicle, null, 'Акт приёма', $letter->date_at, $letter->thread_id, ['message' => $letter->id, 'by_letters' => true]);

        return $letter;
    }

    /** @return Collection<int, Vehicle> принятые ТС с нашим письмом о приёме в ветках и без отметки отчёта */
    public function suspects(): Collection
    {
        return Vehicle::with('vendor')->whereNotNull('accepted_at')
            ->whereIn('id', Thread::park()->whereNotNull('vehicle_id')
                ->whereIn('id', Message::where('intent', Intent::Accepted->value)->where('direction', 'out')->select('thread_id'))->select('vehicle_id'))
            // Отмеченные — запросом, а не по запросу на ТС: часы гоняли 558 запросов каждые 5 минут.
            ->whereDoesntHave('events', fn ($e) => $e->where('type', EventType::ReportSent)->whereRaw("payload->>'what' = ?", ['Акт приёма']))
            ->get();
    }

    /** Все подходящие — `park:tick`. @return int сколько отчётов отмечено */
    public function all(): int
    {
        return $this->suspects()->filter(fn (Vehicle $v) => ($this)($v) !== null)->count();
    }

    private function done(Vehicle $vehicle): bool
    {
        return MarkReportSent::done($vehicle);
    }
}
