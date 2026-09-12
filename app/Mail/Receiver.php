<?php

namespace App\Mail;

/**
 * Письмо с сервера без вложений: блок заголовков и текстовые части по
 * секциям из структуры, опись вложений — из неё же. Самих файлов здесь нет:
 * они остаются в ящике и забираются по секции, когда понадобятся (Parts).
 */
final class Receiver
{
    public const MAX_TEXT_PART = 5 * 1024 * 1024;

    public function __construct(private Parser $parser) {}

    /**
     * @return array{message_id: ?string, in_reply_to: ?string, references: ?string, subject: ?string, subject_normalized: ?string, from_email: ?string, from_name: ?string, to_preview: ?string, date: ?\DateTimeImmutable, headers: array, addresses: array, text_body: ?string, html_body: ?string, preview: ?string, attachments: list<array>}
     */
    public function receive(Imap $imap, string $path, int $uid, array $structure): array
    {
        $parts = Structure::parts($structure);
        $texts = Structure::textSections($parts);
        $want = ['HEADER'];
        foreach ([...$texts['plain'], ...$texts['html']] as $p) {
            if ($p['size'] <= self::MAX_TEXT_PART) {
                $want[] = $p['section'];
            }
        }
        $fetched = $imap->parts($path, $uid, array_values(array_unique($want)));
        $parsed = $this->parser->headers($fetched['HEADER'] ?? '')
            + $this->parser->texts($this->join($texts['plain'], $fetched), $this->join($texts['html'], $fetched));
        $parsed['attachments'] = self::attachments($parts, (string) $parsed['html_body']);

        return $parsed;
    }

    /**
     * Опись вложений из частей структуры: без содержимого, с секцией и кодировкой.
     *
     * @return list<array{filename: string, mime: ?string, size: int, section: string, encoding: string, content_id: ?string, is_inline: bool, position: int}>
     */
    public static function attachments(array $parts, string $html): array
    {
        $attachments = [];
        $position = 0;
        foreach ($parts as $p) {
            if (! $p['is_attachment']) {
                continue;
            }
            $mime = $p['type'].'/'.$p['subtype'];
            $attachments[] = [
                'filename' => $p['filename'] ?? self::fallbackName($mime, $position),
                'mime' => $mime,
                // В структуре размер части в транспортной кодировке; base64 раздувает на треть.
                'size' => $p['encoding'] === 'base64' ? (int) round($p['size'] * 3 / 4) : $p['size'],
                'section' => $p['section'],
                'encoding' => $p['encoding'],
                'content_id' => $p['content_id'],
                // Inline — картинка, на которую ссылается тело, или безымянная часть с disposition inline.
                'is_inline' => ($p['content_id'] !== null && str_contains($html, 'cid:'.$p['content_id'])) || ($p['disposition'] === 'inline' && $p['filename'] === null),
                'position' => $position++,
            ];
        }

        return $attachments;
    }

    private function join(array $sections, array $fetched): string
    {
        $chunks = [];
        foreach ($sections as $p) {
            if (! isset($fetched[$p['section']])) {
                continue;
            }
            $chunks[] = Structure::toUtf8(Structure::decodeTransfer($fetched[$p['section']], $p['encoding']), $p['params']['charset'] ?? null);
        }

        return implode("\n\n", $chunks);
    }

    private static function fallbackName(string $mime, int $position): string
    {
        $ext = match ($mime) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'text/plain' => 'txt',
            'text/html' => 'html', 'application/zip' => 'zip', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => null,
        };

        return 'вложение-'.($position + 1).($ext ? '.'.$ext : '');
    }
}
