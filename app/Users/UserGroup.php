<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Группа людей (02.10.2026, ведётся в «Пользователях»), раздельная по виду:
 * - `managers` — для волн показа предложений: «Свои», «Москва», «Новые» (AudienceRules);
 * - `moderators` — видят и правят предложения друг друга: «XCar Москва» (User::teamIds).
 * Человек может быть в нескольких группах своего вида.
 */
#[Fillable(['name', 'position', 'kind'])]
class UserGroup extends Model
{
    public const MANAGERS = 'managers';

    public const MODERATORS = 'moderators';

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user', 'group_id', 'user_id')->orderBy('name');
    }

    /** Вид группы по роли человека: менеджеру — группы менеджеров, модератору — модераторов; остальным групп нет. */
    public static function kindFor(Role $role): ?string
    {
        return match ($role) {
            Role::Manager => self::MANAGERS,
            Role::Moderator => self::MODERATORS,
            default => null,
        };
    }

    /** Кто бывает в группе этого вида. */
    public static function roleOf(string $kind): Role
    {
        return $kind === self::MODERATORS ? Role::Moderator : Role::Manager;
    }

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind)->orderBy('position')->orderBy('name');
    }
}
