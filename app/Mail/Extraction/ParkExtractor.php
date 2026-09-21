<?php

namespace App\Mail\Extraction;

use App\Cars\Names;
use App\Mail\Extraction\Templates\Generic;
use App\Mail\Extraction\Templates\Template;
use App\Support\Phone;
use App\Vendors\Vendor;
use Carbon\Carbon;

/**
 * Письмо о хранении → поля ТС и заявки. Страховые пишут по-разному: Альфа СПб — всё в теме («заявка на прием ТС
 * 7805/046/01243/26 Медведев Белджи Р621ВЕ126»), ВСК — в теме и таблицей в теле («11 575 256 прием ГОТС Changan
 * CS35 Plus О400КА977 LS5A…», «Фамилия И.О. клиента / Контактный телефон / Дата / Время / Вид услуги»), ВСК СПб —
 * «ТС готово к передаче Renault Arkana Е789СВ198 X7L…» и дата в теме, Совкомбанк — блоком «МАРКА МОДЕЛЬ»,
 * Альфа Москва — ничего, кроме телефона: машина у неё в скане заявки (`AttachmentReader`) или в имени файла
 * нашего ответа («Альфа акт 1 чанган алсвин 8592.PDF»). Марка и модель — через справочник и словарь `Cars\Names`.
 */
final class ParkExtractor
{
    /** Формы номеров, которые встречаются только в переписке стоянки. */
    private const REFS = ['\d{4}-\d{7}-\d{2}', '\d{10}', '\d{8}'];

    private const PLATE = '/\b([АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3})\b/u';

    /** Прицеп: две буквы, четыре цифры, регион. */
    private const TRAILER = '/\b([АВЕКМНОРСТУХ]{2}\d{4}\d{2,3})\b/u';

    private const VIN = '/\b([A-HJ-NPR-Z0-9]{17})\b/';

    /** Тема: служебные слова до машины и страхователя. */
    private const SUBJECT_NOISE = '/^(?:(?:re|fw|fwd|отв|ответ)\s*:\s*)+|\b(?:заявка\s+на\s+при[её]м(?:ку)?|при[её]м(?:ка)?\s+(?:ГОТС|ТС)|передача\s+ТС|на\s+вывоз|вывоз\s+(?:ГОТС|ТС)|готов\w*\s+к\s+передаче.*$|запрос|ТС|ГОТС|фото\s*\d*|ч\.?\s*\d+|наш\s+лизинг|на\s+стоянку\s+\S+.*$)\b/iu';

    public function __construct(private ?AttachmentReader $reader = null) {}

