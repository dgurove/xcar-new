<?php

namespace App\Chats;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Чат по предложению (пара оффер + участник) или обращение с сайта (без предложения; у гостя — по токену). */
#[Fillable(['offer_id', 'user_id', 'guest_name', 'guest_token', 'messages_count', 'unread_for_user', 'unread_for_staff', 'last_message_at'])]
class Chat extends Model
{
    /** Токен гостя открытым текстом — только сразу после создания, для cookie. */
    public ?string $plainToken = null;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('seq');
    }

    public function isEnquiry(): bool
    {
        return $this->offer_id === null;
    }

    /** Имя собеседника для сотрудника. */
    public function displayName(): string
    {
        return $this->user?->name ?? $this->guest_name ?? 'Гость';
    }

    /**
     * Читать и писать может участник, сотрудник или гость с токеном из cookie;
     * постороннему — 404, а не 403, чтобы перебором не узнать, какие чаты есть.
     */
    public function allows(?User $user, ?string $token = null): bool
    {
        if ($user && ($user->id === $this->user_id || $user->isStaff())) {
            return true;
        }

        return $token !== null && $this->guest_token !== null && hash_equals($this->guest_token, hash('sha256', $token));
    }

    public function unreadFor(?User $user): int
    {
        return $user?->isStaff() ? $this->unread_for_staff : $this->unread_for_user;
    }
}
