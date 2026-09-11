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

    public function document(Message $message, bool $remoteImages = false, bool $dark = false): string
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
        $body = $this->inlineImages($message, $body);

        $images = "'self' data:".($remoteImages ? ' https: http:' : '');
        $csp = "default-src 'none'; img-src {$images}; style-src 'unsafe-inline'; font-src data:; form-action 'none'";
        $colors = $dark ? 'background:#0d0d0d;color:#ededed' : 'background:#fff;color:#1d1d1b';

        return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'.$csp.'">'
            .'<base target="_blank"><style>html,body{margin:0;padding:0;'.$colors.';font-family:Onest,system-ui,-apple-system,sans-serif;font-size:15px;line-height:1.45;word-break:break-word}'
            .'img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}a{color:#669709}pre.plain{white-space:pre-wrap;font-family:inherit;margin:0}'
            .'blockquote{margin:0;padding-left:12px;border-left:2px solid #d0d0d0;color:#808080}</style></head><body>'.$body.'</body></html>';
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

    private function inlineImages(Message $message, string $html): string
    {
        if (! str_contains($html, 'cid:')) {
            return $html;
        }
        $map = [];
        foreach ($message->attachments->whereNotNull('content_id') as $attachment) {
            $map['cid:'.$attachment->content_id] = "/admin/pochta/vlozheniya/{$attachment->id}";
        }

        return $map ? strtr($html, $map) : $html;
    }
}
