<?php

namespace App\Offers;

use App\Mail\Message;
use Illuminate\Support\Collection;

/**
 * О чём письмо поставщика по сделке — подзаголовок вкладки «Разобрать ответ» (09.11.2026, владелец: «просто куча „Разобрать
 * ответ“ — непонятно»; начальник просит машины, по которым Альфа написала «ГОТС поступили на реализацию»). Только по своему
 * тексту письма (`Message::replyText`, без цитаты); порядок случаев — порядок важности и подзаголовков: у сделки с несколькими
 * письмами на разборе — самое важное (`forDeal`). Письма 09.11: у Альфы шаблоны (счёт, реквизиты, реализация), у Совкомбанка —
 * живая переписка, больше половины — «Понял-принял», «Уточняю».
 */
enum ReplyKind: string
{
    case Invoice = 'invoice';
    case Requisites = 'requisites';
    case Sale = 'sale';
    case Handover = 'handover';
    case Docs = 'docs';
    case Files = 'files';
    case Other = 'other';

    public function title(): string
    {
        return match ($this) {
            self::Invoice => 'Прислали счёт',
            self::Requisites => 'Просят реквизиты',
            self::Sale => 'ГОТС поступили на реализацию',
            self::Handover => 'Готовы передать',
            self::Docs => 'Просят документы',
            self::Files => 'Прислали документы',
            self::Other => 'Переписка',
        };
    }

    public static function of(Message $message): self
    {
        $text = mb_strtolower($message->replyText());

        return match (true) {
            // Альфа: «Внимание!!! Оплата принимается только от плательщика по счету!!!» со счётом во вложении.
            preg_match('/оплата\s+принимается\s+только\s+от\s+плательщика|сч[её]т\w*\s+на\s+оплату|(?:во\s+вложении|прилага\w+|направля\w+)\s+сч[её]т|сч[её]т\s+во\s+вложении/u', $text) === 1 => self::Invoice,
            InsurerReplies::asksRequisites($message) => self::Requisites,
            preg_match('/поступил\w*\s+на\s+реализаци|до\s+переигровки/u', $text) === 1 => self::Sale,
            preg_match('/готов\w*\s+(?:к\s+)?переда|для\s+(?:оплаты\s+и\s+)?вывоза\s+\S+\s*,?\s*прошу\s+связаться|контактное\s+лицо|кто\s+будет\s+передавать/u', $text) === 1
                || OwnerPhones::for($message) !== [] => self::Handover,
            preg_match('/(?:пришлите|прислать|направьте|направить|предоставьте|предоставить|просьба\s+дать|жд(?:у|[её]м))[^.!?\n]{0,80}(?:\bдкп?\b|\bапп\b|\bакт|документ|скан|подписанн|\bос\b)|подтверждающее\s+письмо|вывезли\s+тс\s+или\s+нет|где\s+находятся\s+[^.!?\n]{0,20}оригинал/u', $text) === 1 => self::Docs,
            InsurerReplies::hasFiles($message) => self::Files,
            default => self::Other,
        };
    }

    /** Самое важное из писем сделки на разборе (порядок случаев). @param Collection<int, Message> $letters */
    public static function forDeal(Collection $letters): self
    {
        $kinds = $letters->map(fn (Message $m) => self::of($m));

        return collect(self::cases())->first(fn (self $kind) => $kinds->contains($kind)) ?? self::Other;
    }
}
