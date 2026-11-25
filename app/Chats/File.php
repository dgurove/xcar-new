<?php

namespace App\Chats;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['message_id', 'name', 'mime', 'size', 'path'])]
class File extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;

    protected $table = 'chat_files';

    protected static function booted(): void
    {
        static::deleted(fn (File $file) => Storage::disk('private')->delete($file->path));
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function isImage(): bool
    {
        // SVG — файл, а не фото: на странице он исполнил бы свой скрипт (25.11.2026, в чат — любой файл).
        return str_starts_with($this->mime, 'image/') && $this->mime !== 'image/svg+xml';
    }

    public function contents(): ?string
    {
        $disk = Storage::disk('private');

        return $disk->exists($this->path) ? $disk->get($this->path) : null;
    }
}
