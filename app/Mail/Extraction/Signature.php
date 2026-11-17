<?php

namespace App\Mail\Extraction;

/**
 * Подпись письма — не показывается ни в письме, ни в строке почты (16.11.2026, владелец по письму Совкомбанка «Спасибо 💐 Татьяна
 * Кузнецова Специалист Управление урегулирования убытков КАСКО …»: «подписи НЕ надо отображать»). Начало подписи — строка:
 * «С уважением» и родня, «-- », «Отправлено из …», оговорка о конфиденциальности или имя отправителя целиком (слова строки —
 * из его имени: «Татьяна Кузнецова» у «Кузнецова Татьяна Анатольевна»). Всё с неё и ниже — подпись.
 */
final class Signature
{
    private const START = '/^(?:с\s+уважением|c\s+уважением|с\s+наилучшими\s+пожеланиями|всего\s+(?:доброго|хорошего)|best\s+regards|kind\s+regards|regards|with\s+regards|--\s*$|информация,\s+содержащаяся\s+в\s+(?:настоящем|этом|данном)|this\s+(?:e-?mail|message)\s+(?:and\s+any|is\s+confidential|contains))/iu';

    /** Приписка телефона — сама строка, не подпись: под ней бывает пересылка («Отправлено из мобильной Почты Mail» над «Пересылаемым сообщением»). */
    private const DROP = '/^(?:отправлено\s+(?:из|с)\b|sent\s+from\b|get\s+outlook\b)/iu';

    public static function dropped(string $line): bool
    {
        return (bool) preg_match(self::DROP, trim(str_replace("\u{00A0}", ' ', $line)));
    }

