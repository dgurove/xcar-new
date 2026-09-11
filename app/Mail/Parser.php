<?php

namespace App\Mail;

use DateTimeImmutable;
use Throwable;
use Webklex\PHPIMAP\Address as ImapAddress;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Разбор сырого письма. Каждый заголовок прогоняется через iconv_mime_decode
 * ещё раз: тему из нескольких encoded-word webklex не раскодирует. Имена
 * вложений читаются из сырого письма самостоятельно — библиотека портит
 * base64 в перенесённых заголовках.
 */
final class Parser
{
    private const KEPT_HEADERS = ['message_id', 'in_reply_to', 'references', 'subject', 'thread_topic', 'list_unsubscribe', 'x_mailer', 'content_type', 'authentication_results'];

    public function parse(string $raw): array
    {
        $message = ImapMessage::fromString($raw);
        $subject = $this->decode($this->header($message, 'subject'));
        $addresses = [];
        foreach (AddressKind::cases() as $kind) {
            array_push($addresses, ...$this->addressesOf($message, $kind));
        }
        $from = collect($addresses)->firstWhere('kind', AddressKind::From);
        $to = collect($addresses)->where('kind', AddressKind::To)->values();
        $text = $this->clean((string) $message->getTextBody());
        $html = $this->clean((string) $message->getHTMLBody());

        return [
            'message_id' => $this->normalizeId($this->header($message, 'message_id')),
            'in_reply_to' => $this->normalizeId($this->header($message, 'in_reply_to')),
            'references' => $this->normalizeReferences($this->header($message, 'references')),
            'subject' => $subject ?: null,
            'subject_normalized' => $this->normalizeSubject($subject),
            'from_email' => $from['email'] ?? null,
            'from_name' => $from['name'] ?? null,
            'to_preview' => $to->isEmpty() ? null : mb_substr($to->map(fn ($a) => $a['name'] ? "{$a['name']} <{$a['email']}>" : $a['email'])->implode(', '), 0, 255),
            'date' => $this->date($message),
            'text_body' => $text ?: null,
            'html_body' => $html ?: null,
            'preview' => $this->preview($text, $html),
            'headers' => $this->headers($message),
            'addresses' => $addresses,
            'attachments' => $this->attachments($message, $html, $raw),
        ];
    }

    /** Только заголовки, без MIME: хватает, чтобы записать письмо и найти его двойника по Message-ID. */
    public function peek(string $raw): array
    {
        $head = $this->headerBlock($raw);
        $subject = $this->decode($this->rawHeader($head, 'Subject'));
        $from = $this->rawHeader($head, 'From');
        $fromEmail = $fromName = null;
        if ($from !== null) {
            if (preg_match('/<([^>]+)>/', $from, $m)) {
                $fromEmail = mb_strtolower(trim($m[1]));
                $fromName = $this->personal(trim(str_replace($m[0], '', $from)));
            } elseif (preg_match('/[^\s<>,;]+@[^\s<>,;]+/', $from, $m)) {
                $fromEmail = mb_strtolower(trim($m[0]));
            }
        }
        $date = null;
        if ($rawDate = $this->rawHeader($head, 'Date')) {
            try {
                $date = $this->local(new DateTimeImmutable($rawDate));
            } catch (Throwable) {
            }
        }

        return [
            'message_id' => $this->normalizeId($this->rawHeader($head, 'Message-ID')),
            'in_reply_to' => $this->normalizeId($this->rawHeader($head, 'In-Reply-To')),
            'references' => $this->normalizeReferences($this->rawHeader($head, 'References')),
            'subject' => $subject ?: null,
            'subject_normalized' => $this->normalizeSubject($subject),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'date' => $date,
        ];
    }

    public function normalizeSubject(?string $subject): ?string
    {
        $value = trim((string) $subject);
        if ($value === '') {
            return null;
        }
        $prefixes = 're|re\[\d+\]|rе|fw|fwd|отв|ответ|пересылка';
        do {
            $previous = $value;
            $value = preg_replace('/^\s*(?:'.$prefixes.')\s*:\s*/iu', '', $value) ?? $value;
        } while ($value !== $previous);
        $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    public function normalizeId(?string $id): ?string
    {
        $value = trim(trim((string) $id), '<>');

        return $value !== '' ? mb_substr($value, 0, 512) : null;
    }

    /** @return list<string> */
    public function referenceChain(?string $references): array
    {
        if (trim((string) $references) === '') {
            return [];
        }

        return array_values(array_filter(array_map(fn ($id) => $this->normalizeId($id), preg_split('/\s+/', trim($references), -1, PREG_SPLIT_NO_EMPTY) ?: [])));
    }

    public function htmlToText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</tr>|</blockquote>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    private function normalizeReferences(?string $references): ?string
    {
        $chain = $this->referenceChain($references);

        return $chain ? implode(' ', array_unique($chain)) : null;
    }

    private function headerBlock(string $raw): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $head = explode("\n\n", $normalized, 2)[0] ?? '';

        return preg_replace('/\n[ \t]+/', ' ', $head) ?? $head;
    }

    private function rawHeader(string $head, string $name): ?string
    {
        if (! preg_match('/^'.preg_quote($name, '/').'[ \t]*:[ \t]*(.*)$/mi', $head, $m)) {
            return null;
        }

        return trim($m[1]) ?: null;
    }

