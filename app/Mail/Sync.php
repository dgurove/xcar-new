<?php

namespace App\Mail;

use App\Mail\Jobs\ParseMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Зеркало ящика. Приём и разбор разведены: сырой .eml ложится на диск и в
 * базу «ждёт разбора», MIME разбирает джоб. Ключ идемпотентности —
 * (folder, uid_validity, uid); одно письмо в ящике — одна запись:
 * двойник по Message-ID переезжает за письмом, а не заводится второй раз.
 */
final class Sync
{
    public const MAX_FETCH_BYTES = 25 * 1024 * 1024;   // больше — только заголовки

    public const BUDGET_BYTES = 24 * 1024 * 1024;      // пачка за один FETCH

    public const BATCH = 25;

    public function __construct(private Parser $parser, private Threads $threads) {}

    public function account(Account $account, bool $full = false): int
    {
        $imap = new Imap($account);
        try {
            $snapshots = $this->folders($account, $imap);
            $stored = 0;
            foreach ($account->folders()->where('is_syncable', true)->get() as $folder) {
                if ($snapshot = $snapshots[$folder->path] ?? null) {
                    $stored += $this->folder($account, $folder, $snapshot, $imap, $full);
                }
            }
            $account->markSynced();

            return $stored;
        } catch (Throwable $e) {
            $account->markFailed($e->getMessage());
            throw $e;
        } finally {
            $imap->disconnect();
        }
    }

    /** Папки с сервера → строки в базе. Возвращает снимки STATUS по пути. */
    public function folders(Account $account, Imap $imap): array
    {
        $snapshots = [];
        foreach ($imap->folders() as $snapshot) {
            $snapshots[$snapshot['path']] = $snapshot;
            $folder = $account->folders()->firstOrNew(['path' => $snapshot['path']]);
            $folder->fill(['name' => $snapshot['name'], 'delimiter' => $snapshot['delimiter'], 'kind' => $snapshot['kind']]);
            if (! $folder->exists) {
                $folder->is_syncable = ! $snapshot['kind']->isNoise();
            }
            $folder->save();
        }

        return $snapshots;
    }

    public function folder(Account $account, Folder $folder, array $snapshot, Imap $imap, bool $full = false): int
    {
        if (! $full && ! $folder->looksChanged($snapshot['uid_validity'], $snapshot['uid_next'], $snapshot['messages'], $snapshot['unseen'])) {
            return 0;
        }
        if ($folder->uid_validity !== null && $snapshot['uid_validity'] !== null && $snapshot['uid_validity'] !== $folder->uid_validity) {
            // Сервер пересоздал папку: прежние UID ничего не значат. Письма найдутся по Message-ID.
            Log::warning('Почта: uidvalidity сменился', ['account' => $account->email, 'folder' => $folder->path]);
            $folder->forceFill(['last_uid' => 0])->save();
        }

        $uids = $imap->uids($folder->path, $folder->last_uid + 1, $account->sync_from);
        $sizes = $uids ? $this->sizes($imap, $folder->path, $uids) : [];
        $stored = 0;

        $oversized = array_values(array_filter($uids, fn ($u) => ($sizes[$u] ?? 0) > self::MAX_FETCH_BYTES));
        if ($oversized) {
            $stored += $this->storeHeadersOnly($account, $folder, $snapshot, $oversized, $sizes, $imap);
            $uids = array_values(array_diff($uids, $oversized));
            $folder->forceFill(['last_uid' => max($folder->last_uid, max($oversized))])->save();
        }

        foreach ($this->batches($uids, $sizes) as $chunk) {
            foreach ($imap->fetch($folder->path, $chunk) as $raw) {
                try {
                    $stored += (int) $this->store($account, $folder, $snapshot, $raw);
                } catch (Throwable $e) {
                    // Одно непринятое письмо не останавливает папку: UID снова попадёт в диапазон.
                    Log::error('Почта: письмо не записалось', ['account' => $account->email, 'folder' => $folder->path, 'uid' => $raw['uid'], 'error' => $e->getMessage()]);
                }
            }
            $folder->forceFill(['last_uid' => max($folder->last_uid, max($chunk))])->save();
        }

        $this->refreshFlags($account, $folder, $imap);
        $folder->forceFill([
            'uid_validity' => $snapshot['uid_validity'],
            'uid_next' => $snapshot['uid_next'],
            'messages_count' => $snapshot['messages'] ?? $folder->messages_count,
            'unseen_count' => $snapshot['unseen'] ?? $folder->unseen_count,
            'synced_at' => now(),
        ])->save();

        return $stored;
    }

    public function applyFlags(Message $message, array $flags): void
    {
        $has = fn (string $flag) => (bool) array_filter($flags, fn ($v) => strtolower(ltrim((string) $v, '\\')) === $flag);
        $changes = ['is_seen' => $has('seen'), 'is_answered' => $has('answered'), 'is_flagged' => $has('flagged'), 'is_draft' => $has('draft'), 'is_deleted' => $has('deleted')];
        $dirty = array_filter($changes, fn ($v, $k) => $message->{$k} !== $v, ARRAY_FILTER_USE_BOTH);
        if (! $dirty) {
            return;
        }
        $message->forceFill($dirty)->save();
        if (isset($dirty['is_seen']) && $message->thread) {
            $this->threads->refresh($message->thread);
        }
    }