    /**
     * @param  list<string>  $filenames  имена вложений письма (марка бывает только там)
     */
    public function extract(?string $subject, ?string $body, ?string $fromEmail = null, ?\DateTimeInterface $on = null, array $filenames = [], iterable $attachments = []): array
    {
        $text = QuotationStripper::strip($body);
        $sender = QuotationStripper::forwardedSender($body) ?? $fromEmail;
        $vendor = Vendor::forSender($sender);
        $class = $vendor?->parser?->templateClass() ?? Generic::class;
        $subject = trim((string) (QuotationStripper::forwardedSubject($body) ?? $subject));
        $fields = (new $class)->extract($subject, $text);
        if (isset($fields['floor_price'])) {
            $fields['value'] = $fields['floor_price'];   // «оценены N» — стоимость в акт, не цена
        }
        unset($fields['year'], $fields['floor_price']);   // год в теле — всегда год письма
        // Марку шаблона сверяем со справочником: «Медведев» из темы маркой не станет.
        if (isset($fields['brand'])) {
            $found = Names::find(trim($fields['brand']['value'].' '.($fields['model']['value'] ?? '')));
            unset($fields['brand'], $fields['model']);
            if ($found) {
                $this->putCar($fields, $found, 'subject');
            }
        }

        // Номер заказа ВСК «11 575 256» шаблон берёт с пробелами.
        if (isset($fields['code']) && preg_match('/^\d{2}[\s\x{00A0}]\d{3}[\s\x{00A0}]\d{3}$/u', $fields['code']['value'])) {
            $fields['code']['value'] = preg_replace('/[\s\x{00A0}]+/u', '', $fields['code']['value']);
        }
        $this->fromSubject($fields, $subject);
        // Сначала свои слова письма, потом цитата: в ответе «RE: …, Джили» под цитатой лежит переписка про Фотон.
        $own = QuotationStripper::ownText($body);
        if ($own !== '' && $own !== $text) {
            $this->fromBody($fields, $own);
        }
        $this->fromBody($fields, $text);
        $this->fromFilenames($fields, $filenames);
        if (! isset($fields['code'])) {
            foreach ([$subject, $text] as $haystack) {
                if ($ref = $this->ref($haystack)) {
                    $fields['code'] = ['value' => $ref, 'source' => 'text'];
                    break;
                }
            }
        }
        if ($phones = $this->phones(self::beforeSignature($text))) {
            $fields['phones'] = ['value' => $phones, 'source' => 'body'];
        }
        if (preg_match('/\bцвет(?:\s+кузова)?\s*:\s*([а-яё\- ]{3,20})/iu', $text, $m)) {
            $fields['color'] = ['value' => mb_strtolower(trim($m[1])), 'source' => 'body'];
        }
        // Общие поля — сначала из своих слов письма (в цитате «From: Storage Storage» и телефон из подписи Альфы), потом из всего.
        $fields += Template::common($subject, $own !== '' ? $own : $text, $on);
        // Ответственный — только из своих слов (в цитате «From: Storage Storage» — это мы).
        $fields += array_diff_key(Template::common($subject, $text, $on), ['contact_name' => 1]);
        if (isset($fields['contact_name']) && preg_match('/storage|xcar|прайм/iu', $fields['contact_name']['value'])) {
            unset($fields['contact_name']);
        }
        // Страхователь из темы («Медведев», «ООО "РХС"», «Газпром и Петролес») — когда тело имени не дало.
        if (! isset($fields['insured_name']) && ($who = $this->insuredFromSubject($subject))) {
            $fields['insured_name'] = ['value' => $who, 'source' => 'subject'];
        }
        if (preg_match('/\bЮр\.?\s*л(?:ицо|\.)/iu', $text) && ! isset($fields['holder'])) {
            $fields['holder_kind'] = ['value' => 'legal', 'source' => 'body'];
        }
        if (! isset($fields['year']) && isset($fields['vin']) && ($year = self::yearFromVin($fields['vin']['value']))) {
            $fields['year'] = ['value' => $year, 'source' => 'vin'];
        }
        if (preg_match('/\b(?:прием|приём|приемка|приёмка)\s+(?:ГОТС|ТС)\b|\bвывоз|\bвывезти|\bзабрать|связаться\s+с\s+клиентом|передач[аеи]\s+(?:ТС|ГОТС)|готов\w*\s+к\s+передаче/iu', $subject.' '.$text)) {
            $fields['request'] ??= ['value' => 'tow', 'source' => 'body'];
        }
        // Сканы и документы: читалки пока нет (NullAttachmentReader), место подключения OCR или AI по API — здесь.
        if ($this->reader) {
            foreach ($attachments as $attachment) {
                foreach ($this->reader->read($attachment) as $field => $value) {
                    $fields[$field] ??= $value;
                }
            }
        }
        if ($vendor) {
            $fields['vendor_id'] = ['value' => $vendor->id, 'source' => 'sender'];
            $fields['vendor'] = ['value' => $vendor->name, 'source' => 'sender'];
        }
        $fields['sender'] = ['value' => $sender, 'source' => 'sender'];

        return $fields;
    }

