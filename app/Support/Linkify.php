<?php

namespace App\Support;

/**
 * Текст сообщения в HTML: экранирование, а потом ссылки — адреса сайтов, телефоны и «№ 123»
 * (предложение). Единственное место, откуда текст чата попадает в разметку без {{ }}.
 */
final class Linkify
{
    public static function html(?string $text): string
    {
        $html = e((string) $text);
        $html = preg_replace_callback('~(?<![\w/])(https?://[^\s<]+[^\s<.,;:!?)\]}»"\'])~u', fn ($m) => '<a href="'.$m[1].'" target="_blank" rel="noopener">'.$m[1].'</a>', $html);
        $html = preg_replace_callback('~(?<![\d\w])(\+7|8)[\s(-]*(\d{3})[\s)-]*(\d{3})[\s-]*(\d{2})[\s-]*(\d{2})(?!\d)~u', fn ($m) => '<a href="tel:+7'.$m[2].$m[3].$m[4].$m[5].'" class="nums">'.$m[0].'</a>', $html);
        $html = preg_replace('~(?<![\w/])№\s?(\d{1,7})(?!\d)~u', '<a href="/offers/$1" class="nums">№&nbsp;$1</a>', $html);

        return $html;
    }
}
