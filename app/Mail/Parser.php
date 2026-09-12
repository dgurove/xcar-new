<?php

namespace App\Mail;

use DateTimeImmutable;
use Throwable;
use Webklex\PHPIMAP\Address as ImapAddress;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Разбор письма по частям: блок заголовков — через webklex (адреса, дата),
 * каждый заголовок прогоняется через iconv_mime_decode ещё раз: тему из
 * нескольких encoded-word библиотека не раскодирует. Тексты приходят уже
 * раскодированными частями (см. Receiver), имена вложений — из структуры.
 */
final class Parser
{
    private const KEPT_HEADERS = ['message_id', 'in_reply_to', 'references', 'subject', 'thread_topic', 'list_unsubscribe', 'x_mailer', 'content_type', 'authentication_results'];

    /** Всё, что даёт блок заголовков: идентификаторы, тема, адреса, дата. */
    public function headers(string $block): array
    {
        $message = ImapMessage::fromString(rtrim($block)."\r\n\r\n");
        $subject = $this->decode($this->header($message, 'subject'));
        $addresses = [];
        foreach (AddressKind::cases() as $kind) {
            array_push($addresses, ...$this->addressesOf($message, $kind));
        }
        $from = collect($addresses)->firstWhere('kind', AddressKind::From);
        $to = collect($addresses)->where('kind', AddressKind::To)->values();

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
            'headers' => $this->keptHeaders($message),
            'addresses' => $addresses,
        ];
    }

    /** Текст и HTML письма (уже в UTF-8) → поля для базы. */
    public function texts(string $text, string $html): array
    {
        $text = $this->clean($text);
        $html = $this->clean($html);

        return [
            'text_body' => $text ?: null,
            'html_body' => $html ?: null,
            'preview' => $this->preview($text, $html),
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

    private function header(ImapMessage $message, string $name): ?string
    {
        try {
            $value = $message->getHeader()->get($name)->first();
        } catch (Throwable) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function keptHeaders(ImapMessage $message): array
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
