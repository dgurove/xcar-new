<?php

namespace App\Support;

use DateTimeInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Response;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

/**
 * Excel и Word в шторке документов: сервер собирает простой HTML (таблицы, абзацы), без стилей файла.
 * На телефоне xlsx и docx иначе открываются только в Quick Look, а из установленного приложения оттуда не выйти.
 * Весь текст экранирован — фрагмент вставляется в страницу как есть. Не разобрали — null, в шторке «Скачать».
 */
final class OfficePreview
{
    private const ROWS = 500;

    private const COLS = 40;

    /** Ответ `?preview=1`: HTML-фрагмент для шторки, не разобрали — 415 (шторка покажет «Скачать»). */
    public static function response(string $path, string $name): Response
    {
        $html = self::html($path, $name);
        abort_if($html === null, 415);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    public static function html(string $path, string $name): ?string
    {
        try {
            return match (Docs::type(null, $name)) {
                'sheet' => str_ends_with(mb_strtolower($name), '.csv') ? self::csv($path) : self::sheet($path),
                'word' => self::word($path),
                'text' => self::plain($path),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private static function sheet(string $path): ?string
    {
        $reader = new Reader(new Options);
        $reader->open($path);
        $out = '';
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $sheet->isVisible()) {
                    continue;
                }
                $rows = [];
                $width = 0;
                foreach ($sheet->getRowIterator() as $i => $row) {
                    if ($i > self::ROWS) {
                        break;
                    }
                    $cells = array_map(fn ($c) => self::cell($c instanceof FormulaCell ? $c->getComputedValue() : $c->getValue()), array_slice($row->cells, 0, self::COLS));
                    while ($cells && end($cells) === '') {
                        array_pop($cells);
                    }
                    $rows[] = $cells;
                    $width = max($width, count($cells));
                }
                while ($rows && end($rows) === []) {
                    array_pop($rows);
                }
                if (! $rows) {
                    continue;
                }
                $out .= '<h3>'.e($sheet->getName()).'</h3><div class="docs-table docs-sheet"><table>';
                foreach ($rows as $cells) {
                    $out .= '<tr>';
                    for ($c = 0; $c < $width; $c++) {
                        $out .= '<td>'.e($cells[$c] ?? '').'</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table></div>';
            }
        } finally {
            $reader->close();
        }

        return $out !== '' ? $out : null;
    }

    private static function cell(mixed $v): string
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->format($v->format('His') === '000000' ? 'd.m.Y' : 'd.m.Y H:i'),
            is_float($v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ','),
            is_int($v) => (string) $v,
            is_bool($v) => $v ? 'да' : 'нет',
            is_scalar($v) => trim((string) $v),
            default => '',
        };
    }

    /** csv: разделитель — чего в первой строке больше (`;` у русского Excel, `,`, таб); кодировка — как у txt. */
    private static function csv(string $path): ?string
    {
        $raw = (string) file_get_contents($path, length: 2_000_000);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($raw));
        $first = $lines[0] ?? '';
        $sep = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();
        $out = '<div class="docs-table docs-sheet"><table>';
        foreach (array_slice($lines, 0, self::ROWS) as $line) {
            $out .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.e(trim((string) $c)).'</td>', array_slice(str_getcsv($line, $sep, '"', ''), 0, self::COLS))).'</tr>';
        }

        return trim($raw) === '' ? null : $out.'</table></div>';
    }

    /** txt: как есть, моноширинным; старые файлы из Windows — в CP1251. */
    private static function plain(string $path): ?string
    {
        $raw = (string) file_get_contents($path, length: 2_000_000);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }

        return trim($raw) === '' ? null : '<pre>'.e($raw).'</pre>';
    }

    /** docx: абзацы и таблицы из word/document.xml, текст без оформления. */
    private static function word(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! $xml) {
            return null;
        }
        $dom = new DOMDocument;
        if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }
        $x = new DOMXPath($dom);
        $x->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $body = $x->query('/w:document/w:body')->item(0);
        $out = $body ? self::blocks($x, $body) : '';

        return trim(strip_tags($out)) !== '' ? $out : null;
    }

    private static function blocks(DOMXPath $x, DOMElement $parent): string
    {
        $out = '';
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $text = self::text($x, $node);
                $out .= $text === '' ? '' : '<p>'.$text.'</p>';
            } elseif ($node->localName === 'tbl') {
                $out .= '<div class="docs-table"><table>';
                foreach ($x->query('w:tr', $node) as $tr) {
                    $out .= '<tr>';
                    foreach ($x->query('w:tc', $tr) as $tc) {
                        $span = (int) ($x->query('w:tcPr/w:gridSpan/@w:val', $tc)->item(0)?->nodeValue ?? 1);
                        $out .= '<td'.($span > 1 ? ' colspan="'.$span.'"' : '').'>'.self::blocks($x, $tc).'</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table></div>';
            } elseif (in_array($node->localName, ['sdt', 'sdtContent', 'customXml'], true)) {
                $out .= self::blocks($x, $node);
            }
        }

        return $out;
    }

    private static function text(DOMXPath $x, DOMElement $p): string
    {
        $s = '';
        foreach ($x->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $p) as $n) {
            $s .= match ($n->localName) {
                't' => e($n->textContent),
                'tab' => ' ',
                default => '<br>',
            };
        }

        return trim($s);
    }
}
