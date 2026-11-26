<?php

namespace App\Mail;

use App\Mail\Actions\ReadEarlier;
use App\Mail\Events\MessageParsed;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Reading\ReadLetter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Разобранное письмо → база: поля, адреса, опись вложений, ветка; дальше — событие и закрепление файлов, если ветка привязана. */
final class Ingest
{
    public function __construct(private Threads $threads, private ReadLetter $reader, private ReadEarlier $readEarlier) {}

    public function apply(Message $message, array $parsed, bool $quiet = false): Message
    {
        $was = $message->thread_id;
        DB::transaction(function () use ($message, $parsed) {
            $thread = $this->threads->resolve($message->account, $parsed);
            $message->forceFill([
                'thread_id' => $thread->id,
                'message_id' => $parsed['message_id'] ?? $message->message_id,
                'in_reply_to' => $parsed['in_reply_to'],
                'references_header' => $parsed['references'],
                'subject' => $parsed['subject'] ?? $message->subject,
                'subject_normalized' => $parsed['subject_normalized'],
                'from_email' => $parsed['from_email'] ?? $message->from_email,
                'from_name' => $parsed['from_name'] ?? $message->from_name,
                'to_preview' => $parsed['to_preview'],
                'date_at' => $parsed['date'] ?? $message->date_at ?? $message->internal_at,
                'text_body' => $parsed['text_body'],
                'html_body' => $parsed['html_body'],
                'preview' => $parsed['preview'],
                'headers' => $parsed['headers'],
                'parse_state' => ParseState::Parsed,
                'parse_error' => null,
            ])->save();

            $message->addresses()->delete();
            foreach ($parsed['addresses'] as $a) {
                $message->addresses()->create(['kind' => $a['kind']->value, 'email' => $a['email'], 'name' => $a['name'], 'position' => $a['position']]);
            }
            $this->attachments($message, $parsed['attachments']);
            // Письмо читается один раз здесь: поля, номера, смысл — дальше всё считается из `parsed`.
            $this->reader->apply($message->fresh(['account', 'attachments']));
            // Пришло чужое письмо в архивную ветку — она снова во «Входящих».
            if ($thread->archived_at && $message->direction === Direction::In) {
                $thread->forceFill(['archived_at' => null])->saveQuietly();
            }
            $this->threads->refresh($thread);
        });

        $message = $message->fresh(['account', 'thread', 'attachments']);
        // Легло в ветку впервые — непрочитанным в ней остаётся только последнее (`ReadEarlier`); пересборка уже
        // лежавшего письма ничего не трогает — «Не прочитано» руками держится.
        if ($message->thread_id !== $was && in_array($message->id, ($this->readEarlier)($message), true)) {
            $message->is_seen = true;
            $message->syncOriginalAttribute('is_seen');
        }
        // Ветка уже привязана — файлы этого письма едут к машине; если привяжется сейчас (OnMessage), LinkThread поставит джобу на всю ветку.
        $linked = $message->thread?->isLinked() ?? false;

        return $this->handle($message, $quiet, $message->thread_id !== $was, $linked);
    }

    /**
     * Следствия разобранного письма — одна дверь (26.11.2026): событие (`OnMessage`: ветка, цепочки, черновик и заявка, ход сделки,
     * уведомления), непрочитанные ветки, файлы привязанной ветки; в конце — отметка `handled_at`. Разбор убили посреди (выкладка,
     * нехватка памяти) или слушатель упал — отметки нет, и дочитка `mail:sync` проходит дверь заново: шаги, что повторять нельзя, —
     * под `MessageStep::once`, остальные идемпотентны по своим данным. Повтор (`$linked` null): ветка привязана сейчас — файлы
     * письма в очередь (задача уникальна, дедуп по sha), это закрывает и обрыв между привязкой ветки и `attachNow`.
     */
    public function handle(Message $message, bool $quiet = false, bool $first = false, ?bool $linked = null): Message
    {
        // Живой синк и дочитка — не вдвоём по одному письму.
        $lock = Cache::lock('mail:handle:'.$message->id, 300);
        if (! $lock->get()) {
            return $message;
        }
        try {
            $linked ??= $message->thread?->isLinked() ?? false;
            MessageParsed::dispatch($message, $quiet);
            // Ветка привязана к делу (`OnMessage`) — непрочитанным во всей цепочке остаётся только это письмо (`ReadEarlier::arrived`).
            if ($first && ($fresh = $message->fresh(['account', 'thread'])) && in_array($message->id, $this->readEarlier->arrived($fresh), true)) {
                $message->is_seen = true;
                $message->syncOriginalAttribute('is_seen');
            }
            if ($linked && $message->thread_id) {
                ImportThreadFiles::dispatch($message->thread_id, $message->id);
            }
            Message::whereKey($message->id)->update(['handled_at' => now()]);
            $message->handled_at = now();
            $message->syncOriginalAttribute('handled_at');
        } finally {
            $lock->release();
        }

        return $message;
    }

    /** Опись вложений заново; закреплённые файлы переживают пересборку — по секции. */
    public function attachments(Message $message, array $attachments): void
    {
        $kept = $message->attachments()->whereNotNull('blob_sha')->get()->keyBy('section');
        $message->attachments()->whereNull('blob_sha')->get()->each->delete();
        $files = 0;
        foreach ($attachments as $a) {
            $old = $kept->get($a['section']);
            $row = $old ? $old->fill($a) : $message->attachments()->make($a);
            $row->save();
            $kept->forget($a['section']);
            $files += $a['is_inline'] ? 0 : 1;
        }
        $kept->each->delete();
        $message->forceFill(['has_attachments' => $files > 0, 'attachments_count' => $files])->save();
    }
}
