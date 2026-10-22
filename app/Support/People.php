<?php

namespace App\Support;

use App\Logistics\Client;
use App\Logistics\Depot;
use App\Logistics\Transport;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Строки выбора человека (`x-people.pick`, 18.10.2026): одна форма для покупателя менеджера, исполнителя логиста,
 * логиста и водителя, заказчика логиста — аватар, имя и подпись по смыслу места (у исполнителя — город и сколько у него
 * в работе: логисту видно, кто свободен; у покупателя — телефон или почта).
 *
 * @phpstan-type Row array{id: int|string, name: string, sub: ?string, user: ?User}
 */
final class People
{
    /** @return Row */
    public static function row(User|Client|Depot $p, ?string $sub = null): array
    {
        // Стоянка логиста: город и адрес, сколько машин стоит.
        if ($p instanceof Depot) {
            return ['id' => $p->id, 'name' => $p->name, 'sub' => $sub ?? (implode(', ', array_filter([$p->settlement?->name, $p->address,
                ($n = (int) ($p->waiting_count ?? 0)) ? 'стоит '.$n : null])) ?: null), 'user' => null];
        }
        if ($p instanceof Client) {
            return ['id' => $p->id, 'name' => $p->name, 'sub' => $sub ?? (implode(', ', array_filter([$p->contact_name, $p->phone ? Phone::format($p->phone) : null])) ?: null), 'user' => null];
        }
        $sub ??= match (true) {
            $p->isContractor() => implode(', ', array_filter([$p->logistics_city, ($busy = (int) ($p->busy ?? 0)) ? 'в работе '.$busy : 'без вывозов'])),
            default => $p->phone ? Phone::format($p->phone) : $p->email,
        };

        return ['id' => $p->id, 'name' => $p->name, 'sub' => $sub ?: null, 'user' => $p];
    }

    /**
     * Все менеджеры строками — «Кому» в «Отдать», «За менеджера», «+ Интерес» (22.10.2026: справочник `/reference/managers`
     * отдавал первых двадцать по имени, и Сахиба в выпадающем списке не было). Раз на запрос: на странице таких шторок несколько.
     */
    public static function managers(): Collection
    {
        return once(fn () => self::rows(User::withRole(Role::Manager)->where('is_demo', false)->whereNull('rejected_at')
            ->with(User::withAvatar())->orderBy('name')->get()));
    }

    /** Покупатели с гаражом по тумблеру — «Отдать → В гараж → Покупателю»; подпись — супер-покупатель или чей покупатель. */
    public static function garageBuyers(): Collection
    {
        return once(fn () => User::garageBuyers()->with(['manager:id,name', ...User::withAvatar()])->orderBy('name')->get()
            ->map(fn (User $u) => self::row($u, $u->isSuperBuyer() ? 'супер-покупатель' : ($u->manager ? 'покупатель '.$u->manager->shortName() : 'покупатель')))
            ->values());
    }

    /** @return Collection<int, Row> */
    public static function rows(iterable $people): Collection
    {
        return collect($people)->map(fn ($p) => self::row($p))->values();
    }

    /**
     * Исполнители логиста с числом открытых вывозов (`busy`). Самого логиста нет (18.10.2026, владелец: логист только
     * распределяет; за исполнителя шаг он отмечает «за него»).
     */
    public static function contractorsOf(User $logist): Collection
    {
        return self::rows(User::contractorsFor($logist)->where('logist_id', $logist->id)->whereNull('rejected_at')->orderBy('name')
            ->select('users.*')->addSelect(['busy' => Transport::open()->selectRaw('count(*)')->whereColumn('transports.executor_id', 'users.id')])->get());
    }

    /** Стоянки логиста строками (`x-people.pick` вид `depot`): свои, не в архиве, где сейчас стоят машины — сверху. */
    public static function depotsOf(?User $logist): Collection
    {
        return $logist ? self::rows(Depot::of($logist)->whereNull('archived_at')->with('settlement')->withWaiting()->orderByDesc('waiting_count')->orderBy('name')->get()) : collect();
    }
}
