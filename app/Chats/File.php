<?php

namespace App\Chats;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['message_id', 'name', 'mime', 'size', 'path'])]
class File extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chat_files';

    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function contents(): ?string
    {
        $disk = Storage::disk('private');

        return $disk->exists($this->path) ? $disk->get($this->path) : null;
    }
}
