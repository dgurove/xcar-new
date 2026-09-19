<?php

namespace App\Mail\Extraction\Templates;

use App\Cars\Category;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\QuotationStripper;
use App\Support\Phone;
use Carbon\Carbon;

/**
 * Шаблон страховой: как из темы и тела достать машину. Поле — значение с
 * источником (subject/body), чтобы модератор видел, откуда что взялось.
 */
abstract class Template
{
    protected const VIN = '/\b[A-HJ-NPR-Z0-9]{17}\b/u';

    protected const PLATE = '/\b[АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3}\b/u';

    protected const YEAR = '/\b(?:19[89]\d|20[0-4]\d)\b/u';

    protected const STOP_WORDS = ['ТС', 'авто', 'автомашина', 'транспортное средство'];

    public function __construct(protected CodeMatcher $matcher = new CodeMatcher) {}

    abstract public function extract(string $subject, string $body): array;

    /**
     * Что письма всех вендоров говорят одинаково: срок ответа, страхователь с
     * телефоном, признаки ТС, НДС, держатель, требования к документам,
     * «находится в …». Одно место на все шаблоны и стоянку; поля, которые
     * шаблон уже нашёл, не перекрываются.
     */
    public static function common(string $subject, string $text, ?\DateTimeInterface $on = null): array
    {
        $plain = trim((string) preg_replace('/[ \t]+/u', ' ', (string) preg_replace('/[*_]+/u', '', $text)));
        $joined = (string) preg_replace('/\s+/u', ' ', $plain);
        $fields = [];
        $put = function (string $field, mixed $value) use (&$fields) {
            if ($value !== null && $value !== '' && $value !== []) {
                $fields[$field] = ['value' => $value, 'source' => 'body'];
            }
        };

        $put('answer_by', self::answerBy($joined, $on));
        [$name, $phone] = self::insured($joined);
        $put('insured_name', $name);
        $put('insured_phone', $phone);
        $put('flags', self::flags($joined));
        if (preg_match('/НДС\s+не\s+облага/iu', $joined)) {
            $fields['vat'] = ['value' => false, 'source' => 'body'];
        } elseif (preg_match('/\bс\s+НДС\b/iu', $joined)) {
            $fields['vat'] = ['value' => true, 'source' => 'body'];
        }
        $put('holder', self::holder($joined));
        // Ответственный по убытку — кто написал у вендора; у пересланного письма он в заголовке «От:».
        if (preg_match('/^\s*(?:>\s*)*(?:От|From):\s*([^<\n]{2,80}?)\s*<[^>\n]+>/mu', (string) $text, $m)) {
            $put('contact_name', trim($m[1], " \t\"'"));
        }
        $put('docs_required', self::docsRequired($joined));
        if (preg_match('/\b(?:находится|находятся|стоит)\s+(?:в|на)?\s*([^\n;]{3,80})/iu', $plain, $m)) {
            $put('location', rtrim(trim($m[1]), ' ,.'));
        }
        if (preg_match('/\bМарка\s+([A-Za-zА-Яа-яЁё-]{2,})\s+Модель\s+([^\n]{1,30})/u', $plain, $m)) {
            $put('brand', trim($m[1]));
            $put('model', trim($m[2]));
        }
        if (preg_match('/\b(?:вывоз|вывезти|забрать|связаться\s+с\s+клиентом|передач[аеи]\s+(?:ТС|ГОТС))/iu', $subject.' '.$joined)) {
            $fields['request'] = ['value' => 'tow', 'source' => 'body'];
        }
        if ($category = Category::guess($subject.' '.$joined)) {
            $fields['category'] = ['value' => $category->value, 'source' => 'body'];
        }

        return $fields;
    }

    /** «СРОК ТОРГОВ: до 17.08.26 до 16:00», «Ответ просьба дать к 01.09 до 12:00», «торги до 04.08.2026 г., 16.00», «сообщить до 03.09.18.00». */
    private static function answerBy(string $text, ?\DateTimeInterface $on): ?string
    {
        $text = (string) preg_replace_callback('/\b(\d{1,2}\.\d{2})\.([01]\d|2[0-3])\.([0-5]\d)\b(?!\.\d)/u', fn ($m) => $m[1].' '.$m[2].':'.$m[3], $text);   // «03.09.18.00» — дата и время через точку
        $anchor = '(?:срок\s+торгов|торги|ответ|решени\w*|сообщить|предложени\w*\s+(?:принимаются|ждём|ждем))';
        $date = '(\d{1,2})\.(\d{2})(?:\.(\d{2,4}))?';
        $time = '(?:\s*(?:г\.?|года)?\s*[,;]?\s*(?:до|в|к)?\s*(\d{1,2})[:.](\d{2}))?';
        if (! preg_match('/'.$anchor.'[^\d\n]{0,40}?(?:до|к)\s*'.$date.$time.'/iu', $text, $m)) {
            return null;
        }
        $base = $on ? Carbon::instance(\DateTime::createFromInterface($on)) : now();
        $year = isset($m[3]) && $m[3] !== '' ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : (int) $base->format('Y');
        try {
            $at = Carbon::create($year, (int) $m[2], (int) $m[1], (int) ($m[4] ?? 23), (int) ($m[5] ?? 59), 0, 'Europe/Moscow');
        } catch (\Throwable) {
            return null;
        }
        if (! isset($m[3]) || $m[3] === '') {
            if ($at->lt($base->copy()->subDays(30))) {
                $at->addYear();
            }
        }

        return $at->toIso8601String();
    }

