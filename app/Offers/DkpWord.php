<?php

namespace App\Offers;

use App\Http\Admin\DealContractController;
use App\Support\Soffice;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;

/**
 * Договор в Word (19.11.2026, владелец: «не только PDF договора скачивать, но и docx, чтобы можно было что-то корректировать»).
 * Шаблон один — тот же вид, что у PDF (`DealContractController::document`), с `word`: без кнопки печати, номеров страниц и знака
 * «ОБРАЗЕЦ». Перед LibreOffice разметка переводится на язык, который Word понимает (`prepare`): рамки и ширины таблиц —
 * атрибутами, линии для ручки — подчёркиваниями, акт — с новой страницы. Файл не хранится: собирается при скачивании из данных
 * сделки, минуту живёт в кэше по отпечатку разметки.
 */
final class DkpWord
{
    public static function docx(Deal $deal): string
    {
        $html = self::html($deal);

        return Cache::remember('dkp-word:'.sha1($html), 60, fn () => self::keepTables(Soffice::convert($html, 'html', 'docx')));
    }

    public static function fileName(Deal $deal): string
    {
        return preg_replace('/\.pdf$/', '.docx', (new DkpDocument(DealContract::for($deal)))->fileName());
    }

    /** Разметка договора для Word. */
    public static function html(Deal $deal): string
    {
        return self::prepare(DealContractController::document($deal, false, word: true)->render());
    }

    public static function prepare(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $x = new DOMXPath($dom);
        $has = fn (string $class) => "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";

        // Линия для ручки — подчёркиваниями: пустой блок нужной ширины Word не рисует. Около 2 мм на знак Times 12.
        foreach (iterator_to_array($x->query("//span[{$has('blank')}]")) as $blank) {
            /** @var DOMElement $blank */
            preg_match('/width:\s*([\d.]+)mm/', $blank->getAttribute('style'), $m);
            $blank->parentNode->replaceChild($dom->createTextNode(str_repeat('_', max(6, (int) round(((float) ($m[1] ?? 30)) / 2)))), $blank);
        }
        // Пункт со строками через <br> — отдельными абзацами без отступа: Word растягивает по ширине строку перед переносом.
        foreach (iterator_to_array($x->query('//p[br]')) as $p) {
            /** @var DOMElement $p */
            $part = $p->cloneNode(false);
            $parts = [$part];
            foreach (iterator_to_array($p->childNodes) as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'br') {
                    $part = $dom->createElement('p');
                    $part->setAttribute('class', 'flat');
                    $part->setAttribute('style', 'text-indent: 0');
                    $parts[] = $part;

                    continue;
                }
                $part->appendChild($child);
            }
            foreach ($parts as $new) {
                $p->parentNode->insertBefore($new, $p);
            }
            $p->parentNode->removeChild($p);
        }
        // «Не разрывать» (`.keep`: последний пункт и подписи) — абзацы держатся со следующим, в Word это `keepNext`.
        foreach ($x->query("//*[{$has('keep')}]//p") as $p) {
            $p->setAttribute('style', trim($p->getAttribute('style').'; page-break-after: avoid', '; '));
        }
        // Таблица ТС — рамкой, левый столбец 42 %.
        foreach ($x->query("//table[{$has('car')}]") as $t) {
            self::table($t, border: true);
            foreach ($x->query('.//tr/td[1]', $t) as $td) {
                $td->setAttribute('width', '42%');
            }
        }
        // Стороны и шапка «г. Москва … г.» — без рамок, половинами.
        foreach ($x->query("//table[{$has('parties')} or {$has('head')} or {$has('keep-block')} or {$has('facts')}]") as $t) {
            self::table($t, border: false);
        }
        foreach ($x->query("//table[{$has('parties')}]/tr/td | //table[{$has('parties')}]/tbody/tr/td") as $td) {
            $td->setAttribute('width', '50%');
        }
        foreach ($x->query("//table[{$has('facts')}]") as $t) {
            $t->removeAttribute('width');
            // Отступ таблицы — пустым первым столбцом: поле слева у таблицы LibreOffice из HTML не берёт.
            foreach ($x->query('.//tr', $t) as $tr) {
                $pad = $dom->createElement('td', ' ');
                $pad->setAttribute('width', '47');
                $tr->insertBefore($pad, $tr->firstChild);
            }
        }
        foreach ($x->query("//table[{$has('head')}]//td[2]") as $td) {
            $td->setAttribute('align', 'right');
        }
        // Акт — с новой страницы.
        foreach (iterator_to_array($x->query("//div[{$has('act')}]")) as $act) {
            $br = $dom->createElement('p');
            $br->setAttribute('style', 'page-break-before: always; margin: 0');
            $act->parentNode->replaceChild($br, $act);
        }

        return (string) $dom->saveHTML($dom->documentElement);
    }

    /**
     * Таблицы в Word не рвутся (LibreOffice из HTML этого не переносит): строка не делится между страницами (`cantSplit`), а все
     * строки, кроме последней, держатся со следующей (`keepNext`) — заголовок «Продавец | Покупатель» не остаётся внизу листа
     * без подписей, таблица ТС не делится.
     */
    public static function keepTables(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dkp');
        file_put_contents($path, $docx);
        try {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
                return $docx;
            }
            $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            $dom = new DOMDocument;
            $dom->loadXML($xml);
            $x = new DOMXPath($dom);
            $x->registerNamespace('w', $w);
            $first = function (DOMElement $parent, string $name) use ($dom, $w): DOMElement {
                foreach ($parent->childNodes as $c) {
                    if ($c instanceof DOMElement && $c->localName === $name) {
                        return $c;
                    }
                }
                $el = $dom->createElementNS($w, 'w:'.$name);
                $parent->insertBefore($el, $parent->firstChild);

                return $el;
            };
            foreach ($x->query('//w:tbl') as $tbl) {
                $rows = iterator_to_array($x->query('w:tr', $tbl));
                foreach ($rows as $i => $tr) {
                    $trPr = $first($tr, 'trPr');
                    $x->query('w:cantSplit', $trPr)->length || $trPr->appendChild($dom->createElementNS($w, 'w:cantSplit'));
                    if ($i === count($rows) - 1) {
                        continue;
                    }
                    foreach ($x->query('.//w:p', $tr) as $p) {
                        $pPr = $first($p, 'pPr');
                        $x->query('w:keepNext', $pPr)->length || $pPr->insertBefore($dom->createElementNS($w, 'w:keepNext'), $pPr->firstChild);
                    }
                }
            }
            $zip->addFromString('word/document.xml', $dom->saveXML());
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private static function table(DOMElement $t, bool $border): void
    {
        $t->setAttribute('width', '100%');
        $t->setAttribute('cellspacing', '0');
        $t->setAttribute('cellpadding', $border ? '4' : '0');
        $t->setAttribute('border', $border ? '1' : '0');
        // Таблица не рвётся между страницами: подписи не уезжают на чистый лист без заголовка.
        $t->setAttribute('style', trim($t->getAttribute('style').'; page-break-inside: avoid', '; '));
        foreach ($t->getElementsByTagName('td') as $td) {
            $td->setAttribute('valign', 'top');
            $td->hasAttribute('align') || $td->setAttribute('align', 'left');
        }
    }
}
