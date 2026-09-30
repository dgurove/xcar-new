<?php

namespace App\Telegram;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Чат бота с человеком (Настройки → «Бот Telegram»). Ключ — chat_id Telegram. Аккаунт xcar — если чат
 * к нему привязан; имя и @username — из последнего входящего. left_at — человек остановил бота.
 */
#[Fillable(['id', 'user_id', 'name', 'username', 'last_message_at', 'left_at'])]
class Chat extends Model
{
    protected $table = 'telegram_chats';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'left_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /** Для списка: последнее сообщение без отдельного запроса на строку. */
    public function scopeWithLast(Builder $query): Builder
    {
        $last = fn (string $column) => ChatMessage::select($column)->whereColumn('chat_id', 'telegram_chats.id')->orderByDesc('id')->limit(1);

        return $query->with('user')->addSelect(['*', 'last_text' => $last('text'), 'last_kind' => $last('kind'), 'last_direction' => $last('direction'), 'last_deleted_at' => $last('deleted_at')]);
    }

    public function displayName(): string
    {
        return $this->user?->name ?? $this->name ?? ($this->username ? '@'.$this->username : ($this->isOwner() ? 'Владелец' : 'Чат '.$this->id));
    }

    /** Чат из TELEGRAM_OWNER_CHAT_ID — туда идут сообщения владельца, даже если он не привязан к аккаунту. */
    public function isOwner(): bool
    {
        return $this->id === app(Bot::class)->ownerChatId();
    }

    /** Кто это для нас: роль аккаунта или «владелец» у чата из настроек. */
    public function roleLabel(): ?string
    {
        return $this->user ? mb_strtolower($this->user->role->label()) : ($this->isOwner() ? 'владелец' : null);
    }

    public function lastPreview(): string
    {
        if (! $this->last_kind) {
            return 'Сообщений пока нет';
        }
        $text = $this->last_deleted_at ? 'Сообщение удалено' : ChatMessage::previewOf($this->last_kind, $this->last_text);

        return ($this->last_direction === ChatMessage::OUT ? 'Бот: ' : '').Str::limit($text, 90);
    }
}
