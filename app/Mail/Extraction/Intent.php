<?php

namespace App\Mail\Extraction;

use App\Mail\Direction;
use App\Mail\Message;

/**
 * Смысл письма страховой о ТС на парковке: заявка на приём, «реализовано, покупатель заберёт», покупатель хочет
 * осмотреть, просят фото или акт, сообщают, что принято, выдана («подписанный АПП», «вывез»), бухгалтерия
 * (отчёт-акт, счета, сверка), «не вывезено», вопрос, прочее. По нему цепочка писем
 * кандидата получает этап (`Chains\ChainBuilder::fold`), а у ТС в деле появляется шаг «Нужно ответить».
 */
enum Intent: string
{
    case Intake = 'intake';
    case Sold = 'sold';
    case Inspect = 'inspect';
    case Docs = 'docs';
    case Accepted = 'accepted';
    case Released = 'released';
    case Billing = 'billing';
    case CancelRelease = 'cancel_release';
    case Question = 'question';
    case Hold = 'hold';
    case Auto = 'auto';
    case Other = 'other';

    public static function of(?string $subject, ?string $body): self
    {
        // Только свои слова письма: цитаты и подпись внизу про другое («ТС продано» в истории переписки).
        $text = self::body($body);
        $all = trim((string) $subject)."\n".$text;
        // Служебное: автоответ, недоставка, отзыв письма, рассылка — не письмо о ТС.
        if (preg_match('/^\s*(?:automatic reply|autoreply|undeliverable|отзыв|delivery status|mail delivery|out of office|уведомление о регистрации|ваше сообщение не доставлено|вход с нового устройства|в аккаунт пытаются войти|добавлен номер телефона)\b/iu', (string) $subject)
            || preg_match('/^\s*(?:в данный момент|с \d{2}\.\d{2}\.\d{2,4}[^\n]{0,40}(?:отпуск|отсутству))|нахожусь в отпуске|отсутствую (?:на рабочем месте|в офисе)|доступ к почте ограничен|delivery has failed|хотел бы отозвать сообщение|отписаться от рассылки|unsubscribe|дайджест|зарегистрировано в автоматическом режиме|письмо создано автоматически/iu', $text)) {
            return self::Auto;
        }
        if (preg_match('/\bне\s+вывезен/iu', $text)) {
            return self::CancelRelease;
        }
        if (preg_match('/не\s+выдавать|выдачу\s+(?:приостановить|отложить)|не\s+передавать\s+(?:ТС|ГОТС|машину)/iu', $text)) {
            return self::Hold;
        }
        if (self::billing($subject, $text)) {
            return self::Billing;
        }
        // «Покупатель вчера вывез ГОТС, акт необходимо закрыть» — уже выдана, не «заберёт».
        if (preg_match('/\bвывез(?:ла|ло)?\b/iu', $text) && ! preg_match('/планиру|ближайш|сегодня|завтра|забер[ёе]т|вывезет|вывезут/iu', $text)) {
            return self::Released;
        }
        if ((preg_match('/осмотр/iu', $text) && preg_match('/покупател|допустить/iu', $text)) || preg_match('/(?:назначить|организовать|провести|прошу|просьба)\s+(?:\w+\s+){0,2}осмотр/iu', $text)) {
            return self::Inspect;
        }
        // «Страхователь планирует передать ТС 06.08» — это приём, хоть и «передать ТС»; продажа — про покупателя.
        if (preg_match('/(?:страховател|клиент|собственник)\w*[^\n]{0,40}(?:планирует|хочет|готов|сможет|привез|передаст|сдаст)/iu', $text) && ! preg_match('/покупател|реализован|продан/iu', $text)) {
            return self::Intake;
        }
        if (ParkExtractor::soldNotice($subject, $text)) {
            return self::Sold;
        }
        // Заявка на приём — по устойчивым оборотам страховых; «просьба прислать скан акта» внутри такой заявки — не просьба о бумагах.
        if (preg_match('/связаться\s+с\s+клиентом|прошу\s+принять|согласован\s+при[её]м|организовать(?:\s+[\d.]+)?\s+(?:при[её]м|перемещение|эвакуацию|выездной\s+при[её]м)|сам\w*(?:\s+с\s+вами)?\s+свяжется|подъедет\s+в\s+офис|готов\w*\s+к\s+передаче|документы\s+для\s+при[её]ма|\bна\s+вывоз|вывоз\s+(?:ГОТС|ТС)|просьба\s+принять|направление\s+на\s+(?:хранение|стоянку)/iu', $all)) {
            return self::Intake;
        }
        if (preg_match('/ожида\w*\s+фото|направ\w+[^\n]{0,60}(?:акт|скан|фото|ЭПТС|ПТС)|присла\w+[^\n]{0,40}(?:фото|скан|ДКП)|жд[её]м\s+от\s+вас\s+фото|фото\s+принят|нет\s+подписи|нет\s+2-й\s+стороны|подтверди\w+[^\n]{0,60}направ|исправленн\w+\s+акт|скан\w*\s+[^\n]{0,30}(?:ЭПТС|ПТС|акт)|заполнить[^\n]{0,30}акт|(?:отсутствует|нет)\s+(?:печат|подпис)|результат\w*\s+при[её]ма/iu', $text)) {
            return self::Docs;
        }
        if (preg_match('/ГОТС\s+принят|ТС\s+(?:передано|принято|принята)|по\s+принят\w+\s+(?:ТС|ГОТС)/iu', $all)) {
            return self::Accepted;
        }
        if (preg_match('/\b(?:прием|приём|приемка|приёмка)\s+(?:ГОТС|ТС)\b|вывезти|забрать\s+(?:ТС|ГОТС)|передач[аеи]\s+(?:ТС|ГОТС)/iu', $all)) {
            return self::Intake;
        }
        if (mb_strlen($text) <= 300 && str_contains($text, '?')) {
            return self::Question;
        }

        return self::Other;
    }

