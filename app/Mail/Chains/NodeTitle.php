<?php

namespace App\Mail\Chains;

use App\Mail\Candidate;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use Illuminate\Support\Str;

/**
 * Заголовок письма в ленте: письмо, из которого свёртка взяла этап, называется этапом («Продана, заберёт Иванов»),
 * письмо со смыслом — смыслом («Вендор ждёт документы или фото»), остальные — первыми своими словами.
 * Один расчёт для ленты, карточки и строки «Из писем».
 */
final class NodeTitle
{
    public static function for(Message $m, ?Candidate $c = null): string
    {
        foreach ($c?->stages ?? [] as $s) {
            if (($s['message_id'] ?? null) === $m->id) {
                return self::stage($s);
            }
        }
        $intent = Intent::tryFrom((string) $m->intent) ?? Intent::Other;
        if ($m->isOurs()) {
            $title = match ($intent) {
                Intent::Accepted => 'Приняли, фотоотчёт',
                Intent::Released => 'Выдали',
                Intent::Intake => 'Приём назначен',
                Intent::Billing => 'Счёт за хранение',
                default => null,
            };
        } else {
            $title = in_array($intent, [Intent::Other, Intent::Question], true) ? null : $intent->title();
        }

        return $title ?? (self::words($m) ?: ($m->has_attachments ? 'Вложения' : 'Без своих слов'));
    }

    /** Заголовок настоящий (этап или смысл), а не первые слова: раскрытое письмо такие слова в строке не повторяет. */
    public static function titled(Message $m, ?Candidate $c = null): bool
    {
        return self::for($m, $c) !== (self::words($m) ?: ($m->has_attachments ? 'Вложения' : 'Без своих слов'));
    }

    /** Кто пишет: наше письмо — сотрудник или «Мы», письмо вендора — имя или адрес. */
    public static function who(Message $m): string
    {
        if ($m->isOurs()) {
            return $m->author?->name ?? ($m->direction === Direction::Out ? 'Мы' : ($m->from_name ?: $m->from_email));
        }

        return $m->from_name ?: $m->from_email;
    }

    /** Первые свои слова письма одной строкой. */
    public static function words(Message $m, int $limit = 90): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $m->ownText())), $limit, '…');
    }

    /** @param array{stage: string, title: string, name?: ?string, phone?: ?string} $s */
    private static function stage(array $s): string
    {
        $title = $s['title'];
        if ($s['stage'] === 'sold' && ($who = trim(($s['name'] ?? '').' '.($s['phone'] ?? '')))) {
            $title .= ', заберёт '.$who;
        }

        return $title;
    }
}
