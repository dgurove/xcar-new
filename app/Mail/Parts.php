<?php

namespace App\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Откуда взять файл вложения: ещё не отправленное — из outbox, закреплённое —
 * из blobs, остальное — из ящика по секции, с кэшем на сутки. К одному ящику
 * ходим по очереди: mail.ru держит считанные соединения на аккаунт.
 */
final class Parts
{
    public const MAX_PART = 50 * 1024 * 1024;

    public const CACHE_DISK = 'cache';

    /** Абсолютный путь к файлу вложения; null — файла нет нигде. */
    public function file(Attachment $attachment): ?string
    {
        if ($attachment->path && Storage::disk(self::CACHE_DISK)->exists($attachment->path)) {
            return Storage::disk(self::CACHE_DISK)->path($attachment->path);
        }
        if ($attachment->blob_sha && ($blob = Blobs::absolute($attachment->blob_sha))) {
            return $blob;
        }
        $cache = Storage::disk(self::CACHE_DISK);
        $cached = "mail/{$attachment->id}";
        if ($cache->exists($cached)) {
            return $cache->path($cached);
        }
        $contents = $this->fetch($attachment);
        if ($contents === null) {
            return null;
        }
        $cache->put($cached, $contents);

        return $cache->path($cached);
    }

    public function contents(Attachment $attachment): ?string
    {
        $file = $this->file($attachment);

        return $file !== null ? (string) file_get_contents($file) : null;
    }

    /** Часть письма из ящика, уже раскодированная. Своё соединение или переданное (закрепление ветки). */
    public function fetch(Attachment $attachment, ?Imap $imap = null): ?string
    {
        $message = $attachment->message;
        if (! $attachment->section || ! $message?->existsOnServer() || $attachment->size > self::MAX_PART) {
            return null;
        }
        $read = function (Imap $imap) use ($attachment, $message) {
            $raw = $imap->part($message->folder->path, $message->imap_uid, $attachment->section);

            return $raw === null ? null : Structure::decodeTransfer($raw, (string) $attachment->encoding);
        };
        if ($imap) {
            return $read($imap);
        }
        try {
            return Cache::lock("imap-parts:{$message->account_id}", 120)->block(60, function () use ($message, $read) {
                $imap = new Imap($message->account, 60);
                try {
                    return $read($imap);
                } finally {
                    $imap->disconnect();
                }
            });
        } catch (Throwable $e) {
            Log::warning('Почта: вложение не забралось из ящика', ['attachment' => $attachment->id, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
