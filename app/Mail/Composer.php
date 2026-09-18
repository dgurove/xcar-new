<?php

namespace App\Mail;

use App\Mail\Jobs\SendMessage;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Исходящее письмо: заготовка ответа/пересылки и запись в базу с постановкой в очередь. Message-ID выписывается до отправки. */
final class Composer
{
    public function __construct(private Parser $parser, private Threads $threads) {}

    public function reply(Message $parent, bool $all = false): array
    {
        $account = $parent->account;
        $to = array_filter([$parent->replyToAddress()]);
        $cc = [];
        if ($all) {
            foreach ([AddressKind::To, AddressKind::Cc] as $kind) {
                foreach ($parent->addressesOfKind($kind) as $address) {
                    $cc[] = $address->email;
                }
            }
            $cc = array_values(array_diff(array_unique($cc), $to, [mb_strtolower($account->email)]));
        }

        return [
            'to' => implode(', ', $to),
            'cc' => implode(', ', $cc),
            'subject' => $this->prefix($parent->subject, 'Re'),
            'body' => $this->signature($account).$this->quote($parent),
        ];
    }

    public function forward(Message $parent): array
    {
        return [
            'to' => '',
            'cc' => '',
            'subject' => $this->prefix($parent->subject, 'Fwd'),
            'body' => $this->signature($parent->account).$this->quote($parent, 'Пересылаемое письмо'),
            'forward' => $parent->files()->pluck('id')->all(),
        ];
    }

    public function fresh(Account $account, ?Template $template = null, array $values = []): array
    {
        $rendered = $template?->render($values) ?? ['subject' => '', 'body' => ''];

        return ['to' => $values['to'] ?? '', 'cc' => $values['cc'] ?? '', 'subject' => $rendered['subject'], 'body' => $rendered['body'].$this->signature($account)];
    }

    /**
     * @param  array{to: string, cc?: string, bcc?: string, subject: string, body: string, files?: list<string>, forward?: list<int>}  $data
     */
    public function create(Account $account, array $data, ?Message $parent, ?User $by, ?Thread $thread = null): Message
    {
        $messageId = Str::uuid().'@'.(Str::after($account->email, '@') ?: 'xcar.ru');
        $html = trim((string) ($data['body'] ?? ''));
        $text = $this->parser->htmlToText($html);
        $to = $this->emails($data['to'] ?? '');

        $message = DB::transaction(function () use ($account, $data, $parent, $by, $thread, $messageId, $html, $text, $to) {
            $subject = trim((string) ($data['subject'] ?? '')) ?: '(без темы)';
            $thread ??= $parent?->thread ?? Thread::create([
                'account_id' => $account->id, 'root_message_id' => $messageId, 'subject' => $subject,
                'subject_normalized' => $this->parser->normalizeSubject($subject), 'last_message_at' => now(),
            ]);
            $message = Message::create([
                'account_id' => $account->id,
                'thread_id' => $thread->id,
                'direction' => Direction::Out,
                'message_id' => $messageId,
                'in_reply_to' => $parent?->message_id,
                'references_header' => $parent?->referencesForReply(),
                'subject' => $subject,
                'subject_normalized' => $this->parser->normalizeSubject($subject),
                'from_email' => mb_strtolower($account->email),
                'from_name' => $account->fromName(),
                'to_preview' => mb_substr(implode(', ', $to), 0, 255),
                'date_at' => now(),
                'internal_at' => now(),
                'html_body' => $html ?: null,
                'text_body' => $text ?: null,
                'preview' => $text ? mb_substr($text, 0, 300) : null,
                'is_seen' => true,
                'parse_state' => ParseState::Parsed,
                'send_state' => SendState::Queued,
                'created_by' => $by?->id,
            ]);
            $message->addresses()->create(['kind' => 'from', 'email' => mb_strtolower($account->email), 'name' => $account->fromName(), 'position' => 0]);
            foreach (['to' => $to, 'cc' => $this->emails($data['cc'] ?? ''), 'bcc' => $this->emails($data['bcc'] ?? '')] as $kind => $list) {
                foreach ($list as $i => $email) {
                    $message->addresses()->create(['kind' => $kind, 'email' => $email, 'position' => $i]);
                }
            }
            $this->attach($message, $data, $parent);
            $this->threads->refresh($thread);

            return $message;
        });
        SendMessage::dispatch($message->id);

        return $message;
    }

    /** @return list<string> */
    public function emails(string $line): array
    {
        preg_match_all('/[^\s<>,;"]+@[^\s<>,;"]+/u', $line, $m);

        return array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e, '.')), $m[0])));
    }

    private function attach(Message $message, array $data, ?Message $parent): void
    {
        $disk = Storage::disk(Parts::CACHE_DISK);
        $position = 0;
        foreach ((array) ($data['files'] ?? []) as $path) {
            if (! str_starts_with((string) $path, 'outbox/') || ! $disk->exists($path)) {
                continue;
            }
            $message->attachments()->create([
                'filename' => substr(basename($path), 37), // после uuid и дефиса
                'mime' => $disk->mimeType($path) ?: null,
                'size' => $disk->size($path),
                'path' => $path,
                'position' => $position++,
            ]);
        }
        if ($parent && ! empty($data['forward'])) {
            // Пересылаемый файл не копируется: тот же blob, что у исходного письма.
            foreach ($parent->attachments()->whereIn('id', (array) $data['forward'])->get() as $original) {
                if (($contents = $original->contents()) === null) {
                    continue;
                }
                $message->attachments()->create(['filename' => $original->filename, 'mime' => $original->mime, 'size' => strlen($contents), 'blob_sha' => Blobs::put($contents), 'pinned_at' => now(), 'position' => $position++]);
            }
        }
        $message->forceFill(['has_attachments' => $position > 0, 'attachments_count' => $position])->save();
    }

    private function prefix(?string $subject, string $prefix): string
    {
        $clean = trim((string) $subject);
        if ($clean === '') {
            return "{$prefix}: (без темы)";
        }

        return preg_match('/^\s*'.$prefix.'\s*:/iu', $clean) ? $clean : "{$prefix}: {$clean}";
    }

    private function signature(Account $account): string
    {
        return $account->signature ? '<p></p><div>'.$account->signature.'</div>' : '<p></p>';
    }

    private function quote(Message $parent, string $title = 'Исходное письмо'): string
    {
        $source = $parent->text_body ?: $this->parser->htmlToText((string) $parent->html_body);
        $header = sprintf('%s, %s, %s:', $title, $parent->date_at?->format('d.m.Y H:i') ?? '', $parent->from_name ? "{$parent->from_name} <{$parent->from_email}>" : (string) $parent->from_email);
        $lines = array_slice(preg_split('/\R/u', trim($source)) ?: [], 0, 200);

        return '<blockquote>'.e($header).'<br>'.implode('<br>', array_map(fn ($l) => e($l), $lines)).'</blockquote>';
    }
}
