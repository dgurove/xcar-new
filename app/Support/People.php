<?php

namespace App\Support;

use App\Logistics\Client;
use App\Logistics\Transport;
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
    public static function row(User|Client $p, ?string $sub = null): array
    {
        if ($p instanceof Client) {
            return ['id' => $p->id, 'name' => $p->name, 'sub' => $sub ?? (implode(', ', array_filter([$p->contact_name, $p->phone ? Phone::format($p->phone) : null])) ?: null), 'user' => null];
        }
        $sub ??= match (true) {
            $p->isContractor() => implode(', ', array_filter([$p->logistics_city, ($busy = (int) ($p->busy ?? 0)) ? 'в работе '.$busy : 'без вывозов'])),
            default => $p->phone ? Phone::format($p->phone) : $p->email,
        };

        return ['id' => $p->id, 'name' => $p->name, 'sub' => $sub ?: null, 'user' => $p];
    }

    /** @return Collection<int, Row> */
    public static function rows(iterable $people): Collection
    {
        return collect($people)->map(fn ($p) => self::row($p))->values();
    }

    /** Исполнители логиста с числом открытых вывозов (`busy`) — и сам логист первой строкой. */
    public static function contractorsOf(User $logist): Collection
    {
        $busy = fn ($q) => $q->select('users.*')->addSelect(['busy' => Transport::open()->selectRaw('count(*)')->whereColumn('transports.executor_id', 'users.id')]);
        $self = $busy(User::whereKey($logist->id))->first();

        return collect([self::row($self, 'вы, '.(($n = (int) $self->busy) ? 'в работе '.$n : 'без вывозов'))])
            ->concat(self::rows($busy(User::contractorsFor($logist)->where('logist_id', $logist->id)->whereNull('rejected_at')->orderBy('name'))->get()));
    }
}