    /** Страхователь: ФИО рядом с телефоном — после него («79054060396 Кругликов Н. Ю.») или перед («Дроздов Е. Н. 79038744488», «8-963-… Светлана»). */
    private static function insured(string $text): array
    {
        // Телефон страхователя — тот, рядом с которым сказано про клиента; подпись сотрудника страховой не годится.
        $phone = '((?:\+?7|8)[\s(\-]*\d{3}[\s)\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2})(?!\d)';
        if (! preg_match('/(?:клиент|страховател|собственник|владел|представител)[^\n]{0,160}?'.$phone.'/iu', $text, $pm, PREG_OFFSET_CAPTURE)) {
            return [null, null];
        }
        $raw = $pm[1][0];
        $normalized = Phone::normalize($raw);
        $offset = $pm[1][1];
        $word = '[А-ЯЁ][а-яё]{2,}(?:-[А-ЯЁ][а-яё]{2,})?';
        $stop = ['Тел', 'Телефон', 'Клиент', 'Клиента', 'Просьба', 'Просим', 'Прошу', 'Пожалуйста', 'Документы', 'Добрый', 'День', 'Привет', 'Связаться', 'Договорится', 'Договориться', 'Вывозе', 'Спасибо', 'Уважением', 'Как', 'Когда', 'После', 'При', 'Для'];
        $clean = function (?string $s) use ($stop) {
            $words = [];
            foreach (explode(' ', trim((string) $s)) as $w) {
                if (in_array($w, $stop, true)) {
                    if ($words) {
                        break;
                    }

                    continue;
                }
                $words[] = $w;
            }

            return $words ? implode(' ', array_slice($words, 0, 3)) : null;
        };
        $after = mb_strcut($text, $offset + strlen($raw), 80);
        $before = mb_strcut($text, max(0, $offset - 80), min(80, $offset));
        $afterName = preg_match('/^\s*[,;:\-–]?\s*((?:'.$word.'\s+){0,2}'.$word.')/u', $after, $m) ? $clean($m[1]) : null;
        $beforeName = preg_match('/((?:'.$word.'\s+){0,2}'.$word.')\s*[,;:\-–]?\s*(?:тел\.?\s*)?$/iu', $before, $m) ? $clean($m[1]) : null;
        $pick = fn (?string $n) => $n && substr_count($n, ' ') >= 1 ? $n : null;
        $name = $pick($afterName) ?? $pick($beforeName) ?? $afterName ?? $beforeName;

        return [$name, $normalized ? Phone::format($normalized) : trim($raw)];
    }

    /** @return list<string> */
    private static function flags(string $text): array
    {
        $flags = [];
        $has = fn (string $re) => (bool) preg_match($re, $text);
        if ($has('/ТС\s+кредитн/iu') && ! $has('/не\s+кредитн/iu')) {
            $flags[] = 'credit';
        }
        if ($has('/лизинг/iu')) {
            $flags[] = 'leasing';
        }
        if ($has('/в\s+залоге|залог\w*\s+у\b/iu')) {
            $flags[] = 'pledged';
        }
        if ($has('/принадлежит\s+юр/iu')) {
            $flags[] = 'legal_owner';
        }
        if ($has('/ограничени/iu') && ! $has('/ограничений\s+(?:ГИБДД\s+)?нет/iu')) {
            $flags[] = 'restricted';
        }
        if ($has('/жд[уё]м?\s+[^.]{0,40}?(?:ключи|СТС)/iu')) {
            $flags[] = 'keys_pending';
        }

        return $flags;
    }

