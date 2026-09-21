<?php

namespace App\Mail\Extraction;

use App\Mail\Direction;
use App\Mail\Message;

/**
 * Смысл письма страховой о ТС на парковке: заявка на приём, «реализовано, покупатель заберёт», покупатель хочет
 * осмотреть, просят фото или акт, сообщают, что принято, «не вывезено», вопрос, прочее. По нему цепочка писем
 * кандидата получает этап (`CandidateStages`), а у ТС в деле появляется шаг «Нужно ответить».
 */
enum Intent: string
{
    case Intake = 'intake';
    case Sold = 'sold';
    case Inspect = 'inspect';
    case Docs = 'docs';
    case Accepted = 'accepted';
    case CancelRelease = 'cancel_release';
    case Question = 'question';
    case Other = 'other';

    public static function of(?string $subject, ?string $body): self
    {
        // Только свои слова письма: цитаты и подпись внизу про другое («ТС продано» в истории переписки).
        $text = self::body($body);
        $all = trim((string) $subject)."\n".$text;
        if (preg_match('/\bне\s+вывезен/iu', $text)) {
            return self::CancelRelease;
        }
        if (preg_match('/осмотр/iu', $text) && preg_match('/покупател|допустить/iu', $text)) {
            return self::Inspect;
        }
        if (ParkExtractor::soldNotice($subject, $text)) {
            return self::Sold;
        }
        // Заявка на приём — по устойчивым оборотам страховых; «просьба прислать скан акта» внутри такой заявки — не просьба о бумагах.
        if (preg_match('/связаться\s+с\s+клиентом|прошу\s+принять|согласован\s+при[её]м|организовать\s+(?:при[её]м|перемещение|эвакуацию)|сам\w*\s+свяжется|готов\w*\s+к\s+передаче|документы\s+для\s+при[её]ма|\bна\s+вывоз|вывоз\s+(?:ГОТС|ТС)|просьба\s+принять/iu', $all)) {
            return self::Intake;
        }
        if (preg_match('/ожида\w*\s+фото|направ\w+[^\n]{0,60}(?:акт|скан|фото|ЭПТС|ПТС)|нет\s+подписи|нет\s+2-й\s+стороны|подтверди\w+[^\n]{0,60}направ|исправленн\w+\s+акт|скан\w*\s+[^\n]{0,30}(?:ЭПТС|ПТС|акт)/iu', $text)) {
            return self::Docs;
        }
        if (preg_match('/ГОТС\s+принят|ТС\s+(?:передано|принято|принята)|по\s+принят\w+\s+(?:ТС|ГОТС)/iu', $all)) {
            return self::Accepted;
        }
        if (preg_match('/\b(?:прием|приём|приемка|приёмка)\s+(?:ГОТС|ТС)\b|вывезти|забрать\s+(?:ТС|ГОТС)|передач[аеи]\s+(?:ТС|ГОТС)/iu', $all)) {
            return self::Intake;
        }
        if (mb_strlen($text) <= 200 && str_contains($text, '?')) {
            return self::Question;
        }

        return self::Other;
    }

    /** Наше письмо: «по принятому ТС», акт во вложении — ТС принята. */
    public static function ofOutgoing(?string $subject, ?string $body, array $filenames = []): self
    {
        $text = self::body($body);
        if (preg_match('/по\s+принят\w+\s+(?:ТС|ГОТС)|принят[аоы]?\s+на\s+(?:хранение|парковку|стоянку)/iu', $text)
            || collect($filenames)->contains(fn ($f) => preg_match('/\bакт/iu', (string) $f))) {
            return self::Accepted;
        }

        return self::Other;
    }

    public static function ofMessage(Message $message): self
    {
        $body = $message->text_body ?: strip_tags((string) $message->html_body);

        return $message->direction === Direction::Out
            ? self::ofOutgoing($message->subject, $body, $message->attachments->pluck('filename')->all())
            : self::of($message->subject, $body);
    }

    /** Текст письма без цитат и подписи, первые строки — для шага в деле и подсказки «Нужно позвонить». */
    public static function excerpt(?string $body, int $limit = 300): string
    {
        $text = self::body($body);
        $text = (string) preg_replace('/^(?:добрый\s+день|здравствуйте|коллеги|партнеры|партнёры)[!,.\s]*/iu', '', $text);
        $text = trim((string) preg_replace('/\s*\n\s*/u', "\n", $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /** Заголовок шага «Нужно ответить» и уведомления. */
    public function title(): string
    {
        return match ($this) {
            self::Inspect => 'Покупатель хочет осмотреть ТС',
            self::Docs => 'Вендор ждёт документы или фото',
            self::CancelRelease => 'ТС не вывезено, будет новый водитель',
            self::Question => 'Вендор спрашивает',
            self::Sold => 'Продано, покупатель заберёт',
            self::Accepted => 'Вендор пишет, что ТС принята',
            self::Intake => 'Заявка на приём',
            self::Other => 'Письмо',
        };
    }

    /** Ждёт нашего ответа в деле ТС. */
    public function needsReply(): bool
    {
        return in_array($this, [self::Inspect, self::Docs, self::CancelRelease, self::Question], true);
    }

    /** Тело без цитат, пересланных заголовков и подписи. */
    private static function body(?string $body): string
    {
        $text = QuotationStripper::strip($body);
        // Пересылка с телефона: своих слов нет, смысл — в пересланном письме.
        $parts = preg_split('/^\s*-+\s*(?:Пересылаемое сообщение|Forwarded message)\s*-+\s*$/imu', $text, 2);
        if (count($parts) === 2 && mb_strlen(trim((string) preg_replace('/Отправлено из.*$/imu', '', $parts[0]))) < 40) {
            $text = (string) preg_replace('/^\s*(?:От|Кому|Копия|Дата|Тема|From|To|Cc|Date|Subject)\s*:.*$/imu', '', $parts[1]);
        }
        $text = (string) preg_split('/^\s*(?:С\s+уважением|C уважением|Best regards|--\s*$|From:|От кого:|От:|Отправлено из|-------- Пересылаемое)/imu', $text)[0];

        return trim($text);
    }
}
