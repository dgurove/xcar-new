<?php

namespace App\Mail;

use App\Mail\Events\MessageParsed;
use App\Mail\Jobs\ImportThreadFiles;
use Illuminate\Support\Facades\DB;

/** Разобранное письмо → база: поля, адреса, опись вложений, ветка; дальше — событие и закрепление файлов, если ветка привязана. */
final class Ingest
{
    public function __construct(private Threads $threads) {}

    public function apply(Message $message, array $parsed): Message
    {
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
            $this->threads->refresh($thread);
        });

        $message = $message->fresh(['account', 'thread', 'attachments']);
        // Ветка уже привязана — файлы этого письма едут к машине; если привяжется сейчас (OnMessage), LinkThread поставит джобу на всю ветку.
        $linked = $message->thread?->isLinked() ?? false;
        MessageParsed::dispatch($message);
        if ($linked) {
            ImportThreadFiles::dispatch($message->thread_id, $message->id);
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
