<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ссылка на новый пароль: в базе хэш, срок сутки, одноразовая. */
#[Fillable(['user_id', 'token_hash', 'created_by', 'expires_at', 'used_at'])]
class PasswordLink extends Model
{
    use HashedToken;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