    /** Бухгалтерия: отчёт-акт, счета, сверка, акты хранения за период — не про одну ТС, ключей и кандидатов не даёт. */
    /** Тема бухгалтерской переписки: месячные отчёты и сверки с вендором — вся ветка про десятки машин, не про одну. */
    public static function billingSubject(?string $subject): bool
    {
        $s = (string) preg_replace('/^\s*(?:(?:re|fwd?|fw|ответ|пересл\w*)\s*(?:\[\d+\])?\s*:\s*)+/iu', '', (string) $subject);
        $period = 'за\s+(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)\w*(?:\s*[-–]\s*\w+)?\s*20\d\d';
        $months = '(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)\w*';

        return (bool) preg_match('/отч[её]т-?акт|акт\w*\s+(?:об\s+оказан|выполненн)|акты\s+хранения|\bсч[её]т(?:а|ов)?\b|сверк|согласовани[ея]\s+акт|'.$period
            .'|^стоянка\s+(?:ип|ооо|«)|^(?:стоянка|акт|отч[её]т)\b[^\n]{0,40}'.$months.'|^'.$months.'\s*(?:[-–]\s*'.$months.'\s*)?20\d\d|пролонгаци|договор\w*\s+хранения|реквизиты/iu', $s);
    }

    public static function billing(?string $subject, string $text): bool
    {
        if (self::billingSubject($subject)) {
            return true;
        }
        $period = 'за\s+(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)\w*(?:\s*[-–]\s*\w+)?\s*20\d\d';

        // В теме — и счета; в тексте только отчёты и сверки: «покупатель оплатит счёт за хранение» — ещё выдача, не бухгалтерия.
        return (bool) preg_match('/отч[её]т-?акт|акт\w*\s+(?:об\s+оказан|выполненн)|акты\s+хранения|сверк|период\s+нахождения|'.$period.'/iu', mb_substr($text, 0, 400));
    }

