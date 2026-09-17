<?php

namespace App\Chats;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Чат по предложению (пара оффер + участник) или обращение с сайта (без предложения; у гостя — по токену).
 * Вторая сторона — сотрудники площадки, а с manager_id — менеджер, с которым говорит его покупатель:
 * тогда счётчик unread_for_staff и AuthorKind::Staff означают «вторая сторона», сотрудники в чате не участвуют.
 */
#[Fillable(['offer_id', 'user_id', 'manager_id', 'guest_name', 'guest_token', 'messages_count', 'unread_for_user', 'unread_for_staff', 'read_seq_user', 'read_seq_staff', 'last_message_at'])]
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('seq');
    }

    /** Для строки списка: последнее сообщение (текст, автор, удалено) подзапросами, без второй выборки. */
    public function scopeWithLast(Builder $query): Builder
    {
        $last = fn (string $column) => Message::select($column)->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1);
        // Есть ли у последнего сообщения не-картинка — чтобы превью говорило «Файл», а не «Фото».
        $doc = File::selectRaw('1')->whereIn('message_id', $last('id'))->where('mime', 'not like', 'image/%')->limit(1);

        return $query->with(['offer.brand', 'offer.model', 'offer.media', 'user', 'manager'])
            ->addSelect(['*', 'last_text' => $last('text'), 'last_author_id' => $last('author_id'), 'last_deleted_at' => $last('deleted_at'), 'last_doc' => $doc]);
    }

    /** Превью последнего сообщения в строке списка; me — чтобы своё начиналось с «Вы:». */
    public function lastPreview(?User $me): string
    {
        if ($this->last_deleted_at) {
            return 'Сообщение удалено';
        }
        $text = $this->last_text ? Str::limit($this->last_text, 90) : ($this->last_doc ? 'Файл' : 'Фото');

        return ($me && $this->last_author_id === $me->id ? 'Вы: ' : '').$text;
    }

    public function isEnquiry(): bool
    {
        return $this->offer_id === null;
    }

    /** Чат покупателя со своим менеджером, а не с площадкой. */
    public function isBuyerChat(): bool
    {
        return $this->manager_id !== null;
    }

    /** Вторая сторона чата: менеджер покупателя или любой сотрудник площадки. Одна дверь для счётчиков, «моих» сообщений и доступа. */
    public function isCounterpart(?User $user): bool
    {
        return $user !== null && ($this->manager_id ? $user->id === $this->manager_id : $user->isStaff());
    }

    /** Имя собеседника для второй стороны. */
    public function displayName(): string
    {
        return $this->user?->name ?? $this->guest_name ?? 'Гость';
    }

    /** Кто на другом конце для читающего: участнику — менеджер или XCar, второй стороне — участник. */
    public function counterpartName(?User $for): string
    {
        return $this->isCounterpart($for) ? $this->displayName() : ($this->manager?->shortName() ?? 'XCar');
    }

    /** До какого номера дочитала другая сторона — для галочек на своих сообщениях читающего. */
    public function readSeqOf(?User $for): int
    {
        return (int) ($this->isCounterpart($for) ? $this->read_seq_user : $this->read_seq_staff);
    }

    /** Непрочитанное читающего: до какого номера дочитал он сам. */
    public function myReadSeq(?User $for): int
    {
        return (int) ($this->isCounterpart($for) ? $this->read_seq_staff : $this->read_seq_user);
    }

    /** Писать может участник, вторая сторона или гость с токеном; сотрудник в чужом чате — только читать. */
    public function canPost(?User $user, ?string $token = null): bool
    {
        if ($user && ($user->id === $this->user_id || $this->isCounterpart($user))) {
            return true;
        }

        return $this->guestAllows($token);
    }

    /**
     * Читать может участник, вторая сторона, сотрудник или гость с токеном из cookie;
     * постороннему — 404, а не 403, чтобы перебором не узнать, какие чаты есть.
     */
    public function allows(?User $user, ?string $token = null): bool
    {
        // Сотрудник читает любой чат — и переписку покупателя с менеджером тоже, но не пишет в неё (canPost).
        if ($user && ($user->id === $this->user_id || $this->isCounterpart($user) || $user->isStaff())) {
            return true;
        }

        return $this->guestAllows($token);
    }

    private function guestAllows(?string $token): bool
    {
        return $token !== null && $this->guest_token !== null && hash_equals($this->guest_token, hash('sha256', $token));
    }
}
