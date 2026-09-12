<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Зеркало ящика. Письмо целиком не скачивается: по структуре берутся
 * заголовки и текст, вложения остаются в ящике описью с номерами секций.
 * Ключ идемпотентности — (folder, uid_validity, uid); одно письмо в ящике —
 * одна запись: двойник по Message-ID переезжает за письмом, а не заводится
 * второй раз.
 */
final class Sync
{
    public const BATCH = 50;

    public function __construct(private Receiver $receiver, private Ingest $ingest, private Threads $threads) {}

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
        $stored = 0;
        $halted = false;
        foreach (array_chunk($uids, self::BATCH) as $chunk) {
            $rows = $imap->structures($folder->path, $chunk);
            foreach ($chunk as $uid) {
                if (! isset($rows[$uid])) {
                    continue; // письмо исчезло между SEARCH и FETCH
                }
                try {
                    $stored += (int) $this->store($account, $folder, $snapshot, $imap, $uid, $rows[$uid]);
                } catch (Throwable $e) {
                    // Непринятое письмо не пропадает: last_uid остаётся перед ним, uid_next не
                    // запоминается — следующий синк увидит папку изменившейся и заберёт его снова.
                    Log::error('Почта: письмо не записалось', ['account' => $account->email, 'folder' => $folder->path, 'uid' => $uid, 'error' => $e->getMessage()]);
                    $halted = true;
                    break 2;
                }
                $folder->forceFill(['last_uid' => max($folder->last_uid, $uid)])->save();
            }
        }

        $this->refreshFlags($account, $folder, $imap);
        $folder->forceFill([
            'uid_validity' => $snapshot['uid_validity'],
            'uid_next' => $halted ? $folder->uid_next : $snapshot['uid_next'],
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

    private function store(Account $account, Folder $folder, array $snapshot, Imap $imap, int $uid, array $row): bool
    {
        $existing = Message::where('folder_id', $folder->id)->where('uid_validity', $snapshot['uid_validity'])->where('imap_uid', $uid)->first();
        if ($existing) {
            $this->applyFlags($existing, $row['flags']);

            return false;
        }
        try {
            $parsed = $this->receiver->receive($imap, $folder->path, $uid, $row['structure']);
        } catch (Throwable $e) {
            // Письмо не прочиталось (обрыв, кривой MIME) — строка всё равно заводится, «ждёт разбора»:
            // mail:reconcile перезапустит ParseMessage, который заберёт его по UID заново.
            Log::warning('Почта: письмо принято без разбора', ['account' => $account->email, 'folder' => $folder->path, 'uid' => $uid, 'error' => $e->getMessage()]);
            Message::create(array_merge($this->skeleton($account, $folder, $snapshot, $uid, $row), ['parse_error' => mb_substr($e->getMessage(), 0, 2000)]));

            return true;
        }
        $adopted = $parsed['message_id'] ? Message::where('account_id', $account->id)->where('message_id', $parsed['message_id'])->orderByRaw('imap_uid is null desc')->first() : null;
        if ($adopted) {
            $adopted->forceFill([
                'folder_id' => $folder->id,
                'imap_uid' => $uid,
                'uid_validity' => $snapshot['uid_validity'],
                // Наш ответ доехал до «Отправленных» — значит APPEND состоялся.
                'appended_to_sent_at' => $adopted->isOutgoing() ? ($adopted->appended_to_sent_at ?? now()) : null,
            ])->save();
            // Файлы нашего письма теперь лежат в ящике: опись по секциям, локальные копии больше не нужны.
            $this->ingest->attachments($adopted, $parsed['attachments']);
            $this->applyFlags($adopted, $row['flags']);

            return false;
        }

        $message = Message::create(array_merge($this->skeleton($account, $folder, $snapshot, $uid, $row), [
            'direction' => $folder->kind === FolderKind::Sent || ($parsed['from_email'] && $parsed['from_email'] === mb_strtolower($account->email)) ? Direction::Out : Direction::In,
            'message_id' => $parsed['message_id'],
            'subject' => $parsed['subject'],
            'from_email' => $parsed['from_email'],
            'from_name' => $parsed['from_name'],
            'date_at' => $parsed['date'],
        ]));
        $this->ingest->apply($message, $parsed);

        return true;
    }

    /** Поля письма, известные ещё до чтения: место в ящике, флаги, размер. */
    private function skeleton(Account $account, Folder $folder, array $snapshot, int $uid, array $row): array
    {
        return [
            'account_id' => $account->id,
            'folder_id' => $folder->id,
            'direction' => $folder->kind === FolderKind::Sent ? Direction::Out : Direction::In,
            'imap_uid' => $uid,
            'uid_validity' => $snapshot['uid_validity'],
            'internal_at' => $row['internal_at'],
            'date_at' => $row['internal_at'],
            'size' => $row['size'],
            'is_seen' => $this->flag($row['flags'], 'seen'),
            'is_answered' => $this->flag($row['flags'], 'answered'),
            'is_flagged' => $this->flag($row['flags'], 'flagged'),
            'is_draft' => $this->flag($row['flags'], 'draft'),
            'is_deleted' => $this->flag($row['flags'], 'deleted'),
            'parse_state' => ParseState::Pending,
        ];
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

    private function flag(array $flags, string $flag): bool
    {
        return (bool) array_filter($flags, fn ($v) => strtolower(ltrim((string) $v, '\\')) === $flag);
    }
}