    private function header(ImapMessage $message, string $name): ?string
    {
        try {
            $value = $message->getHeader()->get($name)->first();
        } catch (Throwable) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function headers(ImapMessage $message): array
    {
        $headers = [];
        foreach (self::KEPT_HEADERS as $name) {
            $value = $this->decode($this->header($message, $name));
            if ($value !== '') {
                $headers[$name] = mb_substr($value, 0, 2000);
            }
        }

        return $headers;
    }

    private function addressesOf(ImapMessage $message, AddressKind $kind): array
    {
        $attribute = match ($kind) {
            AddressKind::From => $message->getFrom(),
            AddressKind::To => $message->getTo(),
            AddressKind::Cc => $message->getCc(),
            AddressKind::Bcc => $message->getBcc(),
            AddressKind::ReplyTo => $message->getReplyTo(),
        };
        $addresses = [];
        $position = 0;
        foreach ((array) $attribute?->toArray() as $address) {
            if (! $address instanceof ImapAddress || trim((string) $address->mail) === '') {
                continue;
            }
            $addresses[] = ['kind' => $kind, 'email' => mb_strtolower(trim((string) $address->mail)), 'name' => $this->personal($address->personal), 'position' => $position++];
        }

        return $addresses;
    }

    private function personal(mixed $personal): ?string
    {
        $name = trim($this->decode(is_scalar($personal) ? (string) $personal : null), " \t\"'");

        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    private function date(ImapMessage $message): ?DateTimeImmutable
    {
        try {
            $date = $message->getDate()->first();

            return $date ? $this->local(new DateTimeImmutable($date->toDateTimeString(), $date->getTimezone())) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** В часовой пояс приложения: Eloquent пишет дату как есть, не переводя пояс. */
    private function local(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone((string) config('app.timezone')));
    }

    private function attachments(ImapMessage $message, string $html, string $raw): array
    {
        $attachments = [];
        $position = 0;
        $repairs = $this->filenameRepairs($raw);
        foreach ($message->getAttachments() as $attachment) {
            try {
                $contents = (string) $attachment->getContent();
            } catch (Throwable) {
                continue;
            }
            if ($contents === '') {
                continue;
            }
            $contentId = $this->normalizeId(is_scalar($attachment->getId()) ? (string) $attachment->getId() : null);
            $mime = is_scalar($attachment->getMimeType()) ? (string) $attachment->getMimeType() : null;
            $disposition = is_scalar($attachment->getDisposition()) ? strtolower((string) $attachment->getDisposition()) : '';
            $attachments[] = [
                'filename' => $this->attachmentName($attachment->getName(), $contentId, $mime, $contents, $position, $repairs),
                'mime' => $mime,
                'contents' => $contents,
                'content_id' => $contentId,
                // Inline — не «есть Content-ID», а «на него ссылается тело».
                'is_inline' => $disposition === 'inline' || ($contentId !== null && str_contains($html, 'cid:'.$contentId)),
                'position' => $position++,
            ];
        }

        return $attachments;
    }

    private function attachmentName(mixed $name, ?string $contentId, ?string $mime, string $contents, int $position, array $repairs): string
    {
        $raw = is_scalar($name) ? (string) $name : null;
        $name = $this->decode($raw);
        if ($raw !== null && str_contains($name, '=?')) {
            $name = $repairs[$this->filenameKey($raw)] ?? $name;
        }
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));
        if ($name !== '' && ! ($contentId !== null && $name === $contentId)) {
            return mb_substr($name, 0, 200);
        }
        if ($mime === 'message/rfc822') {
            $subject = $this->nestedSubject($contents);

            return mb_substr($subject ? 'Письмо — '.$subject : 'Письмо '.($position + 1), 0, 200).'.eml';
        }
        $ext = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'text/plain' => 'txt',
            'text/html' => 'html', 'application/zip' => 'zip', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => null,
        };

        return 'вложение-'.($position + 1).($ext ? '.'.$ext : '');
    }

    /** Имена из сырого письма: заголовок разворачивается, соседние encoded-word склеиваются, раскодируется целиком. */
    private function filenameRepairs(string $raw): array
    {
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        if (preg_match_all('/(?:file)?name\s*=\s*"([^"]*)"/i', $unfolded, $m) < 1) {
            return [];
        }
        $repairs = [];
        foreach (array_unique($m[1]) as $candidate) {
            if (! str_contains($candidate, '=?')) {
                continue;
            }
            $decoded = $this->decode($candidate);
            if ($decoded !== '' && ! str_contains($decoded, '=?')) {
                $repairs[$this->filenameKey($candidate)] = $decoded;
            }
        }

        return $repairs;
    }

    /** Ключ сравнения — начинка первого encoded-word без ничего, кроме букв и цифр: первое слово уцелевает всегда. */
    private function filenameKey(string $name): string
    {
        if (preg_match('/=\?[^?]+\?[BbQq]\?([^?]*)/', $name, $m)) {
            $name = $m[1];
        }

        return (string) preg_replace('/[^A-Za-z0-9]/', '', $name);
    }

    private function nestedSubject(string $contents): ?string
    {
        $head = explode("\r\n\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $contents)), 2)[0] ?? '';
        $head = preg_replace('/\r\n[ \t]+/', ' ', $head) ?? $head;
        if (! preg_match('/^Subject:\s*(.+)$/mi', $head, $m)) {
            return null;
        }
        $subject = preg_replace('/[\r\n]+/', ' ', $this->decode(trim($m[1])));

        return $subject !== '' ? mb_substr($subject, 0, 120) : null;
    }

    private function preview(string $text, string $html): ?string
    {
        $source = trim(preg_replace('/\s+/u', ' ', $text !== '' ? $text : $this->htmlToText($html)) ?? '');

        return $source !== '' ? mb_substr($source, 0, 300) : null;
    }

    private function decode(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '=?')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }

        return $this->clean($value);
    }

    private function clean(string $value): string
    {
        $value = str_replace("\0", '', $value);
        if (mb_check_encoding($value, 'UTF-8')) {
            return trim($value);
        }
        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return trim(is_string($converted) ? $converted : '');
    }
}
