<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Пригласительная ссылка. Менеджер зовёт покупателей (многоразовая: одну ссылку
 * можно послать десяти людям; `fields` — какие контакты покупатель указывает при
 * регистрации). Админ зовёт менеджеров — такая ссылка одноразовая (`max_uses` 1):
 * пришедший сразу становится менеджером — и покупателей от имени менеджера.
 */
#[Fillable(['manager_id', 'role', 'created_by', 'code', 'label', 'fields', 'group_id', 'max_uses', 'expires_at', 'disabled_at'])]
class Invite extends Model
{
    public const FIELDS = ['phone' => 'Телефон', 'email' => 'Почта'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'disabled_at' => 'datetime', 'expires_at' => 'datetime', 'role' => Role::class];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public static function freshCode(): string
    {
        do {
            $code = Str::random(8);
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BuyerGroup::class, 'group_id');
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(User::class, 'invite_id');
    }

    /** Кто вправе видеть и выключать (scopeManageableBy / isManageableBy): админ — все, менеджер — ссылки своих покупателей (и сделанные для него админом). */
    public function scopeManageableBy(Builder $q, User $user): Builder
    {
        return $user->isAdmin() ? $q : $q->where('manager_id', $user->id);
    }

    public function isManageableBy(User $user): bool
    {
        return $user->isAdmin() || $this->manager_id === $user->id;
    }

    /** Кому ссылка: короткое слово для чипа. */
    public function kind(): string
    {
        return match ($this->role) {
            Role::Buyer => 'покупателю',
            Role::Manager => 'менеджеру',
            Role::Moderator => 'модератору',
            Role::Admin => 'администратору',
            default => $this->role->label(),
        };
    }

    public function url(): string
    {
        return \App\Support\Surface::Site->url("/i/{$this->code}");
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null && ! $this->isUsedUp() && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Одноразовая (или с пределом) ссылка уже сработала. */
    public function isUsedUp(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }

    /** Покупателю — многоразовая от имени менеджера; всем остальным — одноразовая со сроком. */
    public function forBuyer(): bool
    {
        return $this->role === Role::Buyer;
    }

    public function allows(string $field): bool
    {
        return (bool) ($this->fields[$field] ?? false);
    }

    /** @return list<string> */
    public function contactFields(): array
    {
        return array_values(array_filter(array_keys(self::FIELDS), fn ($f) => $this->allows($f)));
    }

    /** Подпись строки: своё название или дата. */
    public function title(): string
    {
        return $this->label ?: mb_ucfirst($this->kind());
    }
}
