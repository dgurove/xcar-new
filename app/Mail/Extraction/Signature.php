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

        return count($words) >= 2 && count($words) <= 4 && array_diff($words, $name) === [];
    }

    /** Текст без подписи. */
    public static function cut(string $text, ?string $name): string
    {
        $words = self::nameWords($name);
        $lines = preg_split('/\R/u', $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            if (self::starts($line, $words)) {
                break;
            }
            if (! self::dropped($line)) {
                $kept[] = $line;
            }
        }

        return rtrim(implode("\n", $kept));
    }

    /**
     * Подпись из HTML письма: известные обёртки подписи (Gmail, Outlook, mail.ru) и всё с первой строки-начала подписи. Строка —
     * текстовый кусок между `<br>` или элемент без блоков внутри. Меняет документ на месте; true — что-то срезано.
     */
    public static function cutDom(\DOMDocument $dom, \DOMElement $body, ?string $name): bool
    {
        $xp = new \DOMXPath($dom);
        $start = $xp->query("//body//*[contains(concat(' ', normalize-space(@class), ' '), ' gmail_signature ') or @id='Signature' or @id='signature' or @data-signature-widget or contains(concat(' ', normalize-space(@class), ' '), ' signature ')]")?->item(0);
        $words = self::nameWords($name);
        $lines = $xp->query('//body//text()[normalize-space()] | //body//*[not(self::br) and not(.//p or .//div or .//table or .//tr or .//td or .//li or .//br)][normalize-space()]');
        $drop = [];
        foreach ($lines ?: [] as $node) {
            if (self::dropped($node->textContent)) {
                $drop[] = $node;

                continue;
            }
            if (self::starts($node->textContent, $words)) {
                // Раньше обёртки подписи — она и есть начало.
                if (! $start || self::before($node, $start)) {
                    $start = $node;
                }
                break;
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
        for ($node = $start; $node && ! $node->isSameNode($body); $node = $node->parentNode) {
            while ($node->nextSibling) {
                $node->parentNode->removeChild($node->nextSibling);
            }
        }
        $start->parentNode?->removeChild($start);
        self::trimEnd($body);

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
