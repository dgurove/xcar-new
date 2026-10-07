<?php

namespace App\Mail\Chains;

use App\Mail\Candidate;
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

    /** Кто пишет: наше письмо — сотрудник или имя ящика («Storage XCar»), письмо вендора — имя или адрес. */
    public static function who(Message $m): string
    {
        if ($m->isForwardedByStaff()) {
            return $m->forwardedFrom() ?? ($m->from_name ?: $m->from_email);
        }
        if ($m->isOurs()) {
            return $m->author?->name ?? ($m->from_name ?: $m->from_email);
        }

        return $m->from_name ?: $m->from_email;
    }

    /**
     * Адрес того, кто написал (владелец 07.10.2026: «почту, а не имя»): у пересылки сотрудником — адрес вендора из
     * пересланного, иначе отправитель письма (у нашего — ящик, с которого ушло).
     */
    public static function email(Message $m): string
    {
        $sender = $m->isForwardedByStaff() ? trim((string) $m->field('sender')) : '';

        return mb_strtolower(filter_var($sender, FILTER_VALIDATE_EMAIL) ? $sender : trim((string) $m->from_email));
    }

    /** Кто переслал письмо со своим примечанием: имя ящика сотрудника («Андрей Кузнецов»), иначе адрес. */
    public static function forwarder(Message $m): string
    {
        return $m->from_name ?: $m->from_email;
    }

    /** Первые свои слова письма одной строкой. */
    public static function words(Message $m, int $limit = 90): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $m->ownText())), $limit, '…');
    }

    /**
     * «Ч.2» того же письма: тот же адрес и те же слова не позже суток — продолжение, текст не повторяется, только файлы.
     * Письма — по времени. @return array<int, int> id продолжения → id письма, которое оно продолжает
     */
    public static function continued(iterable $messages): array
    {
        $continued = [];
        $prev = null;
        foreach ($messages as $m) {
            if ($prev && $prev->from_email === $m->from_email && trim($m->ownText()) !== '' && trim($m->ownText()) === trim($prev->ownText()) && $m->date_at && $prev->date_at && $m->date_at->diffInHours($prev->date_at, true) <= 24) {
                $continued[$m->id] = $prev->id;
            } else {
                $prev = $m;
            }
        }

        return $continued;
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
