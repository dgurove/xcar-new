<?php

namespace App\Chats;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['offer_id', 'user_id', 'messages_count', 'unread_for_user', 'unread_for_staff', 'last_message_at'])]
class Chat extends Model
{
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

    /** Читать и писать может участник или сотрудник; постороннему — 404, а не 403, чтобы перебором не узнать, какие чаты есть. */
    public function allows(?User $user): bool
    {
        return $user && ($user->id === $this->user_id || $user->isStaff());
    }

    public function unreadFor(User $user): int
    {
        return $user->isStaff() ? $this->unread_for_staff : $this->unread_for_user;
    }
}
