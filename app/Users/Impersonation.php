<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Вход админа за человека. Ссылка одноразовая, 15 минут, в базе хэш; сессия за него — не дольше HOURS
 * и помечена impersonation_id: полоса «Вы в кабинете», без следов просмотра, без пароля и ключей.
 */
#[Fillable(['admin_id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'ended_at', 'ip', 'user_agent', 'created_at'])]
class Impersonation extends Model
{
    public const UPDATED_AT = null;

    public const MINUTES = 15;

    public const HOURS = 4;

    public const SESSION = 'impersonation_id';

    private static ?self $current = null;

    private static ?int $currentId = null;

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

    public static function byToken(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }

    /** Ссылка ещё не открыта и не просрочена. */
    public function isLive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /** За кого можно войти: не сотрудник (админ, модератор) и не сам. */
    public static function allowed(User $by, User $for): bool
    {
        return $by->isAdmin() && ! $for->isStaff() && ! $for->is($by);
    }

    /** Сессия за человека ещё в силе: не вышли, не прошли часы, админ всё ещё админ. */
    public function isRunning(): bool
    {
        return $this->ended_at === null && $this->used_at?->copy()->addHours(self::HOURS)->isFuture()
            && $this->admin && self::allowed($this->admin, $this->user);
    }

    /** Вход за человека в этом запросе — запись из сессии, один запрос в базу на запрос. */
    public static function current(): ?self
    {
        $id = session()->isStarted() ? session(self::SESSION) : null;
        if (! $id) {
            return null;
        }
        if (self::$currentId !== $id) {
            self::$currentId = $id;
            self::$current = self::with(['admin', 'user'])->find($id);
        }

        return self::$current;
    }

    public static function active(): bool
    {
        return self::current() !== null;
    }
}