    /** Слова имени отправителя строчными; меньше двух — имени нет («Storage», адрес вместо имени). @return list<string> */
    public static function nameWords(?string $name): array
    {
        $words = preg_split('/[^\pL-]+/u', mb_strtolower(str_replace('ё', 'е', (string) $name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn ($w) => mb_strlen($w) > 1));

        return count($words) >= 2 ? $words : [];
    }

    /** Строка начинает подпись. */
    public static function starts(string $line, array $name): bool
    {
        $line = trim(str_replace("\u{00A0}", ' ', $line));
        if ($line === '') {
            return false;
        }
        if (preg_match(self::START, $line)) {
            return true;
        }
        if ($name === []) {
            return false;
        }
        $words = preg_split('/[^\pL-]+/u', mb_strtolower(str_replace('ё', 'е', $line)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Имя в «От» латиницей, в подписи — кириллицей («Marina Kardysheva» и «Марина Кардышева», 17.11.2026): слова сравниваются
        // транслитом.
        return count($words) >= 2 && count($words) <= 4
            && (array_diff($words, $name) === [] || array_diff(array_map(self::latin(...), $words), array_map(self::latin(...), $name)) === []);
    }

    private const LATIN = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];

    /** Слово латиницей: кириллица — транслитом, «kh» и «h», «iy» и «y» — одним видом. */
    private static function latin(string $word): string
    {
        $w = strtr($word, self::LATIN);

        return str_replace(['kh', 'iy', 'yy', 'j'], ['h', 'y', 'y', 'y'], $w);
    }

    /**
     * Строка-должность прямо над именем («Специалист», «Ведущий эксперт»): до трёх слов, без знаков конца фразы и не
     * благодарность — уходит в подпись вместе с именем.
     */
    public static function title(string $line): bool
    {
        $line = trim(str_replace("\u{00A0}", ' ', $line));
        $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words !== [] && count($words) <= 3 && ! preg_match('/[.!?:,;)]/u', $line)
            && ! preg_match('/^(?:спасибо|благодарю|пожалуйста|жду|ждем|ждём|ок|хорошо|добрый|здравствуйте)/iu', $line);
    }

    /** Текст без подписи. */
    public static function cut(string $text, ?string $name): string
    {
        return self::split($text, $name)[0];
    }

    /** Текст и его подпись отдельно (письмо справа во «Входящих» показывает её свёрнутой). @return array{0: string, 1: string} */
    public static function split(string $text, ?string $name): array
    {
        $words = self::nameWords($name);
        $lines = preg_split('/\R/u', $text) ?: [];
        $kept = [];
        $sign = [];
        foreach ($lines as $i => $line) {
            if (self::starts($line, $words)) {
                // Начали с имени — должность строкой выше тоже подпись.
                if (! preg_match(self::START, trim($line)) && ($j = array_key_last(array_filter($kept, fn ($l) => trim($l) !== ''))) !== null && self::title($kept[$j])) {
                    $sign = array_splice($kept, $j);
                }
                $sign = [...$sign, ...array_slice($lines, $i)];
                break;
            }
            if (! self::dropped($line)) {
                $kept[] = $line;
            }
        }

        return [rtrim(implode("\n", $kept)), trim(implode("\n", $sign))];
    }

    /**
     * Подпись из HTML письма: известные обёртки подписи (Gmail, Outlook, mail.ru) и всё с первой строки-начала подписи. Строка —
     * текстовый кусок между `<br>` или элемент без блоков внутри. Меняет документ на месте; true — что-то срезано.
     */
    public static function cutDom(\DOMDocument $dom, \DOMElement $body, ?string $name, ?\DOMElement $fold = null): bool
    {
        $xp = new \DOMXPath($dom);
        $start = $xp->query("//body//*[contains(concat(' ', normalize-space(@class), ' '), ' gmail_signature ') or @id='Signature' or @id='signature' or @data-signature-widget or contains(concat(' ', normalize-space(@class), ' '), ' signature ')]")?->item(0);
        $words = self::nameWords($name);
        $lines = $xp->query('//body//text()[normalize-space()] | //body//*[not(self::br) and not(.//p or .//div or .//table or .//tr or .//td or .//li or .//br)][normalize-space()]');
        $drop = [];
        $prev = null;
        foreach ($lines ?: [] as $node) {
            if (self::dropped($node->textContent)) {
                $drop[] = $node;

                continue;
            }
            if (self::starts($node->textContent, $words)) {
                // Начали с имени — должность строкой выше тоже подпись («Специалист» над «Марина Кардышева»).
                if ($prev && ! preg_match(self::START, trim(str_replace("\u{00A0}", ' ', $node->textContent))) && self::title($prev->textContent) && ! $prev->contains($node)) {
                    $node = $prev;
                }
                // Раньше обёртки подписи — она и есть начало.
                if (! $start || self::before($node, $start)) {
                    $start = $node;
                }
                break;
            }
            // Строка — самый глубокий кусок: элемент со строкой внутри не затирает её текстовый узел.
            if (! ($prev && $node->contains($prev))) {
                $prev = $node;
            }
        }
        foreach ($drop as $node) {
            if (! $start || self::before($node, $start)) {
                $node->parentNode?->removeChild($node);
            }
        }
        if (! $start) {
            return $drop !== [];
        }
        // Подпись — с начала и всё ниже, по уровням вверх до тела письма; fold — не выбросить, а собрать туда по порядку.
        // Каждый кусок помнит, в чём стоял: в fold он ложится в копии своих обёрток (17.11.2026, владелец: «у них подпись более
        // навороченно оформлена» — цвет, жирность и шрифт подписи висят на `<span style>` и `<b>` вокруг строк, а куски
        // переносились без них: имя Совкомбанка из синего полужирного становилось простым, красная черта — серой).
        $moved = [];
        for ($node = $start; $node && ! $node->isSameNode($body); $node = $node->parentNode) {
            $parent = $node->parentNode;
            while ($node->nextSibling) {
                $moved[] = [$parent->removeChild($node->nextSibling), $parent];
            }
        }
        $startParent = $start->parentNode;
        $moved = [[$startParent?->removeChild($start) ?? $start, $startParent], ...$moved];
        self::trimEnd($body);
        if ($fold) {
            $clones = [];
            $into = function (?\DOMNode $parent) use (&$into, &$clones, $body, $fold): \DOMNode {
                if (! $parent instanceof \DOMElement || $parent->isSameNode($body) || in_array(strtolower($parent->localName), ['body', 'html'], true)) {
                    return $fold;
                }
                $key = spl_object_id($parent);
                if (! isset($clones[$key])) {
                    $copy = $parent->cloneNode(false);
                    $copy instanceof \DOMElement && $copy->removeAttribute('id');
                    $into($parent->parentNode)->appendChild($copy);
                    $clones[$key] = $copy;
                }

                return $clones[$key];
            };
            foreach ($moved as [$node, $parent]) {
                $into($parent)->appendChild($node);
            }
        }

        return true;
    }

    /** Пустые строки и абзацы, что стояли над подписью, — с конца письма (у Outlook это `<p>&nbsp;</p>` подряд). */
    private static function trimEnd(\DOMNode $node): void
    {
        while ($last = $node->lastChild) {
            $empty = trim(str_replace("\u{00A0}", ' ', $last->textContent)) === ''
                && ! ($last instanceof \DOMElement && ($last->tagName === 'img' || $last->getElementsByTagName('img')->length > 0));
            if ($empty) {
                $node->removeChild($last);

                continue;
            }
            if ($last instanceof \DOMElement) {
                self::trimEnd($last);
            }

            return;
        }
    }

    /** $a в документе раньше $b. */
    private static function before(\DOMNode $a, \DOMNode $b): bool
    {
        $xp = new \DOMXPath($a->ownerDocument);
        $after = $xp->query('following::node()', $a);
        foreach ($after ?: [] as $n) {
            if ($n->isSameNode($b)) {
                return true;
            }
        }

        return false;
    }
}