    /** Тема: номер заказа ВСК, госномер, VIN, дата передачи, марка с моделью. */
    private function fromSubject(array &$fields, string $subject): void
    {
        $clean = trim((string) preg_replace('/^\s*(?:(?:re|fw|fwd|отв|ответ)\s*:\s*)+/iu', '', $subject));
        if ($clean === '') {
            return;
        }
        // ВСК: «11 575 256 прием ГОТС …», «11595488 готов к передаче …» — номер заказа как код.
        if (preg_match('/^\s*(\d{2}[\s\x{00A0}]?\d{3}[\s\x{00A0}]?\d{3})\b/u', $clean, $m)) {
            $fields['code'] ??= ['value' => preg_replace('/[\s\x{00A0}]+/u', '', $m[1]), 'source' => 'subject'];
        }
        if (preg_match('/готов\w*\s+к\s+передаче\s+(\d{2})\.(\d{2})\.(\d{4})(?:\s*(?:г\.?|года)?)?\s*(?:в|к)?\s*(\d{1,2})[.:](\d{2})/iu', $clean, $m)) {
            $fields['planned_at'] ??= ['value' => sprintf('%s-%s-%s %02d:%s', $m[3], $m[2], $m[1], (int) $m[4], $m[5]), 'source' => 'subject'];
        }
        $this->putMatch($fields, 'plate', self::PLATE, $clean, 'subject');
        $this->putMatch($fields, 'plate', self::TRAILER, $clean, 'subject');
        $this->putMatch($fields, 'vin', self::VIN, mb_strtoupper($clean), 'subject');
        if (! isset($fields['brand']) && ($found = Names::find($this->carPart($clean)))) {
            $this->putCar($fields, $found, 'subject');
        }
    }

