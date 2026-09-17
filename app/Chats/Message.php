<?php

namespace App\Chats;

use App\Support\Linkify;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['chat_id', 'seq', 'author_id', 'author_kind', 'text', 'reply_to', 'edited_at', 'deleted_at'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chat_messages';

    protected function casts(): array
    {
        return ['author_kind' => AuthorKind::class, 'created_at' => 'datetime', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
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

    /** Сообщение, на которое это — ответ (тот же чат, по номеру). */
    public function replied(): ?Message
    {
        return $this->reply_to ? static::where('chat_id', $this->chat_id)->where('seq', $this->reply_to)->with('files')->first() : null;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Имя автора для чужого пузыря и цитаты. */
    public function authorName(Chat $chat): string
    {
        return $this->author?->name ?? ($this->author_kind === AuthorKind::Staff ? Chat::PLATFORM : ($chat->guest_name ?? ''));
    }

    /** Строка превью: список чатов, цитата, тост. */
    public function preview(int $limit = 80): string
    {
        if ($this->isDeleted()) {
            return 'Сообщение удалено';
        }

        if ($this->text) {
            return Str::limit(Linkify::plain($this->text), $limit);
        }

        return $this->relationLoaded('files') && $this->files->contains(fn (File $f) => ! $f->isImage()) ? 'Файл' : 'Фото';
    }

    /** Править и удалять — только автор, и только своё живое сообщение. */
    public function ownedBy(?User $user): bool
    {
        return $user !== null && $this->author_id === $user->id && ! $this->isDeleted() && $this->author_kind !== AuthorKind::System;
    }

    /** Своё ли это сообщение для читающего: участник (и гость) видит свои справа, вторая сторона — свои. */
    public function isMine(?User $user, ?Chat $chat = null): bool
    {
        return ($chat ?? $this->chat)->isCounterpart($user) ? $this->author_kind !== AuthorKind::Participant : $this->author_kind === AuthorKind::Participant;
    }
}
