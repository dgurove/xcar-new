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
        $body = $this->body($message);
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
        $colors = 'background:#f7f7f5;color:#1d1d1b';
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
            .'<base target="_blank"><style>html{margin:0;padding:0;'.$colors.'}body{margin:0;padding:12px;'.$colors.';font-family:Onest,system-ui,-apple-system,sans-serif;font-size:15px;line-height:1.45;word-break:break-word}'
            .'img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}a{color:#669709}pre.plain{white-space:pre-wrap;font-family:inherit;margin:0}'
            .'blockquote{margin:0;padding-left:12px;border-left:2px solid #d0d0d0;color:#808080}'.$themed.'</style></head><body>'.$body.'</body></html>';
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
