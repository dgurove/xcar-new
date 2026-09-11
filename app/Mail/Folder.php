<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'path', 'name', 'delimiter', 'kind', 'is_syncable', 'uid_validity', 'uid_next', 'last_uid', 'messages_count', 'unseen_count', 'synced_at'])]
class Folder extends Model
{
    protected $table = 'mail_folders';

    protected function casts(): array
    {
        return ['kind' => FolderKind::class, 'is_syncable' => 'bool', 'synced_at' => 'datetime', 'uid_validity' => 'int', 'uid_next' => 'int', 'last_uid' => 'int'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /** Снимок STATUS отличается от сохранённого — в папку стоит зайти. */
    public function looksChanged(?int $uidValidity, ?int $uidNext, ?int $messages, ?int $unseen): bool
    {
        return $this->synced_at === null
            || $this->uid_validity !== $uidValidity
            || $this->uid_next !== $uidNext
            || ($messages !== null && $this->messages_count !== $messages)
            || ($unseen !== null && $this->unseen_count !== $unseen);
    }
}
