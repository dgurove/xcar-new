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
#[Fillable(['preset', 'user_id', 'scope', 'notify'])]
final class TabOwner extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;

    protected $table = 'offer_tab_owners';

    protected function casts(): array
    {
        return ['notify' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Вкладки, за которые человек отвечает (`Responsibility::BINDING` и `EVERYTHING` — не вкладки: «Только мои» их не показывает).
     *
     * @return list<string>
     */
    public static function presetsOf(User $user): array
    {
        return array_values(array_diff(array_keys(self::of($user)), [Responsibility::BINDING, Responsibility::EVERYTHING]));
    }

    /**
     * Строки человека: вкладка → за что отвечает (`scope`: `all` | `urgent`) и шлём ли пуш и Telegram (`notify`), 26.11.2026.
     *
     * @return array<string, self>
     */
    public static function of(User $user): array
    {
        return self::rows()->where('user_id', $user->id)->keyBy('preset')->all();
    }

    /**
     * Все строки ответственных — раз на запрос (не статическим полем: воркер Octane живёт дольше запроса).
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function rows(): \Illuminate\Support\Collection
    {
        $rows = fn () => self::query()->orderBy('id')->get()->toBase();

        return app()->runningInConsole() ? $rows() : once($rows);
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
