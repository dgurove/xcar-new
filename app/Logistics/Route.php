<?php

namespace App\Logistics;

use App\Logistics\Events\TransportAssigned;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Маршрут вывоза из отрезков (20.10.2026, владелец: «в регионе до стоянки, потом межрегион, потом стоянка, потом пункт
 * назначения — и у каждого отрезка свой исполнитель»). Отрезок — строка `transports` со своим исполнителем, ходом, ссылкой
 * `/trip`, ценой логисту и историей; маршрут — отрезки с одним `route_id` (id первого) по `seq`. Между отрезками машина
 * стоит на стоянке логиста: «куда» у промежуточного — стоянка (`Point::Depot`), «откуда» у следующего — она же
 * (`from_depot_id`). Цель маршрута — «куда» последнего отрезка.
 *
 * Ведёт маршрут один логист (владелец): ставит на каждый отрезок своего исполнителя и цену, заранее или по ходу. Отрезок,
 * до которого не дошла очередь, — `Planned`: исполнитель его не видит и ничего не получает, пока машину не привезли на
 * стоянку перед ним; тогда `sync` открывает его («Назначен» или «У логиста») и шлёт поручение.
 *
 * Текущий отрезок — `current`, ровно один на маршрут: идущий, а у законченного — последний. По нему строка таблицы «Вывоз»
 * (строка на машину), вкладки, CRM и xcar.ru.
 */
final class Route
{
    /** Отрезки маршрута по порядку. @return Collection<int, Transport> */
    public static function legs(Transport $t): Collection
    {
        return Transport::where('route_id', $t->route_id ?? $t->id)->orderBy('seq')->get();
    }

    /** Текущий отрезок маршрута. */
    public static function current(Transport $t): Transport
    {
        if ($t->current) {
            return $t;
        }

        return Transport::where('route_id', $t->route_id ?? $t->id)->where('current', true)->first() ?? $t;
    }

    /** Отрезок за этим. */
    public static function after(Transport $t): ?Transport
    {
        return Transport::where('route_id', $t->route_id)->where('seq', '>', $t->seq)->orderBy('seq')->first();
    }

    /** Последний ли отрезок: его «куда» — цель маршрута. */
    public static function isLast(Transport $t): bool
    {
        return ! Transport::where('route_id', $t->route_id)->where('seq', '>', $t->seq)->exists();
    }

    /** Отрезок ещё не начался: машину не забрали — его можно менять, делить стоянкой и удалять. */
    public static function untouched(Transport $t): bool
    {
        return $t->state === TransportState::Planned || ($t->isOpen() && ! $t->state->picked() && $t->agreed_at === null);
    }

    /**
     * Пересчитать маршрут: первый незакрытый отрезок — текущий; запланированный, перед которым машину уже привезли на
     * стоянку, открывается — «Назначен», если исполнитель уже стоит (ему — поручение), иначе «У логиста». Все закрыты —
     * текущий последний. Зовут «Привёз», откат, вставка и удаление стоянки, отмена.
     */
    public static function sync(int $routeId, ?User $by = null): ?Transport
    {
        $opened = null;
        $current = DB::transaction(function () use ($routeId, &$opened) {
            $legs = Transport::where('route_id', $routeId)->orderBy('seq')->lockForUpdate()->get()->values();
            $current = null;
            foreach ($legs as $i => $leg) {
                if (in_array($leg->state, [TransportState::Delivered, TransportState::Cancelled], true)) {
                    continue;
                }
                if ($leg->state === TransportState::Planned) {
                    // Сюда доходим, только когда все отрезки перед ним закрыты: привезли — его очередь, отменили — маршрут
                    // встал, отрезок не открываем.
                    if (($legs[$i - 1] ?? null)?->state === TransportState::Cancelled) {
                        break;
                    }
                    $leg->fill([
                        'state' => $leg->executor_id ? TransportState::Assigned : ($leg->logist_id ? TransportState::Logist : TransportState::New),
                        // С этой минуты отрезок ждёт людей: от неё «зависший» считает сутки (`logistics:tick`).
                        'assigned_at' => now(),
                    ])->save();
                    $opened = $leg;
                }
                $current = $leg;
                break;
            }
            // Все закрыты — текущий последний не запланированный (законченный или отменённый маршрут).
            $current ??= $legs->reverse()->first(fn (Transport $l) => $l->state !== TransportState::Planned) ?? $legs->first();
            if ($current) {
                Transport::where('route_id', $routeId)->whereKeyNot($current->id)->where('current', true)->update(['current' => false]);
                Transport::whereKey($current->id)->where('current', false)->update(['current' => true]);
                $current->current = true;
            }

            return $current;
        });
        // Очередь дошла — исполнителю (или логисту) поручение, как при назначении.
        if ($opened) {
            DB::afterCommit(fn () => TransportAssigned::dispatch($opened, null));
        }

        return $current;
    }

    /**
     * Последний отрезок привезли на стоянку логиста — маршрут не кончился: машина ждёт следующего отрезка «Пока неизвестно
     * куда» («по ходу», 20.10.2026: стоянку не всегда планируют заранее). Заводит его «Впереди»; `sync` тут же откроет его
     * логисту.
     */
    public static function continueAt(Transport $leg, ?User $by = null): ?Transport
    {
        if ($leg->destination !== Point::Depot || ! $leg->depot || ! self::isLast($leg)) {
            return null;
        }
        $depot = $leg->depot;
        $next = Transport::create([
            'car_id' => $leg->car_id, 'origin' => $leg->origin, 'kind' => TransportKind::Move, 'channel' => Channel::Logist,
            'logist_id' => $leg->logist_id, 'state' => TransportState::Planned, 'route_id' => $leg->route_id, 'seq' => $leg->seq + 1,
            'current' => false, 'previous_id' => $leg->id,
            'from_depot_id' => $depot->id, 'from_address' => $depot->label(), 'from_contact' => $depot->contact_name, 'from_phone' => $depot->phone,
            'client_id' => $leg->client_id, 'created_by' => $by?->id,
        ]);
        TransportEvent::record($next, 'opened', $by, ['from' => $depot->label()]);

        return $next;
    }

    /** Подпись точки «откуда» отрезка: владелец (или адрес заказчика) — у первого, стоянка — у остальных. */
    public static function fromLabel(Transport $t): string
    {
        return $t->fromDepot ? 'Стоянка '.$t->fromDepot->name : ($t->isPickup() ? 'Владелец' : ($t->from_address ?? 'Откуда'));
    }

    /** Подпись точки «куда» отрезка. */
    public static function toLabel(Transport $t): string
    {
        return $t->destination === Point::Depot ? 'Стоянка '.($t->depot?->name ?? '') : ($t->routeLabel() ?? 'пока неизвестно');
    }
}