    /** «(ОТП Банк)», ««Совкомбанк Лизинг»», «лизинговая СКБЛ». */
    private static function holder(string $text): ?string
    {
        foreach (['/«(?![^»]*страхов)([^»]{3,40}?(?:лизинг|банк)[^»]{0,20})»/iu', '/\(([^()«»]{2,30}?(?:банк|лизинг)[^()«»]{0,12})\)/iu', '/лизингов\w*\s+([А-ЯЁ]{3,8})\b/u'] as $re) {
            if (preg_match($re, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function docsRequired(string $text): array
    {
        $docs = [];
        $has = fn (string $re) => (bool) preg_match($re, $text);
        if ($has('/акт\w*\s+(?:п\/п|при[её]м)/iu') || $has('/дв[ае]\s+акт/iu')) {
            $docs[] = 'handover_act';
        }
        if ($has('/акт\w*\s+хранени/iu')) {
            $docs[] = 'storage_act';
        }
        if ($has('/соглашени/iu')) {
            $docs[] = 'agreement';
        }
        if ($has('/дв[ае]\s+(?:акт|соглашени)/iu')) {
            $docs[] = 'two_copies';
        }
        if ($has('/цветн\w*\s+скан/iu')) {
            $docs[] = 'color_scan';
        }
        if ($has('/скан\w*[\s\\\\\/]*(?:фото\s+)?подписан/iu')) {
            $docs[] = 'signed_scan';
        }
        if ($has('/фото(?:графи[июя])?\s+(?:ТС|автомобил|машин)/iu')) {
            $docs[] = 'photos';
        }

        return $docs;
    }

    protected function subjectOf(?string $subject, ?string $body): string
    {
        $subject = QuotationStripper::forwardedSubject($body) ?? trim((string) $subject);

        return trim((string) preg_replace('/^\s*(?:(?:fwd|fw|re|пересылка|пересл)\s*:\s*)+/ui', '', $subject));
    }

    protected function firstCode(string $text): ?string
    {
        return $this->matcher->findAll($text)[0] ?? null;
    }

    protected function match(string $pattern, string $text): ?string
    {
        return $text !== '' && preg_match($pattern, mb_strtoupper($text), $m) ? $m[0] : null;
    }

    protected function put(array &$fields, string $field, mixed $value, string $source): void
    {
        if ($value !== null && $value !== '' && ! isset($fields[$field])) {
            $fields[$field] = ['value' => $value, 'source' => $source];
        }
    }

    protected function price(string $text): ?int
    {
        if ($text === '') {
            return null;
        }
        $n = '[\d\x{00A0}\x{2007}\x{202F}\s]';
        foreach ([
            '/Цена\s+за\s+(?:Оффер|Лот)\D{0,10}('.$n.'+)/ui',
            '/оценены\D{0,20}?('.$n.'{4,})\D{0,6}(?:руб|р\.)/ui',
            '/Максимальное\s+предложение\D{0,20}?('.$n.'{4,})\D{0,6}(?:руб|р\.)/ui',
        ] as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $digits = preg_replace('/\D/u', '', $m[1]) ?? '';
                if ($digits !== '' && (int) $digits > 0) {
                    return (int) $digits;
                }
            }
        }

        return null;
    }

    protected function location(string $text): ?string
    {
        if (! preg_match('/Местонахождение\s*:?\s*(.+)/ui', $text, $m)) {
            return null;
        }
        $value = rtrim(trim(trim((string) preg_replace('/\s+/u', ' ', $m[1])), "*: \t\n\r\0\x0B"), ' .;,');

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    protected function brandAndModel(string $text): array
    {
        if ($text === '') {
            return [null, null];
        }
        $head = (string) preg_replace('/\(.*$/us', '', $text);
        if (preg_match(self::YEAR, $head, $year, PREG_OFFSET_CAPTURE)) {
            $head = mb_substr($head, mb_strlen(mb_strcut($head, 0, $year[0][1] + strlen($year[0][0]))));
        } else {
            foreach ($this->matcher->findAll($head) as $code) {
                // Код в теме бывает кириллицей («У-001-…»), а найден он нормализованным — вырезаем по обеим азбукам.
                $pattern = strtr(preg_quote($code, '/'), ['Y' => '[YУ]', 'A' => '[AА]', 'C' => '[CС]', 'K' => '[KК]', 'T' => '[TТ]', 'E' => '[EЕ]', 'H' => '[HН]', 'M' => '[MМ]', 'O' => '[OО]', 'P' => '[PР]', 'B' => '[BВ]', 'X' => '[XХ]']);
                if (preg_match('/'.$pattern.'/iu', $head, $m, PREG_OFFSET_CAPTURE)) {
                    $head = substr($head, $m[0][1] + strlen($m[0][0]));
                }
            }
        }
        $head = trim((string) preg_replace('/\s+/u', ' ', $head), " \t-–—,:;");
        do {
            $changed = false;
            foreach (self::STOP_WORDS as $word) {
                $pattern = '/^'.preg_quote($word, '/').'\s+/ui';
                if (preg_match($pattern, $head)) {
                    $head = trim((string) preg_replace($pattern, '', $head, 1));
                    $changed = true;
                }
            }
        } while ($changed);
        $head = trim((string) preg_replace('/\b(?:ч\.?\s*\d+|часть\s*\d+)\b/ui', ' ', $head));

        return $this->splitBrandModel($head);
    }

    protected function stripMarkdown(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[*_]+/u', '', $value)));
    }

    protected function splitBrandModel(string $text): array
    {
        $head = trim($this->stripMarkdown($text), " \t*_—–-:;,.");
        if ($head === '') {
            return [null, null];
        }
        $words = explode(' ', $head);
        $brand = array_shift($words);
        $model = implode(' ', $words);

        return [$brand, $model !== '' ? $model : null];
    }
}