    /** Наше письмо: «подписанный АПП», «по выданному ТС», файл «акт выдачи» — ТС выдана; «по принятому ТС», файл с актом — принята. */
    public static function ofOutgoing(?string $subject, ?string $body, array $filenames = []): self
    {
        $text = self::body($body);
        // Отчёт-акт за месяц и сверки — не акт приёма, хоть в имени и «акт».
        $files = collect($filenames)->reject(fn ($f) => preg_match('/отч[её]т|сверк|\.xlsx?$|\.docx?$|за\s+(?:январ|феврал|март|апрел|ма[йя]|июн|июл|август|сентябр|октябр|ноябр|декабр)/iu', (string) $f));
        // Выдача — раньше бухгалтерии: СОГАЗу закрывающие документы по выданной ТС шлют в ветке «Стоянка ИП Кузнецов».
        if (preg_match('/подписанн\w+\s+АПП|по\s+выданн\w+\s+(?:ТС|ГОТС)|закрывающие\s+документы/iu', $text)
            || $files->contains(fn ($f) => preg_match('/акт\w*\s+выдач|\bапп\b/iu', (string) $f))) {
            return self::Released;
        }
        if (preg_match('/(?:ТС|ГОТС|машин[ау]|автомобиль)\s+(?!не\s)(?:выдали|выдан[аоы]?|забрали|вывезли)\b|покупатель\s+(?!не\s)(?:забрал|вывез)\b/iu', $text)) {
            return self::Released;
        }
        if (self::billingSubject($subject)) {
            return self::Billing;
        }
        // Ответ Альфе СПб на «заявку на приём» — без слов, одни фото: это фотоотчёт о принятой ТС. «Да, ТС привезли 09.04» — тоже принята.
        $photos = $files->filter(fn ($f) => preg_match('/\.(?:jpe?g|png|heic)$/iu', (string) $f))->count();
        if (preg_match('/по\s+принят\w+\s+(?:ТС|ГОТС)|по\s+при[её]му\s+(?:ТС|ГОТС)|принят[аоы]?\s+на\s+(?:хранение|парковку|стоянку)|фотоотч[её]т|^(?:ТС|ГОТС|машина|автомобиль)\s+принят[аоы]?\b|(?:ТС|ГОТС|машин[ау])\s+(?!не\s)(?:привезли|доставили|сдали|приняли)\b/iu', $text)
            || $files->contains(fn ($f) => preg_match('/\bакт/iu', (string) $f))
            || ($photos >= 4 && mb_strlen($text) < 60)) {
            return self::Accepted;
        }
        // Наш ответ с датой приёма — «Согласовали прием ТС 14.04.2026 на 13:00», «перенесен на 12.03.2026»: цепочке нужна эта дата.
        if (preg_match('/(?:при[её]м|передач|забор|привоз)[^\n]{0,40}(?:назначен|согласован|перенес)|(?:согласовали|назначили|перенесли)[^\n]{0,40}(?:при[её]м|передач)/iu', $text) && preg_match('/\d{1,2}\.\d{2}\.\d{2,4}/', $text)) {
            return self::Intake;
        }

        return self::Other;
    }

    public static function ofMessage(Message $message): self
    {
        if (preg_match('/^(?:security|mailer-daemon|no-?reply|postmaster|notification|noreply\w*)@/iu', (string) $message->from_email)) {
            return self::Auto;
        }
        $body = $message->text_body ?: strip_tags((string) $message->html_body);

        // Свой человек переслал письмо вендора без своих слов — смысл пересланного, а не «наше прочее».
        $forwarded = $message->direction !== Direction::Out && preg_match('/^\s*(?:fwd?|пересл\w*)\s*:/iu', (string) $message->subject);

        return $message->isOurs() && ! $forwarded
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
            self::Hold => 'Вендор просит не выдавать ТС',
            self::Auto => 'Автоответ',
            self::Question => 'Вендор спрашивает',
            self::Sold => 'Продано, покупатель заберёт',
            self::Accepted => 'Вендор пишет, что ТС принята',
            self::Released => 'ТС выдана',
            self::Billing => 'Бухгалтерия',
            self::Intake => 'Заявка на приём',
            self::Other => 'Письмо',
        };
    }

    /** Слово для тега в строке почты и в блоке писем дела; «прочее» тега не даёт. */
    public function short(): ?string
    {
        return match ($this) {
            self::Intake => 'Заявка',
            self::Sold => 'Продано',
            self::Inspect => 'Осмотр',
            self::Docs => 'Документы',
            self::Accepted => 'Принята',
            self::Released => 'Выдана',
            self::Billing => 'Бухгалтерия',
            self::CancelRelease => 'Не вывезено',
            self::Hold => 'Не выдавать',
            self::Question => 'Вопрос',
            self::Auto => 'Автоответ',
            self::Other => null,
        };
    }

    /** Тон тега: заявка лаймовая, ждущее ответа и продажа оранжевые, служебное приглушённое. */
    public function tone(): string
    {
        return match (true) {
            $this === self::Intake => 'tag-accent',
            // Оранжевое — только то, что ждёт нашего ответа; «Продано» ответа не ждёт, это факт.
            $this->needsReply() => 'tag-urgent',
            in_array($this, [self::Billing, self::Auto], true) => 'tag-dim',
            default => '',
        };
    }

    /** Ждёт нашего ответа в деле ТС. */
    public function needsReply(): bool
    {
        return in_array($this, [self::Inspect, self::Docs, self::CancelRelease, self::Hold, self::Question], true);
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
        // Приписка Альфы под каждым письмом — не свои слова.
        $text = (string) preg_replace('/^\s*\*\s*прошу\s+(?:не\s+изменять|отвечать)[^\n]*$/imu', '', $text);

        return trim($text);
    }
}
