<?php

namespace App\Mail;

/**
 * BODYSTRUCTURE письма: разбор ответа сервера и обход дерева MIME. Даёт список
 * частей с номерами секций (1, 1.2, 3), по которым потом забирается ровно та
 * часть, что нужна: текст — при приёме, вложение — когда его открыли.
 * Свой разбор, потому что webklex режет `"utf-8")` пополам.
 */
final class Structure
{
    /**
     * Строки ответа на FETCH → uid → пары ключ/значение (BODYSTRUCTURE, FLAGS, INTERNALDATE, RFC822.SIZE).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fromResponse(array $lines): array
    {
        $rows = [];
        foreach (self::tokenize(implode('', $lines)) as $item) {
            // Ответ: * n FETCH (ключ значение …)
            if (! is_array($item) || ($item[0] ?? null) !== '*' || ($item[2] ?? null) !== 'FETCH' || ! is_array($item[3] ?? null)) {
                continue;
            }
            $pairs = [];
            $list = $item[3];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $pairs[(string) $list[$i]] = $list[$i + 1];
            }
            if (isset($pairs['UID'])) {
                $rows[(int) $pairs['UID']] = $pairs;
            }
        }

        return $rows;
    }

    /**
     * Листья дерева: секция, тип, параметры, кодировка, размер, имя, признак вложения.
     *
     * @return list<array{section: string, type: string, subtype: string, params: array<string, string>, content_id: ?string, encoding: string, size: int, filename: ?string, disposition: ?string, is_attachment: bool}>
     */
    public static function parts(mixed $structure): array
    {
        if (! is_array($structure) || ! $structure) {
            return [];
        }
        $parts = [];
        self::walk($structure, '', $parts, true);

        return $parts;
    }

    /** Секции текстовых частей письма (не вложений) по типу: ['plain' => [...], 'html' => [...]]. */
    public static function textSections(array $parts): array
    {
        $sections = ['plain' => [], 'html' => []];
        foreach ($parts as $p) {
            if ($p['type'] === 'text' && in_array($p['subtype'], ['plain', 'html'], true) && ! $p['is_attachment']) {
                $sections[$p['subtype']][] = $p;
            }
        }

        return $sections;
    }

    private static function walk(array $node, string $prefix, array &$parts, bool $top): void
    {
        if (is_array($node[0] ?? null)) {
            // multipart: (часть)(часть)… "subtype" (params) disposition …
            $i = 1;
            foreach ($node as $child) {
                if (! is_array($child)) {
                    break;
                }
                self::walk($child, $prefix === '' ? (string) $i : "$prefix.$i", $parts, false);
                $i++;
            }

            return;
        }
        $type = strtolower(self::str($node[0] ?? ''));
        $subtype = strtolower(self::str($node[1] ?? ''));
        $params = self::params($node[2] ?? null);
        $contentId = self::str($node[3] ?? null);
        $encoding = strtolower(self::str($node[5] ?? '') ?: '7bit');
        $size = (int) self::str($node[6] ?? 0);
        // После size: у text — lines, у message/rfc822 — envelope, body, lines; дальше md5, disposition, language, location.
        $rest = match (true) {
            $type === 'text' => array_slice($node, 8),
            $type === 'message' && $subtype === 'rfc822' => array_slice($node, 10),
            default => array_slice($node, 7),
        };
        $disposition = null;
        $dispositionParams = [];
        if (is_array($rest[1] ?? null)) {
            $disposition = strtolower(self::str($rest[1][0] ?? ''));
            $dispositionParams = self::params($rest[1][1] ?? null);
        }
        $filename = self::filename($dispositionParams['filename'] ?? null) ?? self::filename($params['name'] ?? null);
        if ($filename === null && $type === 'message' && $subtype === 'rfc822') {
            $subject = is_array($node[7] ?? null) ? self::decodeWords(self::str($node[7][1] ?? '')) : '';
            $filename = ($subject !== '' ? 'Письмо — '.mb_substr($subject, 0, 120) : 'Письмо').'.eml';
        }
        $isText = $type === 'text' && in_array($subtype, ['plain', 'html'], true);
        $parts[] = [
            'section' => $prefix === '' ? '1' : $prefix,
            'type' => $type,
            'subtype' => $subtype,
            'params' => $params,
            'content_id' => $contentId !== '' && strtoupper($contentId) !== 'NIL' ? trim($contentId, '<>') : null,
            'encoding' => $encoding,
            'size' => $size,
            'filename' => $filename,
            'disposition' => $disposition ?: null,
            'is_attachment' => ! $isText || $disposition === 'attachment' || $filename !== null,
        ];
    }