    private function store(Account $account, Folder $folder, array $snapshot, array $raw, bool $headersOnly = false): bool
    {
        $existing = Message::where('folder_id', $folder->id)->where('uid_validity', $snapshot['uid_validity'])->where('imap_uid', $raw['uid'])->first();
        if ($existing) {
            $this->applyFlags($existing, $raw['flags']);

            return false;
        }
        $peek = $this->parser->peek($raw['raw']);
        $adopted = $peek['message_id'] ? Message::where('account_id', $account->id)->where('message_id', $peek['message_id'])->orderByRaw('imap_uid is null desc')->first() : null;
        if ($adopted) {
            $adopted->forceFill([
                'folder_id' => $folder->id,
                'imap_uid' => $raw['uid'],
                'uid_validity' => $snapshot['uid_validity'],
                // Наш ответ доехал до «Отправленных» — значит APPEND состоялся.
                'appended_to_sent_at' => $adopted->isOutgoing() ? ($adopted->appended_to_sent_at ?? now()) : null,
            ])->save();
            $this->applyFlags($adopted, $raw['flags']);

            return false;
        }

        $message = Message::create([
            'account_id' => $account->id,
            'folder_id' => $folder->id,
            'direction' => $folder->kind === FolderKind::Sent || ($peek['from_email'] && $peek['from_email'] === mb_strtolower($account->email)) ? Direction::Out : Direction::In,
            'imap_uid' => $raw['uid'],
            'uid_validity' => $snapshot['uid_validity'],
            'message_id' => $peek['message_id'],
            'in_reply_to' => $peek['in_reply_to'],
            'references_header' => $peek['references'],
            'subject' => $peek['subject'],
            'subject_normalized' => $peek['subject_normalized'],
            'from_email' => $peek['from_email'],
            'from_name' => $peek['from_name'],
            'date_at' => $peek['date'],
            'internal_at' => $raw['internal_at'],
            'size' => $raw['size'],
            'is_seen' => $this->flag($raw['flags'], 'seen'),
            'is_answered' => $this->flag($raw['flags'], 'answered'),
            'is_flagged' => $this->flag($raw['flags'], 'flagged'),
            'is_draft' => $this->flag($raw['flags'], 'draft'),
            'is_deleted' => $this->flag($raw['flags'], 'deleted'),
            'raw_path' => $this->storeRaw($account, $raw['raw']),
            'parse_state' => $headersOnly ? ParseState::Failed : ParseState::Pending,
            'parse_error' => $headersOnly ? 'Письмо слишком большое, забраны только заголовки. Тело и вложения — в почтовом клиенте.' : null,
        ]);
        if (! $headersOnly) {
            ParseMessage::dispatch($message->id);
        }

        return true;
    }

    private function storeHeadersOnly(Account $account, Folder $folder, array $snapshot, array $uids, array $sizes, Imap $imap): int
    {
        $stored = 0;
        try {
            foreach ($imap->headers($folder->path, $uids) as $uid => $block) {
                Log::warning('Почта: письмо не забрано целиком', ['account' => $account->email, 'uid' => $uid, 'bytes' => $sizes[$uid] ?? 0]);
                $stored += (int) $this->store($account, $folder, $snapshot, ['uid' => (int) $uid, 'raw' => $block, 'flags' => [], 'internal_at' => null, 'size' => $sizes[$uid] ?? 0], headersOnly: true);
            }
        } catch (Throwable $e) {
            Log::error('Почта: заголовки больших писем не получены', ['folder' => $folder->path, 'error' => $e->getMessage()]);
        }

        return $stored;
    }

    private function sizes(Imap $imap, string $path, array $uids): array
    {
        try {
            return $imap->sizes($path, $uids);
        } catch (Throwable $e) {
            Log::warning('Почта: размеры писем не получены', ['folder' => $path, 'error' => $e->getMessage()]);

            return [];
        }
    }

    private function batches(array $uids, array $sizes): array
    {
        if (! $uids) {
            return [];
        }
        if (! $sizes) {
            return array_chunk($uids, self::BATCH);
        }
        $batches = [];
        $batch = [];
        $bytes = 0;
        foreach ($uids as $uid) {
            $size = $sizes[$uid] ?? 0;
            if ($batch && ($bytes + $size > self::BUDGET_BYTES || count($batch) >= self::BATCH)) {
                $batches[] = $batch;
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $uid;
            $bytes += $size;
        }
        if ($batch) {
            $batches[] = $batch;
        }

        return $batches;
    }

    private function refreshFlags(Account $account, Folder $folder, Imap $imap): void
    {
        $messages = Message::where('account_id', $account->id)->where('folder_id', $folder->id)->whereNotNull('imap_uid')
            ->where('uid_validity', $folder->uid_validity ?? 0)->orderByDesc('imap_uid')->limit(100)->get();
        if ($messages->isEmpty()) {
            return;
        }
        $flags = $imap->flags($folder->path, $messages->pluck('imap_uid')->all());
        foreach ($messages as $message) {
            if (isset($flags[$message->imap_uid])) {
                $this->applyFlags($message, $flags[$message->imap_uid]);
            }
        }
    }

    private function storeRaw(Account $account, string $raw): string
    {
        $path = sprintf('mail/%s/%s/%s.eml', $account->slug, now()->format('Y/m'), Str::uuid());
        Storage::disk(Message::DISK)->put($path, $raw);

        return $path;
    }

    private function flag(array $flags, string $flag): bool
    {
        return (bool) array_filter($flags, fn ($v) => strtolower(ltrim((string) $v, '\\')) === $flag);
    }
}
