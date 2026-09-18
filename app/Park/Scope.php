<?php

namespace App\Park;

use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Что видит сотрудник стоянки: со «своей площадкой» — только её ТС и заявки
 * (ничьи заявки без площадки — тоже его), админ и без площадки — всё.
 */
final class Scope
{
    public static function yardId(User $user): ?int
    {
        return $user->isAdmin() ? null : $user->park_yard_id;
    }

    public static function vehicles(User $user, ?Builder $q = null): Builder
    {
        $q ??= Vehicle::query();
        if ($yard = self::yardId($user)) {
            $q->where(fn ($w) => $w->where('yard_id', $yard)->orWhereNull('yard_id'));
        }

        return $q;
    }

    public static function requests(User $user, ?Builder $q = null): Builder
    {
        $q ??= Request::query();
        if ($yard = self::yardId($user)) {
            $q->whereHas('vehicle', fn ($v) => $v->where('yard_id', $yard)->orWhereNull('yard_id'));
        }

        return $q;
    }

    public static function allows(User $user, Vehicle $vehicle): bool
    {
        $yard = self::yardId($user);

        return ! $yard || ! $vehicle->yard_id || $vehicle->yard_id === $yard;
    }
}
