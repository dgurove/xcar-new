<?php

namespace App\Mail\Jobs;

use App\Mail\Events\MessageParsed;
use App\Mail\Message;
use App\Mail\ParseState;
use App\Mail\Parser;
use App\Mail\Threads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Разбор MIME отдельно от приёма: кривое письмо получает «не разобралось» и остаётся видимым с темой из заголовков. */
final class ParseMessage implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTACHMENT = 25 * 1024 * 1024;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
        // Долгое соединение: у штатного retry_after 90 с, письмо разбирается дольше.
        $this->onConnection('database-long')->onQueue('mail');
    }

    public function handle(Parser $parser, Threads $threads): void
    {
        $message = Message::find($this->messageId);
        if (! $message) {
            return;
        }
        $raw = $message->rawContents();
        if ($raw === null) {
            $this->fail($message, $threads, 'Сырое письмо не найдено на диске');

            return;
        }
        try {
            $parsed = $parser->parse($raw);
        } catch (Throwable $e) {
            Log::error('Почта: письмо не разобралось', ['message' => $message->id, 'error' => $e->getMessage()]);
            $this->fail($message, $threads, $e->getMessage());

            return;
        }

        DB::transaction(function () use ($message, $parsed, $threads) {
            $thread = $threads->resolve($message->account, $parsed);
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

            // Перезапуск разбора не плодит копии файлов.
            foreach ($message->attachments()->get() as $old) {
                Storage::disk(Message::DISK)->delete($old->path);
                $old->delete();
            }
            $stored = 0;
            foreach ($parsed['attachments'] as $a) {
                if (strlen($a['contents']) > self::MAX_ATTACHMENT) {
                    Log::warning('Почта: вложение больше потолка, пропущено', ['message' => $message->id, 'filename' => $a['filename']]);
                    continue;
                }
                $safe = mb_substr(trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $a['filename']) ?? 'file', '_'), 0, 150) ?: 'file';
                $path = sprintf('mail/%s/attachments/%s/%s-%s', $message->account->slug, $message->id, Str::uuid(), $safe);
                Storage::disk(Message::DISK)->put($path, $a['contents']);
                $message->attachments()->create([
                    'filename' => $a['filename'], 'mime' => $a['mime'], 'size' => strlen($a['contents']), 'path' => $path,
                    'content_id' => $a['content_id'], 'is_inline' => $a['is_inline'], 'position' => $a['position'],
                ]);
                $stored++;
            }
            $message->forceFill(['has_attachments' => $stored > 0, 'attachments_count' => $stored])->save();
            $threads->refresh($thread);
        });

        MessageParsed::dispatch($message->fresh(['account', 'thread', 'attachments']));
    }

    private function fail(Message $message, Threads $threads, string $error): void
    {
        $message->forceFill(['parse_state' => ParseState::Failed, 'parse_error' => mb_substr($error, 0, 2000)])->save();
        if ($message->thread_id) {
            return;
        }
        $thread = $threads->resolve($message->account, [
            'message_id' => $message->message_id, 'in_reply_to' => $message->in_reply_to, 'references' => $message->references_header,
            'subject' => $message->subject, 'subject_normalized' => $message->subject_normalized, 'date' => $message->date_at, 'addresses' => [],
        ]);
        $message->forceFill(['thread_id' => $thread->id])->save();
        $threads->refresh($thread);
    }
}
