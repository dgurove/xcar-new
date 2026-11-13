<?php

namespace App\Chats;

use App\Support\FastAttributes;
use App\Support\Linkify;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['chat_id', 'seq', 'author_id', 'author_kind', 'text', 'reply_to', 'edited_at', 'deleted_at', 'requirement_id', 'ask'])]
class Message extends Model
{
    use FastAttributes;

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

    /** Просьба шага, чьи кнопки несёт сообщение площадки (10.11.2026, `Chats\StepKeys`). */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(Requirement::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'message_id');
    }

    /** Сообщение, на которое это — ответ (тот же чат, по номеру). Лента подгружает цитаты пачкой — `primeReplies`. */
    public function replied(): ?Message
    {
        if ($this->relationLoaded('repliedTo')) {
            return $this->getRelation('repliedTo');
        }

        return $this->reply_to ? static::where('chat_id', $this->chat_id)->where('seq', $this->reply_to)->with('files')->first() : null;
    }

    /** Цитаты ответов для пачки сообщений одним запросом — иначе по два запроса на каждый ответ в ленте. */
    public static function primeReplies(iterable $messages): void
    {
        $messages = collect($messages)->filter(fn (self $m) => $m->reply_to);
        if ($messages->isEmpty()) {
            return;
        }
        $quoted = static::with(['files', 'author'])
            ->where(fn ($q) => $messages->groupBy('chat_id')->each(fn ($group, $chat) => $q->orWhere(fn ($w) => $w->where('chat_id', $chat)->whereIn('seq', $group->pluck('reply_to')->unique()->values()))))
            ->get()->keyBy(fn (self $m) => $m->chat_id.':'.$m->seq);
        $messages->each(fn (self $m) => $m->setRelation('repliedTo', $quoted->get($m->chat_id.':'.$m->reply_to)));
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Имя автора для чужого пузыря и цитаты. */
    /**
     * Имя над пузырём и в цитате. Администрация XCar для менеджера и покупателя обезличена (13.11.2026, владелец: «видят, кто им
     * пишет — Андрей, Дмитрий или Катя, так не должно быть, администрация везде обезличена»): сотрудник в чате площадки — «Администрация
     * XCar» всем, кроме сотрудников.
     */
    public function authorName(Chat $chat, ?User $viewer = null): string
    {
        if ($this->hidesAuthorFrom($chat, $viewer)) {
            return Chat::PLATFORM;
        }

        return $this->author?->name ?? ($this->author_kind === AuthorKind::Staff ? Chat::PLATFORM : ($chat->guest_name ?? ''));
    }

    /** Писал сотрудник площадки, а читает не сотрудник: ни имени, ни аватара. */
    public function hidesAuthorFrom(Chat $chat, ?User $viewer): bool
    {
        return $this->author_kind === AuthorKind::Staff && ! $chat->manager_id && ! $viewer?->isStaff();
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
