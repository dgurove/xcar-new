<?php

namespace App\Telegram;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Сообщение в переписке бота. out — бот написал (сам или сотрудник из CRM, тогда author_id), in — человек.
 * У исходящих text — HTML, как ушёл в Telegram; у входящих — обычный текст или подпись к файлу.
 * kind: text, photo, document, voice, sticker, press (нажал кнопку — text её подпись), system (запустил/остановил бота).
 */
#[Fillable(['chat_id', 'message_id', 'direction', 'kind', 'text', 'keyboard', 'reply_to', 'file_id', 'file_unique_id', 'file_name', 'file_mime', 'file_size', 'author_id', 'failed', 'edited_at', 'deleted_at', 'subject'])]
class ChatMessage extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public const UPDATED_AT = null;

    protected $table = 'telegram_messages';

    protected function casts(): array
    {
        return ['keyboard' => 'array', 'created_at' => 'datetime', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function replied(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to');
    }

    public function isOut(): bool
    {
        return $this->direction === self::OUT;
    }

    public function isEvent(): bool
    {
        return in_array($this->kind, ['press', 'system'], true);
    }

    /** Написано из CRM — его можно изменить или удалить (Telegram даёт боту удалить своё за 48 часов). */
    public function isManual(): bool
    {
        return $this->isOut() && $this->author_id !== null && ! $this->failed && ! $this->deleted_at;
    }

    public function canDelete(): bool
    {
        return $this->isManual() && $this->message_id && $this->created_at->gt(now()->subHours(47));
    }

    public function hasFile(): bool
    {
        return $this->file_id !== null;
    }

    public function isImage(): bool
    {
        return in_array($this->kind, ['photo', 'sticker'], true) || str_starts_with((string) $this->file_mime, 'image/');
    }

    /** Почему не дошло — словами, а не ответом API. */
    public function failedLabel(): string
    {
        return match (true) {
            str_contains((string) $this->failed, 'blocked') => 'бот заблокирован',
            str_contains((string) $this->failed, 'chat not found'), str_contains((string) $this->failed, 'deactivated') => 'чата больше нет',
            default => (string) $this->failed,
        };
    }

    /** Текст без разметки: у исходящих — HTML бота без тегов. */
    public function plain(): string
    {
        $text = (string) $this->text;

        return $this->isOut() ? html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5) : $text;
    }

    /** HTML исходящего для пузыря: только теги, которые понимает Telegram. */
    public function html(): string
    {
        return strip_tags((string) $this->text, ['b', 'strong', 'i', 'em', 'u', 's', 'code', 'pre', 'a', 'blockquote']);
    }

    public function preview(int $limit = 80): string
    {
        return Str::limit(self::previewOf($this->kind, $this->isOut() ? $this->plain() : $this->text), $limit);
    }

    public static function previewOf(?string $kind, ?string $text): string
    {
        $text = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));

        return match ($kind) {
            'press' => 'Нажал кнопку «'.$text.'»',
            'photo' => $text ?: 'Фото',
            'document' => $text ?: 'Файл',
            'voice' => 'Голосовое',
            'sticker' => 'Стикер '.$text,
            default => str_replace("\n", ' ', $text),
        };
    }
}
