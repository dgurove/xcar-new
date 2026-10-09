<?php

namespace App\Park;

use App\Park\Request as ParkRequest;
use Illuminate\Support\Collection;

/**
 * Дела дня одной функцией — утреннему дайджесту владельцу (`park:digest`). Экрана «Сегодня» нет:
 * те же дела на `/requests` пресетами (типы) и на `/cars` (Бумаги вендору · Стоят долго).
 * Секции: Принять · Выдать · Ждут без заявки · Отправить вендору · Стоят долго.
 * Пустые не отдаются.
 *
 * @phpstan-type Section array{0: string, 1: Collection, 2: string}
 */
final class Today
{
    /** @return array{sections: list<Section>, stored: int} */
    public static function build(): array
    {
        $open = ParkRequest::query()->whereIn('state', RequestState::open())->with('vehicle')->oldest()->get();
        $intake = $open->filter(fn (ParkRequest $r) => $r->type === RequestType::Intake);
        $release = $open->filter(fn (ParkRequest $r) => $r->type === RequestType::Release);

        // Ждут без заявки — отменили заявку и забыли: заявка заводится с ТС.
        $orphans = Vehicle::where('state', VehicleState::Expected)->whereDoesntHave('requests', fn ($q) => $q->whereIn('state', RequestState::open()))->get();
        $stored = Vehicle::where('state', VehicleState::Stored)->with('docs')->get();
        $idle = $stored->filter(fn (Vehicle $v) => ($v->daysStored() ?? 0) >= Idle::warn());
        $docsDue = $stored->filter(fn (Vehicle $v) => $v->docsPending() && $v->accepted_at && $v->accepted_at->lt(now()->subDays(self::docsDays())));

        return [
            'stored' => $stored->count(),
            'sections' => array_values(array_filter([
                ['Принять', $intake, '/requests?preset=intake'],
                ['Выдать', $release, '/requests?preset=release'],
                ['Ждут без заявки', $orphans, '/cars?state=expected'],
                ['Отправить вендору', $docsDue, '/cars'],
                ['Стоят долго', $idle, '/cars'],
            ], fn ($s) => $s[1]->isNotEmpty())),
        ];
    }

    /** Через сколько дней после приёма неотправленные бумаги вендору — дело дня. */
    public static function docsDays(): int
    {
        return (int) config('xcar.park_docs_days', 3);
    }
}
