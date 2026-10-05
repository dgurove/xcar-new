<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ящик: доступы лежат в базе (пароли зашифрованы ключом приложения), заводятся в админке. */
#[Fillable([
    'slug', 'title', 'email', 'from_name', 'scope', 'reply_account_id', 'imap_host', 'imap_port', 'imap_encryption', 'imap_validate_cert',
    'imap_username', 'imap_password', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
    'signature', 'is_active', 'sync_from', 'files_from',
])]
#[Hidden(['imap_password', 'smtp_password'])]
class Account extends Model
{
    protected $table = 'mail_accounts';

    protected function casts(): array
    {
        return [
            'scope' => Scope::class,
            'imap_port' => 'int',
            'smtp_port' => 'int',
            'imap_validate_cert' => 'bool',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'is_active' => 'bool',
            'sync_from' => 'date',
            'files_from' => 'date',
            'synced_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Ящик, с которого уходят ответы на письма этого: offer@ только принимает, отвечаем с deal@ (05.10.2026). */
    public function replyAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_account_id');
    }

    /** С какого ящика отвечать на письма этого ящика: «Отвечаем с ящика», если он включён, иначе сам. */
    public function sender(): self
    {
        $to = $this->replyAccount;

        return $to && $to->is_active && $to->scope === $this->scope ? $to : $this;
    }

    /** Ящики, с которых можно писать: без тех, что только принимают. */
    public function scopeSending(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('reply_account_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'account_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class, 'account_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'account_id');
    }

    public function folderOf(FolderKind $kind): ?Folder
    {
        return $this->folders()->where('kind', $kind)->first();
    }

    public function fromName(): string
    {
        return $this->from_name ?: $this->title;
    }

    public function imapConfig(): array
    {
        return [
            'host' => $this->imap_host,
            'port' => $this->imap_port,
            'encryption' => $this->imap_encryption === 'none' ? false : $this->imap_encryption,
            'validate_cert' => $this->imap_validate_cert,
            'username' => $this->imap_username,
            'password' => $this->imap_password,
            'protocol' => 'imap',
            'authentication' => null,
        ];
    }

    /** Мейлер собирается из строки ящика штатным сборщиком: он знает, как из «ssl на 465» получить неявный TLS. */
    public function smtpConfig(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->smtp_host,
            'port' => $this->smtp_port,
            'username' => $this->smtp_username,
            'password' => $this->smtp_password,
            'timeout' => 30,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'xcar.ru',
        ];
    }

    public function markSynced(): void
    {
        $this->forceFill(['synced_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['last_error' => mb_substr($error, 0, 2000), 'last_error_at' => now()])->save();
    }
}
