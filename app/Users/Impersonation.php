<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Вход админа за человека. Ссылка одноразовая, 15 минут, в базе хэш; сессия за него — не дольше HOURS
 * и помечена impersonation_id: плашка «Вы в кабинете», без следов просмотра, без пароля и ключей.
 */
#[Fillable(['admin_id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'ended_at', 'ip', 'user_agent', 'created_at'])]
class Impersonation extends Model
{
    use HashedToken;

    public const UPDATED_AT = null;

    public const MINUTES = 15;

    public const HOURS = 4;

    public const SESSION = 'impersonation_id';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id')->withoutGlobalScope('demo');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScope('demo');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ImpersonationAction::class);
    }

    /** Ссылка ещё не открыта и не просрочена. */
    public function isLive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * За кого можно войти: за любого, кроме админа и себя. Модератора тоже (02.10.2026, владелец): проверить, что
     * видят девчонки в CRM, — ссылка ведёт через `LoginController::home`, и модератор оказывается в CRM.
     */
    public static function allowed(User $by, User $for): bool
    {
        return $by->isAdmin() && ! $for->isAdmin() && ! $for->is($by);
    }

    /** Сессия за человека ещё в силе: не вышли, не прошли часы, админ всё ещё админ. */
    public function isRunning(): bool
    {
        return $this->ended_at === null && $this->used_at?->copy()->addHours(self::HOURS)->isFuture()
            && $this->admin && $this->user && self::allowed($this->admin, $this->user);
    }

    /**
     * Вход за человека в этом запросе — запись из сессии, один запрос в базу на запрос. Память — атрибут
     * запроса, а не статика: воркер Octane живёт между запросами, и статика отдавала бы вчерашние права.
     */
    public static function current(): ?self
    {
        $request = app()->bound('request') ? request() : null;
        $id = $request?->hasSession() ? $request->session()->get(self::SESSION) : null;
        if (! $id) {
            return null;
        }
        $memo = $request->attributes->get(self::SESSION);
        if (! is_array($memo) || $memo[0] !== $id) {
            $memo = [$id, self::with(['admin', 'user'])->find($id)];
            $request->attributes->set(self::SESSION, $memo);
        }

        return $memo[1];
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }
}
