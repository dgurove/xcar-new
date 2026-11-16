<?php

namespace App\Mail;

/**
 * Тело письма для iframe: без скриптов, форм и внешних картинок до нажатия
 * кнопки (в рассылках это маячки). Внешнего санитайзера нет: песочница
 * `sandbox` без allow-scripts плюс CSP закрывают ровно то, ради чего он нужен.
 */
final class BodyRenderer
{
    private const FORBIDDEN = ['script', 'iframe', 'object', 'embed', 'applet', 'form', 'base', 'meta', 'link'];

    public function document(Message $message, bool $remoteImages = false, string $base = '/work/mail'): string
    {
        return $this->wrap($message, $this->body($message), $remoteImages, $base);
    }

    /**
     * Свои слова письма с его оформлением — для ленты писем и шторки «Показать менеджеру» (16.11.2026, владелец: «не надо обрезать
     * приветствие и полностью очищать форматирование — что красным, должно быть красным»): тело как прислали, без цитаты прежней
     * переписки ниже (`withoutQuote`); пересылка сотрудника — целиком; письмо без HTML — текстом до цитаты
     * (`QuotationStripper::ownText`). Своих слов нет (только вложения) — null.
     */
    /** flat — письмо справа во «Входящих» (17.11.2026): прямо на белом, без листа и поля — серая плашка там была бы заливкой блока. */
    public function own(Message $message, string $base = '/work/mail', bool $flat = false): ?string
    {
        $html = trim((string) $message->html_body);
        if ($html === '') {
            [$text, $sign] = Extraction\Signature::split(Extraction\QuotationStripper::ownText($message->text_body), $message->from_name);
            if ($text === '') {
                return null;
            }
            if (! $flat) {
                return $this->wrap($message, '<pre class="plain">'.e($text).'</pre>', false, $base);
            }
            // Справа во «Входящих»: без пустых строк, подпись — свёрнутой «› Подпись» (17.11.2026, владелец).
            $lines = fn (string $t) => implode("\n", array_filter(preg_split('/\R/u', $t) ?: [], fn ($l) => trim(str_replace("\u{00A0}", ' ', $l)) !== ''));
            $sign = $lines($sign);

            return $this->wrap($message, '<pre class="plain">'.e($lines($text)).'</pre>'
                .($sign !== '' ? '<details class="sig"><summary>Подпись</summary><pre class="plain sig-body">'.e($sign).'</pre></details>' : ''), false, $base, $flat);
        }
        if (! $message->isForwardedByStaff()) {
            $html = $this->withoutQuote($html) ?? $html;
        }
        $html = $flat ? ($this->flat($html, $message->from_name) ?? $html) : ($this->withoutSignature($html, $message->from_name) ?? $html);

        return self::blank($html) ? null : $this->wrap($message, $html, false, $base, $flat);
    }

    /**
     * Письмо справа во «Входящих» (17.11.2026, владелец): подпись не срезается, а сворачивается в «› Подпись» в конце письма,
     * пустых строк нет — пустые абзацы и блоки уходят, `<br>` подряд — один, в начале и в конце блока — ни одного.
     */
    private function flat(string $html, ?string $name): ?string
    {
        $dom = new \DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>'.self::ascii($html), LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $loaded || ! $body instanceof \DOMElement) {
            return null;
        }
        $sign = $dom->createElement('div');
        $sign->setAttribute('class', 'sig-body');
        Extraction\Signature::cutDom($dom, $body, $name, $sign);
        self::compact($body);
        self::compact($sign);
        if (trim(str_replace("\u{00A0}", ' ', $sign->textContent)) !== '' || $sign->getElementsByTagName('img')->length) {
            $details = $dom->createElement('details');
            $details->setAttribute('class', 'sig');
            $details->appendChild($dom->createElement('summary', 'Подпись'));
            $details->appendChild($sign);
            $body->appendChild($details);
        }

