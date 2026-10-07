<?php

namespace App\Users\Access;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Устройство менеджера (07.10.2026): живое у человека одно. Браузер носит открытую метку (cookie `Lock::COOKIE`), здесь —
 * её sha256; браузер держит закрытый ключ, здесь — открытый, им проверяется каждое подтверждение. `verified_until` —
 * конец окна: дальше экран замерзает до кода или кнопки в боте.
 *
 * `pgsql_async`: окно продлевается на каждом подтверждении, а фиксация на HDD прода стоит 160–290 мс. Потеря последней
 * записи при сбое сервера значит одно лишнее подтверждение.
 */
#[Fillable(['user_id', 'token_hash', 'public_key', 'label', 'ip', 'topic', 'verified_until', 'revoked_at'])]
class Device extends Model
{
    protected $connection = 'pgsql_async';

    protected function casts(): array
    {
        return ['public_key' => 'array', 'verified_until' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function live(User $user): ?self
    {
        return self::where('user_id', $user->id)->whereNull('revoked_at')->first();
    }

    public function owns(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function isOpen(): bool
    {
        return $this->verified_until?->isFuture() ?? false;
    }
}
