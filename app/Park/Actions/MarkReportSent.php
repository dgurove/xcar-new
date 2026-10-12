<?php

namespace App\Park\Actions;

use App\Park\DocState;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Support\Carbon;

/**
 * Отчёт вендору отправлен: шаг «Отчёт» в деле сделан (`ReportSent`), бумаги вендору — «отправлено». Одна дверь для формы
 * отчёта (`SendIntakeReport`), нашего письма из почтовой программы (`ReportByLetters`) и «Уже отправили» — отчёт ушёл с
 * личной почты, письма у нас нет.
 */
final class MarkReportSent
{
    public function __construct(private MarkDoc $markDoc) {}

    /** @param array<string, mixed> $mark — что записать в событие (ветка, письмо, `manual`, `by_letters`) */
    public function __invoke(Vehicle $vehicle, ?User $by, string $what = 'Акт приёма', ?Carbon $at = null, ?int $threadId = null, array $mark = []): void
    {
        foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->get() as $doc) {
            ($this->markDoc)($doc, $by, DocState::Sent, $at, null, $threadId);
        }
        $vehicle->log(EventType::ReportSent, $by, array_filter(['what' => $what, 'thread' => $threadId]) + $mark);
    }

    public static function done(Vehicle $vehicle, string $what = 'Акт приёма'): bool
    {
        return $vehicle->events()->where('type', EventType::ReportSent)->whereRaw("payload->>'what' = ?", [$what])->exists();
    }
}