    /** @return array<string, string> ключи в нижнем регистре, RFC 2231 склеен */
    private static function params(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        $raw = [];
        for ($i = 0; $i + 1 < count($list); $i += 2) {
            $raw[strtolower(trim(self::str($list[$i])))] = self::str($list[$i + 1]);
        }
        $params = [];
        foreach ($raw as $key => $value) {
            // name*0*, name*1* — куски; name* — одним куском; и там и там charset''percent-encoded.
            if (str_contains($key, '*') && preg_match('/^(.+?)(?:\*(\d+))?(\*)?$/', $key, $m)) {
                $params[$m[1]]['pieces'][(int) ($m[2] ?? 0)] = ['value' => $value, 'encoded' => ($m[3] ?? '') === '*'];
            } else {
                $params[$key]['plain'] = $value;
            }
        }
        $out = [];
        foreach ($params as $key => $v) {
            if (isset($v['pieces'])) {
                ksort($v['pieces']);
                $charset = 'utf-8';
                $joined = '';
                foreach ($v['pieces'] as $n => $piece) {
                    $value = $piece['value'];
                    if ($piece['encoded']) {
                        if ($n === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $value, $m)) {
                            $charset = $m[1] ?: 'utf-8';
                            $value = $m[2];
                        }
                        $value = rawurldecode($value);
                    }
                    $joined .= $value;
                }
                $out[$key] = self::toUtf8($joined, $charset);
            } else {
                $out[$key] = $v['plain'];
            }
        }

        return $out;
    }

    private static function filename(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $name = trim(str_replace(['/', '\\', "\0"], '_', self::decodeWords(trim($value, '"'))));

        return $name !== '' ? mb_substr($name, 0, 200) : null;
    }

    private static function decodeWords(string $value): string
    {
        if (str_contains($value, '=?')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }

        return self::toUtf8($value, 'utf-8');
    }

    public static function toUtf8(string $value, ?string $charset): string
    {
        $charset = strtolower(trim((string) $charset)) ?: 'utf-8';
        if (in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }
            $charset = 'windows-1251'; // кириллица без объявления — почти всегда она
        }
        $converted = @mb_convert_encoding($value, 'UTF-8', $charset === 'cp1251' ? 'windows-1251' : $charset);
        if (! is_string($converted) || $converted === '') {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'windows-1251');
        }

        return is_string($converted) ? $converted : $value;
    }

    /** Транспортная кодировка части → байты. */
    public static function decodeTransfer(string $raw, string $encoding): string
    {
        return match (strtolower($encoding)) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $raw) ?? '', false),
            'quoted-printable' => quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Лексер IMAP-ответа: списки в скобках, строки в кавычках, литералы {n}, атомы.
     * Возвращает список строк ответа, каждая — вложенный массив токенов.
     *
     * @return list<array>
     */
    public static function tokenize(string $input): array
    {
        $lines = [];
        $stack = [];
        $current = [];
        $len = strlen($input);
        $i = 0;
        while ($i < $len) {
            $c = $input[$i];
            if ($c === ' ' || $c === "\t") {
                $i++;
            } elseif ($c === "\r" || $c === "\n") {
                if (! $stack && $current) {
                    $lines[] = $current;
                    $current = [];
                }
                $i++;
            } elseif ($c === '(') {
                $stack[] = $current;
                $current = [];
                $i++;
            } elseif ($c === ')') {
                $child = $current;
                $current = array_pop($stack) ?? [];
                $current[] = $child;
                $i++;
            } elseif ($c === '"') {
                $j = $i + 1;
                $value = '';
                while ($j < $len && $input[$j] !== '"') {
                    if ($input[$j] === '\\' && $j + 1 < $len) {
                        $j++;
                    }
                    $value .= $input[$j];
                    $j++;
                }
                $current[] = $value;
                $i = $j + 1;
            } elseif ($c === '{' && preg_match('/\{(\d+)\}\r?\n/A', $input, $m, 0, $i)) {
                $start = $i + strlen($m[0]);
                $current[] = substr($input, $start, (int) $m[1]);
                $i = $start + (int) $m[1];
            } else {
                $j = $i;
                while ($j < $len && ! in_array($input[$j], [' ', "\t", "\r", "\n", '(', ')'], true)) {
                    $j++;
                }
                $atom = substr($input, $i, $j - $i);
                $current[] = strtoupper($atom) === 'NIL' ? null : $atom;
                $i = $j;
            }
        }
        if ($current) {
            $lines[] = $current;
        }

        return $lines;
    }
}
