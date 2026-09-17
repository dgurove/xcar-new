<?php

namespace App\Chats;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['chat_id', 'seq', 'author_id', 'author_kind', 'text'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chat_messages';

    protected function casts(): array
    {
        return ['author_kind' => AuthorKind::class, 'created_at' => 'datetime'];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'message_id');
    }

    /** Своё ли это сообщение для читающего: участник (и гость) видит свои справа, вторая сторона — свои. */
    public function isMine(?User $user, ?Chat $chat = null): bool
    {
        return ($chat ?? $this->chat)->isCounterpart($user) ? $this->author_kind !== AuthorKind::Participant : $this->author_kind === AuthorKind::Participant;
    }
}
