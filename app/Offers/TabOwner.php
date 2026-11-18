<?php

namespace App\Offers;

use App\Support\FastAttributes;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ответственный за вкладку «Предложений» (18.11.2026, владелец): вкладка — ключ `OfferController::PRESETS`, у вкладки
 * несколько сотрудников (администраторы и модераторы). Задаёт админ в «Настройки → Ответственные» (`SetTabOwners`); по ним
 * «Только мои» в колесе оставляет вкладки человека (`presetsOf`).
 */
#[Fillable(['preset', 'user_id'])]
final class TabOwner extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;

    protected $table = 'offer_tab_owners';

    /** @var array<int, list<string>> вкладки человека на запрос */
    private static array $memo = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Можно ли назначить ответственного: вкладкам ожидания (`DealTabs::WAITING`, полупрозрачные) — нет (18.11.2026, владелец:
     * «у них и не может быть ответственного»), там нашего дела нет.
     */
    public static function assignable(string $preset): bool
    {
        return ! in_array($preset, DealTabs::WAITING, true);
    }

    /** Вкладки, за которые человек отвечает. @return list<string> */
    public static function presetsOf(User $user): array
    {
        return self::$memo[$user->id] ??= self::where('user_id', $user->id)->pluck('preset')->all();
    }

    /** Кого можно назначить: администраторы и модераторы, не отклонённые и не демо. @return Collection<int, User> */
    public static function staff(): Collection
    {
        return User::withRole(Role::Admin, Role::Moderator)->whereNull('rejected_at')->where('is_demo', false)->orderBy('name')->get();
    }

    public static function forget(): void
    {
        self::$memo = [];
    }
}