    /** Тело: «ТС готово к передаче …», таблица ключ-значение ВСК, строки с VIN или госномером и маркой. */
    private function fromBody(array &$fields, string $text): void
    {
        if ($text === '') {
            return;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));
        $kv = function (string $key) use ($lines): ?string {
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*'.$key.'\s*:?\s*(.*)$/iu', $line, $m)) {
                    $value = trim($m[1]);

                    // Значение на той же строке или на следующей.
                    return $value !== '' ? $value : ($lines[$i + 1] ?? null);
                }
            }

            return null;
        };
        if ($car = $kv('Марка,?\s*модель(?:\s+(?:автомобиля|ТС))?')) {
            if (! isset($fields['brand']) && ($found = Names::find($car))) {
                $this->putCar($fields, $found, 'body');
            }
        }
        if ($plate = $kv('Гос\.?\s*(?:регистрационный\s+)?номер(?:\s+ТС)?')) {
            $this->putMatch($fields, 'plate', self::PLATE, $plate, 'body');
        }
        if ($who = $kv('Фамилия\s+И\.?\s*О\.?\s+клиента')) {
            $fields['insured_name'] ??= ['value' => mb_convert_case(mb_strtolower($who), MB_CASE_TITLE), 'source' => 'body'];
        }
        if (($phone = $kv('Контактный\s+телефон')) && ($normalized = Phone::normalize($phone))) {
            $fields['insured_phone'] ??= ['value' => Phone::format($normalized), 'source' => 'body'];
        }
        if ($order = $kv('Индивидуальный\s+номер\s+заказа')) {
            if (preg_match('/^\d[\d\s]{6,12}$/', $order)) {
                $fields['code'] ??= ['value' => preg_replace('/\s+/', '', $order), 'source' => 'body'];
            }
        }
        if ($where = $kv('Место\s+нахождения\s+ТС')) {
            $fields['location'] ??= ['value' => rtrim($where, ' .,'), 'source' => 'body'];
        }
        $date = $kv('Дата');
        $time = $kv('Время');
        if ($date && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $date, $d) && $time && preg_match('/(\d{1,2})[.:](\d{2})/', $time, $t)) {
            $fields['planned_at'] ??= ['value' => sprintf('%s-%s-%s %02d:%s', $d[3], $d[2], $d[1], (int) $t[1], $t[2]), 'source' => 'body'];
        }
        if (preg_match('/силами\s+страхов|кран-?\s*манипулятор|манипулятор|согласована\s+эвакуация/iu', $text)) {
            $fields['delivery'] ??= ['value' => 'vendor', 'source' => 'body'];
        } elseif (preg_match('/без\s+участия/iu', $text)) {
            $fields['delivery'] ??= ['value' => 'self', 'source' => 'body'];
        }
        // Строки о машине: «ТС готово к передаче Renault Arkana Е789СВ198 X7L…», «Hyundai Santa Fe XWES…»,
        // «ТС Hyundai Solaris гос. номер …» — марка ищется только в строках с номером, VIN или словом о ТС.
        foreach ($lines as $line) {
            $about = preg_match('/готов\w*\s+к\s+передаче|\b(?:ТС|автомобил\w*|а\/м|марк[аи])\b/iu', $line);
            $hasId = preg_match(self::PLATE, $line) || preg_match(self::VIN, mb_strtoupper($line));
            if (! $about && ! $hasId) {
                continue;
            }
            $part = preg_replace('/^.*?готов\w*\s+к\s+передаче\s*/iu', '', $line) ?? $line;
            if (! isset($fields['brand']) && ($found = Names::find($part))) {
                $this->putCar($fields, $found, 'body');
            }
            if ($hasId) {
                $this->putMatch($fields, 'plate', self::PLATE, $line, 'body');
                $this->putMatch($fields, 'vin', self::VIN, mb_strtoupper($line), 'body');
            }
            if (isset($fields['brand'], $fields['plate'], $fields['vin'])) {
                break;
            }
        }
        $this->putMatch($fields, 'plate', self::TRAILER, $text, 'body');
    }

    /** Имена файлов: «Альфа акт 1 чанган алсвин 8592.PDF», «Выписка ЭПТС WMW31BS00M3M37937.pdf». */
    private function fromFilenames(array &$fields, array $filenames): void
    {
        foreach ($filenames as $name) {
            $base = (string) preg_replace('/\.[a-z0-9]{2,5}$/iu', '', (string) $name);
            if (preg_match('/^(?:img|dsc|photo|scan|image|\d+)/iu', $base)) {
                continue;
            }
            $this->putMatch($fields, 'vin', self::VIN, mb_strtoupper($base), 'file');
            $this->putMatch($fields, 'plate', self::PLATE, $base, 'file');
            if (! isset($fields['brand'])) {
                // «Альфа акт 1 чанган алсвин 8592» — служебные слова спереди, четыре цифры номера сзади.
                // «Альфа Эптс воях фри 2290», «Согаз акт выдачи kia sportage 9699» — служебных слов может быть несколько.
                $part = (string) preg_replace(['/^(?:(?:альфа|вск|согаз|ргс|акт|скан|заявка|ао|асп|эптс|птс|стс|дов|доверенность|разрешение|выдачи?|при[её]ма|апп|отчет|отчёт)\s*\d*[\s_-]*)+/iu', '/[\s_-]+\d{3,5}(?:\s*\(\d+\))?$/u'], '', $base);
                if ($found = Names::find($part)) {
                    $this->putCar($fields, $found, 'file');
                }
            }
        }
    }

    private function putCar(array &$fields, array $found, string $source): void
    {
        $fields['brand'] = ['value' => $found['brand']->name, 'source' => $source];
        if ($found['model']) {
            $fields['model'] = ['value' => $found['model'], 'source' => $source];
        }
    }

    private function putMatch(array &$fields, string $field, string $pattern, string $text, string $source): void
    {
        if (isset($fields[$field]) || ! preg_match_all($pattern, $text, $m)) {
            return;
        }
        foreach ($m[1] as $value) {
            // VIN из одного знака («11111111111111111» у Совкомбанка) — заглушка.
            if ($field === 'vin' && strlen(count_chars($value, 3)) < 4) {
                continue;
            }
            $fields[$field] = ['value' => $field === 'plate' ? mb_strtoupper($value) : $value, 'source' => $source];

            return;
        }
    }

    /** Часть темы про машину: без кода, номеров, VIN, служебных слов. */
    private function carPart(string $subject): string
    {
        $part = $subject;
        foreach ((new CodeMatcher)->findAll($part) as $code) {
            $pattern = strtr(preg_quote($code, '/'), ['Y' => '[YУ]', 'A' => '[AА]', 'C' => '[CС]', 'K' => '[KК]', 'T' => '[TТ]', 'E' => '[EЕ]', 'H' => '[HН]', 'M' => '[MМ]', 'O' => '[OО]', 'P' => '[PР]', 'B' => '[BВ]', 'X' => '[XХ]']);
            $part = (string) preg_replace('/'.$pattern.'/iu', ' ', $part, 1);
        }
        $part = (string) preg_replace(['/\b\d{2,4}[\/-]\d{3}[\/-]\d{5}[\/-]\d{2}\b/u', '/\b[A-Z0-9]{1,4}\/\d{3}\/\d{5}\/\d{2}\b/iu', '/\b\d{6}-\d{4}\b/u', '/^\s*\d{2}\s?\d{3}\s?\d{3}\b/u', self::PLATE, self::TRAILER, self::VIN, '/\d{2}\.\d{2}\.\d{2,4}/'], ' ', $part);
        $part = (string) preg_replace(self::SUBJECT_NOISE, ' ', $part);

        return Names::clean((string) preg_replace('/\s+/u', ' ', $part));
    }

    /** Слова темы до марки: фамилия или организация. Только когда в теме есть машина: марка или госномер. */
    private function insuredFromSubject(string $subject): ?string
    {
        $part = $this->carPart($subject);
        $found = Names::find($part);
        if (! $found && ! preg_match(self::PLATE, $subject)) {
            return null;
        }
        $who = Names::clean($found ? $found['before'] : $part);
        $who = trim((string) preg_replace('/\b(?:и\s+)?(?:ООО|АО|ПАО|ИП|ЗАО)\s*$/u', '', $who));
        if (preg_match('/направлени|хранени|стоянк|\bvin\b|\bвин\b|заявк|при[её]м|передач|убыт|запрос|срочно/iu', $who)) {
            return null;
        }
        $words = $who === '' ? [] : preg_split('/\s+/u', $who);
        if (! $words || count($words) > 6 || preg_match('/\d{4,}/u', $who) || ! preg_match('/^\p{Lu}/u', $who)) {
            return null;
        }

        return $who;
    }

    /** Год из 10-го знака VIN — у японцев, корейцев, китайцев и американцев; европейские (S…Z) год не кодируют. */
    public static function yearFromVin(string $vin): ?int
    {
        $vin = strtoupper($vin);
        if (strlen($vin) !== 17 || preg_match('/^[S-Z]/', $vin)) {
            return null;
        }
        $c = $vin[9];
        $letters = 'ABCDEFGHJKLMNPRSTVWXY';
        $year = ctype_digit($c) && $c !== '0' ? 2000 + (int) $c : (($pos = strpos($letters, $c)) !== false ? 2010 + $pos : null);

        return $year && $year <= (int) Carbon::now()->format('Y') + 1 ? $year : null;
    }

    /**
     * Письмо — заявка, если в нём номер убытка, VIN или госномер. Свободный номер из текста (`REFS`:
     * десять-восемь цифр) считается только от известного вендора — иначе рассылка с номером телефона
     * или счёта становилась кандидатом. VIN или госномер без слов о вывозе, передаче, хранении
     * (`request`) и не от вендора — тоже не заявка: счёт, ответ бухгалтерии, рассылка с VIN.
     */
    public static function looksLikeRequest(array $fields): bool
    {
        $fromVendor = isset($fields['vendor_id']);
        if (isset($fields['vin']) || isset($fields['plate'])) {
            return $fromVendor || isset($fields['request']) || isset($fields['code']);
        }
        if (! isset($fields['code'])) {
            return false;
        }

        return ($fields['code']['source'] ?? '') !== 'text' || $fromVendor;
    }

    /**
     * Письмо страховой «ТС продано, заберёт такой-то»: дата продажи — дата письма, из текста — кому выдать
     * (ФИО рядом с «покупател / заберёт / выдать / передать», телефон, доверенность или ДКП). Не о продаже — null.
     *
     * @return array{name: ?string, phone: ?string, note: ?string}|null
     */
    public static function soldNotice(?string $subject, ?string $body): ?array
    {
        $text = trim((string) $subject)."\n".QuotationStripper::strip($body);
        // «не продано» / «ещё не реализовано» — не продажа.
        if (! preg_match('/(?<!не |не\s)\b(продан[оаы]?|реализован[оаы]?|покупател[ья]|новы[йм] собственник)/iu', $text)
            && ! preg_match('/\b(выдать|передать|отдать|забер[ёе]т|заберут|вывезет|вывезут)\b[^\n]{0,80}\b(ТС|ГОТС|автомобил|машин)/iu', $text)) {
            return null;
        }
        $phone = '((?:\+?7|8)[\s(\-]*\d{3}[\s)\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2})(?!\d)';
        $word = '[А-ЯЁ][а-яё]{2,}';
        $name = null;
        $tel = null;
        // «покупателю Иванову Ивану Ивановичу», «заберёт Иванов И. И.», «представитель покупателя — Петров Пётр».
        if (preg_match('/(?i:покупател[ьяюе]м?|заберут|забер[ёе]т|выдать|передать|отдать|представител[ьюя]|получател[ьюя])\s*(?:[—:-]\s*)?(?:ТС\s+)?('.$word.'(?:\s+(?:'.$word.'|[А-ЯЁ]\.)){1,2})/u', $text, $m)
            && ! Names::find($m[1])) {   // «выдать ТС покупателю: Лада Веста» — это машина, не человек
            $name = $m[1];
        }
        if ($name && preg_match('/'.preg_quote($name, '/').'[^\n]{0,80}?'.$phone.'/u', $text, $pm)) {
            $tel = $pm[1];
        } elseif (preg_match('/(?:покупател|заберут|забер[ёе]т|получател|тел)[^\n]{0,120}?'.$phone.'/iu', $text, $pm)) {
            $tel = $pm[1];
        }
        $notes = [];
        if (preg_match('/по\s+доверенности[^\n.]{0,60}/iu', $text, $nm)) {
            $notes[] = trim($nm[0]);
        }
        if (preg_match('/(?:ДКП|договор[а-я]*\s+купли[- ]продажи)\s*(?:№\s*)?([A-ZА-Я0-9\/-]{2,30})/iu', $text, $nm)) {
            $notes[] = 'ДКП № '.$nm[1];
        }

        return ['name' => $name, 'phone' => $tel ? Phone::format(Phone::normalize($tel) ?? $tel) : null, 'note' => $notes ? implode(', ', $notes) : null];
    }

    private function ref(string $text): ?string
    {
        $normalized = Code::normalize($text) ?? '';
        foreach (self::REFS as $pattern) {
            if (preg_match('/(?<![A-Z0-9\/\-.])(?:'.$pattern.')(?![A-Z0-9\/\-.])/u', $normalized, $m)) {
                return $m[0];
            }
        }

        return null;
    }

    /** @return list<string> */
    /** Текст без подписей: «С уважением, … тел.: (495) 788-09-99 доб.» — телефон офиса, не клиента; цитата ниже подписи остаётся. */
    public static function beforeSignature(string $text): string
    {
        return trim((string) preg_replace('/^\s*(?:С\s+уважением|C уважением|Best regards|--\s*$).*?(?=^\s*(?:От|От кого|From|Sent|Отправлено|-{3,})\b|\z)/imsu', '', $text));
    }

    private function phones(string $text): array
    {
        preg_match_all('/(?:\+?7|8)[\s(-]*\d{3}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}(?!\d)/u', $text, $m);

        return array_values(array_unique(array_map(fn ($p) => Phone::format(Phone::normalize($p) ?? $p), $m[0])));
    }
}
