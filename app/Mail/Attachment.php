<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Вложение — строка описи, не файл. Файл живёт в ящике (section + encoding),
 * пока ветку не привязали к машине: тогда он закрепляется у нас (blob_sha).
 * path — только у ещё не отправленного письма: файл в outbox на диске cache.
 */
#[Fillable(['message_id', 'filename', 'mime', 'size', 'path', 'section', 'encoding', 'blob_sha', 'pinned_at', 'content_id', 'is_inline', 'position'])]
class Attachment extends Model
{
    protected $table = 'mail_attachments';

    protected function casts(): array
    {
        return ['is_inline' => 'bool', 'size' => 'int', 'pinned_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(function (Attachment $attachment) {
            if ($attachment->path) {
                Storage::disk(Parts::CACHE_DISK)->delete($attachment->path);
            }
            Storage::disk(Parts::CACHE_DISK)->delete("mail/{$attachment->id}");
            if ($attachment->blob_sha) {
                Blobs::release($attachment->blob_sha);
            }
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    /** Абсолютный путь к файлу — где бы он ни лежал; null, если письма уже нет в ящике. */
    public function file(): ?string
    {
        return app(Parts::class)->file($this);
    }

    public function contents(): ?string
    {
        return app(Parts::class)->contents($this);
    }

    /** Файл лежит на диске — можно показать рамкой, не дожидаясь похода в ящик. */
    public function isOnDisk(): bool
    {
        return app(Parts::class)->local($this) !== null;
    }

    public function isPinned(): bool
    {
        return $this->blob_sha !== null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/') && $this->mime !== 'image/svg+xml';
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function humanSize(): string
    {
        $b = $this->size;

        return $b >= 1048576 ? round($b / 1048576, 1).' МБ' : ($b >= 1024 ? round($b / 1024).' КБ' : $b.' Б');
    }
}