        return self::save($dom);
    }

    /** Без пустых строк: пустые блоки (без текста, картинок и таблиц) — вон, `<br>` подряд — один, у краёв блока — ни одного. */
    private static function compact(\DOMElement $root): void
    {
        $blank = fn (\DOMNode $n) => trim(str_replace("\u{00A0}", ' ', $n->textContent)) === '';
        $solid = fn (\DOMElement $e) => in_array(strtolower($e->localName), ['img', 'table', 'hr', 'iframe', 'video'], true)
            || $e->getElementsByTagName('img')->length || $e->getElementsByTagName('table')->length;
        $xp = new \DOMXPath($root->ownerDocument);
        foreach (array_reverse(iterator_to_array($xp->query('.//*', $root))) as $el) {
            $tag = strtolower($el->localName);
            // Только блоки: пустой `<span> </span>` между словами — пробел, его не трогаем.
            if (in_array($tag, ['p', 'o:p', 'div', 'section', 'center', 'blockquote', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) && $blank($el) && ! $solid($el)) {
                $el->parentNode?->removeChild($el);
            }
        }
        $isBr = fn (?\DOMNode $n) => $n instanceof \DOMElement && strtolower($n->localName) === 'br';
        $skip = function (?\DOMNode $n, string $dir) use ($blank) {
            while ($n && $n->nodeType === XML_TEXT_NODE && $blank($n)) {
                $n = $dir === 'prev' ? $n->previousSibling : $n->nextSibling;
            }

            return $n;
        };
        foreach (iterator_to_array($root->getElementsByTagName('br')) as $br) {
            $prev = $skip($br->previousSibling, 'prev');
            $next = $skip($br->nextSibling, 'next');
            $block = fn (?\DOMNode $n) => $n instanceof \DOMElement && in_array(strtolower($n->localName), ['p', 'div', 'table', 'ul', 'ol', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'details'], true);
            if ($prev === null || $next === null || $isBr($prev) || $block($prev) || $block($next)) {
                $br->parentNode?->removeChild($br);
            }
        }
    }

    /**
     * Свои слова письма текстом — то же, что `own()`, но строками (16.11.2026, «Показать менеджеру»: старый разбор текста не узнавал
     * цитату Совкомбанка, и в «Текст письма» ехала вся переписка). Абзацы и `<br>` — переводами строк.
     */
    public function ownText(Message $message): string
    {
        $html = trim((string) $message->html_body);
        if ($html === '') {
            return Extraction\Signature::cut(Extraction\QuotationStripper::ownText($message->text_body), $message->from_name);
        }
        if (! $message->isForwardedByStaff()) {
            $html = $this->withoutQuote($html) ?? $html;
        }
        $dom = new \DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.self::ascii($html), LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }
        Extraction\Signature::cutDom($dom, $body, $message->from_name);
        foreach (['style', 'script', 'head', 'title'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('br')) as $br) {
            $br->parentNode?->replaceChild($dom->createTextNode("\u{2028}"), $br);
        }
        foreach (['p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'blockquote'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $n) {
                $n->appendChild($dom->createTextNode("\u{2028}"));
            }
        }
        // Переводы строк исходника HTML — пробелы (Outlook рвёт строки посреди фразы), строки — только от `<br>` и блоков.
        $text = str_replace(["\u{00A0}", "\r\n", "\r"], [' ', ' ', ' '], $body->textContent);
        $text = str_replace("\n", ' ', $text);
        $text = str_replace("\u{2028}", "\n", $text);
        $text = (string) preg_replace(['/[ \t]+/u', '/ *\n */u', "/\n{3,}/u"], [' ', "\n", "\n\n"], $text);

        return trim($text);
    }

    /** В теле ни слова и ни картинки (стили и заголовок не в счёт) — только вложения. Разбором, не регуляркой: письма по 100 КБ. */
    private static function blank(string $html): bool
    {
        $dom = new \DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.self::ascii($html), LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return true;
        }
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            $style->parentNode?->removeChild($style);
        }

        return trim(str_replace("\u{00A0}", ' ', $body->textContent)) === '' && $body->getElementsByTagName('img')->length === 0;
    }

    /**
     * Тело без цитаты прежней переписки: всё от первого её признака и ниже снимается — `<blockquote>`, блоки цитаты почтовиков
     * (Outlook `divRplyFwdMsg` и черта `border-top: solid #E1E1E1`, Gmail, Thunderbird, Yahoo), `<hr>`, абзац «От: / From: /
     * -----Original Message-----». Пустые абзацы в начале и в конце (Outlook ставит по два) — тоже. Признака нет — null.
     */
    /** Тело без подписи (`Extraction\Signature`); подписи нет — null. */
    private function withoutSignature(string $html, ?string $name): ?string
    {
        $dom = new \DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>'.self::ascii($html), LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $loaded || ! $body instanceof \DOMElement || ! Extraction\Signature::cutDom($dom, $body, $name)) {
            return null;
        }

        return self::save($dom);
    }

    private function withoutQuote(string $html): ?string
    {
        $dom = new \DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>'.self::ascii($html), LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $loaded || ! $body) {
            return null;
        }
        $xp = new \DOMXPath($dom);
        $starts = implode(' or ', array_map(fn ($w) => "starts-with(normalize-space(.), '{$w}')", ['От:', 'От кого:', 'From:', '-----Original', '-----Исходное', '-------- Исходное', '-------- Пересылаемое']));
        $line = "translate(@style, 'ABCDEF ', 'abcdef')";
        $marks = $xp->query('//body//blockquote | //body//hr'
            ." | //body//*[@id='divRplyFwdMsg' or @id='appendonsend' or contains(@class, 'gmail_quote') or contains(@class, 'moz-cite-prefix') or contains(@class, 'yahoo_quoted')]"
            ." | //body//div[contains({$line}, 'border-top:solid#e1e1e1') or contains({$line}, 'border-top:solid#b5c4df')]"
            ." | //body//*[self::p or self::div][{$starts}]");
        $start = $marks ? $marks->item(0) : null;
        if (! $start) {
            return null;
        }
        // Строка над цитатой («суббота, 14 ноября 2026 г. в 10:14 +03:00 от Марина <m@x.ru>:» у mail.ru, «… wrote:» у других) —
        // часть цитаты.
        // Обёртка, в которой одна цитата (mail.ru: `div#composeWebView_previouse_content > blockquote`), — тоже цитата.
        $blank = fn (?\DOMNode $n) => $n && (($n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '') || ($n instanceof \DOMElement && $n->tagName === 'br'));
        $first = function (\DOMNode $parent) use ($blank) {
            $n = $parent->firstChild;
            while ($blank($n)) {
                $n = $n->nextSibling;
            }

            return $n;
        };
        while ($start->parentNode && ! $start->parentNode->isSameNode($body) && $first($start->parentNode)?->isSameNode($start)) {
            $start = $start->parentNode;
        }
        $intro = $start->previousSibling;
        while ($blank($intro)) {
            $intro = $intro->previousSibling;
        }
        if ($intro && preg_match('/(?:@|\bот\b|wrote|писал).{0,120}:\s*$/isu', trim($intro->textContent)) && mb_strlen(trim($intro->textContent)) < 300) {
            $start = $intro;
        }
        // Всё после признака — в том же блоке и во всех вмещающих — и сам признак.
        for ($node = $start; $node && ! $node->isSameNode($body); $node = $node->parentNode) {
            while ($node->nextSibling) {
                $node->parentNode->removeChild($node->nextSibling);
            }
        }
        $start->parentNode->removeChild($start);
        $paragraphs = iterator_to_array($xp->query('//body//p'));
        $empty = fn (\DOMNode $p) => trim(str_replace("\u{00A0}", ' ', $p->textContent)) === '' && $xp->query('.//img', $p)->length === 0;
        foreach ([$paragraphs, array_reverse($paragraphs)] as $side) {
            foreach ($side as $p) {
                if (! $p->parentNode || ! $empty($p)) {
                    break;
                }
                $p->parentNode->removeChild($p);
            }
        }

        return self::save($dom);
    }

    /**
     * Документ обратно в HTML — UTF-8. Без этого libxml пишет в кодировке `<meta charset>` письма: у Совкомбанка koi8-r, и в ленте
     * вместо текста были «������» (16.11.2026, срезка подписи).
     */
    private static function save(\DOMDocument $dom): string
    {
        foreach (iterator_to_array($dom->getElementsByTagName('meta')) as $meta) {
            if ($meta->hasAttribute('charset') || strcasecmp($meta->getAttribute('http-equiv'), 'content-type') === 0) {
                $meta->parentNode?->removeChild($meta);
            }
        }

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', (string) $dom->saveHTML());
    }

    /**
     * HTML для разбора: всё не-ASCII — числовыми сущностями. Тело в базе уже UTF-8, а `<meta charset=koi8-r>` из письма libxml
     * слушает охотнее, чем `<?xml encoding>`, — текст превращался в «п░п╫п…» (16.11.2026, письма Совкомбанка в «Показать
     * менеджеру»). Сущностям кодировка документа не важна.
     */
    private static function ascii(string $html): string
    {
        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /** Документ для iframe: без скриптов и форм, CSP, лист или цвета темы. */
    private function wrap(Message $message, string $body, bool $remoteImages, string $base, bool $flat = false): string
    {
        foreach (self::FORBIDDEN as $tag) {
            $body = preg_replace('#<'.$tag.'\b[^>]*>.*?</'.$tag.'\s*>#is', '', $body) ?? $body;
            $body = preg_replace('#<'.$tag.'\b[^>]*/?>#i', '', $body) ?? $body;
        }
        $body = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $body) ?? $body;
        $body = preg_replace('/(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', '$1="#"', $body) ?? $body;
        $body = preg_replace('/\sdir\s*=\s*(["\'])\s*rtl\s*\1/i', '', $body) ?? $body;
        $body = preg_replace('/\bdirection\s*:\s*rtl\b/i', 'direction:ltr', $body) ?? $body;
        $body = $this->inlineImages($message, $body, $base);

        $images = "'self' data:".($remoteImages ? ' https: http:' : '');
        $csp = "default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; font-src data:; form-action 'none'";
        // Письмо — лист: чуть тёплый белый и поле 12px, чтобы текст не липнул к краю рамки в тёмной теме.
        // Справа во «Входящих» (`flat`) простое письмо — на фоне панели, свёрстанное со своими цветами — на белом (в тёмной теме
        // прозрачный фон под тёмными буквами письма был бы нечитаем).
        $colors = match (true) {
            $flat && $this->isPlain($message) => 'background:transparent;color:#1d1d1b',
            $flat => 'background:#fff;color:#1d1d1b',
            default => 'background:#f7f7f5;color:#1d1d1b',
        };
        // Простое письмо (текст или HTML без своих цветов) в тёмной теме — без листа, цветами темы: белый лист
        // горел посреди чёрного экрана. Тему знает cookie (класс на <html>), без неё — система; frame_controller
        // сверяет класс с приложением после загрузки и при смене темы. Свёрстанное письмо остаётся листом.
        $plain = $this->isPlain($message);
        $theme = in_array($cookie = request()->cookie('theme'), ['dark', 'light'], true) ? $cookie : null;
        $dark = fn (string $root) => "{$root}{color-scheme:dark}{$root},{$root} body{background:transparent;color:#ededed}"
            ."{$root} a{color:#a6cf3a}{$root} blockquote{border-left-color:#38383a;color:#a3a3a3}";
        $themed = $plain ? $dark('html.dark').'@media (prefers-color-scheme:dark){'.$dark('html:not(.light)').'}' : '';
        $class = $plain && $theme ? ' class="'.$theme.'"' : '';

        return '<!doctype html><html lang="ru"'.$class.'><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'.$csp.'">'
            .($plain ? '<meta name="color-scheme" content="'.($theme ?? 'light dark').'">' : '')
            .'<base target="_blank"><style>html{margin:0;padding:0;'.$colors.'}body{margin:0;padding:'.($flat ? '0' : '12px').';'.$colors.';font-family:Onest,system-ui,-apple-system,sans-serif;font-size:15px;line-height:1.45;word-break:break-word}'
            .'img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}a{color:#669709}pre.plain{white-space:pre-wrap;font-family:inherit;margin:0}'
            .'blockquote{margin:0;padding-left:12px;border-left:2px solid #d0d0d0;color:#808080}'
            .($flat ? 'p{margin:0}.sig{margin-top:10px}.sig>summary{cursor:pointer;list-style:none;font-size:13px;color:#8a8a8a}.sig>summary::-webkit-details-marker{display:none}'
                .'.sig>summary::before{content:"›";display:inline-block;width:12px;transition:transform .15s}.sig[open]>summary::before{transform:rotate(90deg)}'
                .'.sig-body{margin-top:6px;color:#8a8a8a;font-size:13px}' : '')
            .$themed.'</style></head><body>'.$body.'</body></html>';
    }

    /** Письмо без своей вёрстки: только текст или HTML без своих фонов и цветов — его можно рисовать цветами темы. */
    public function isPlain(Message $message): bool
    {
        $html = trim((string) $message->html_body);

        return $html === '' || ! preg_match('/bgcolor|background|color\s*[:=]/i', $html);
    }

    public function hasRemoteImages(Message $message): bool
    {
        return (bool) preg_match('/<img[^>]+src\s*=\s*["\']?https?:/i', (string) $message->html_body);
    }

    private function body(Message $message): string
    {
        if ($html = trim((string) $message->html_body)) {
            return $html;
        }
        $text = trim((string) $message->text_body);

        return $text === '' ? '<p style="color:#808080">Письмо без текста</p>' : '<pre class="plain">'.e($text).'</pre>';
    }

    private function inlineImages(Message $message, string $html, string $base): string
    {
        if (! str_contains($html, 'cid:')) {
            return $html;
        }
        $map = [];
        foreach ($message->attachments->whereNotNull('content_id') as $attachment) {
            $map['cid:'.$attachment->content_id] = "{$base}/attachments/{$attachment->id}";
        }

        return $map ? strtr($html, $map) : $html;
    }
}
