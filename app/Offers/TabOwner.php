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
        return array_keys(array_filter(self::owners(), fn (Collection $people) => $people->contains('id', $user->id)));
    }

    /**
     * Ответственные каждой вкладки (аватарки в колесе, «Только мои») — один запрос на веб-запрос. Не статическим полем: воркер
     * Octane живёт дольше запроса, и после «Ответственных» другие воркеры показывали прежних (24.11.2026).
     *
     * @return array<string, Collection<int, User>>
     */
    public static function owners(): array
    {
        $owners = fn () => self::query()->with(['user' => fn ($q) => $q->with(User::withAvatar())])->orderBy('id')->get()
            ->groupBy('preset')->map(fn ($rows) => new Collection($rows->pluck('user')->filter()->values()->all()))->all();

        return app()->runningInConsole() ? $owners() : once($owners);
    }

    /** Кого можно назначить: администраторы и модераторы, не отклонённые и не демо. @return Collection<int, User> */
    public static function staff(): Collection
    {
        return User::withRole(Role::Admin, Role::Moderator)->whereNull('rejected_at')->where('is_demo', false)->orderBy('name')->get();
    }

    public static function forget(): void
    {
        \Illuminate\Support\Once::flush();
    }
}
