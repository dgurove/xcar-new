<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['message_id', 'filename', 'mime', 'size', 'path', 'content_id', 'is_inline', 'position'])]
class Attachment extends Model
{
    protected $table = 'mail_attachments';

    protected function casts(): array
    {
        return ['is_inline' => 'bool', 'size' => 'int'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function contents(): ?string
    {
        $disk = Storage::disk(Message::DISK);

        return $disk->exists($this->path) ? $disk->get($this->path) : null;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
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
