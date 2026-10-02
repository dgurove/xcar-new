<?php

namespace App\Cars;

use App\Mail\Extraction\CarWords;
use App\Mail\Extraction\CodeMatcher;
use App\Mail\Extraction\Patterns;
use App\Offers\Offer;
use App\Park\Vehicle;

/**
 * Текст про машину кучей — в поля: то, что модератор шлёт в WhatsApp, вставляется в «+ Новый» целиком.
 *
 *     AUT-26-685135
 *     Changan UNI-S      Серпухов      Привод Передний      Пробег 2022      Год выпуска 2024      732 000 руб.
 *
 * Строка — до перевода строки, табуляции или трёх пробелов подряд (так WhatsApp склеивает строки при копировании).
 * Строка с подписью («Привод Передний», «Год: 2019») даёт своё поле; без подписи — номер убытка, VIN, госномер, цена,
 * телефон, марка с моделью и город узнаются по виду. Кирпичи те же, что у писем и документов: `CodeMatcher`,
 * `Patterns`, `Names`, `CarWords`, `Colors`, `Settlement::named|inAddress`. Вендор — по номеру: у кого прошлые номера того же вида.
 */
final class CarText
{
    private static ?Region $region = null;

    /** Подписи полей; первая подходящая забирает строку. Порядок важен: «Тип двигателя» раньше «Двигателя». */
    private const LABELS = [
        'claim_ref' => 'номер\s+убытка|№\s*убытка|убыток|номер\s+дела|номер\s+дл',
        'vin' => 'vin(?:\s*номер)?|вин|идентификационный\s+номер(?:\s*\(vin\))?',
        'plate' => 'гос\.?\s*(?:рег\.?\s*)?(?:номер|знак)|госномер|г\s*\/\s*н|грз',
        'year' => 'год(?:\s+(?:выпуска|изготовления|выпуска\s+тс))?|г\.\s*в\.?',
        'mileage' => 'пробег',
        'drive' => 'привод',
        'engine_volume' => 'объ[её]м(?:\s+двигателя)?|рабочий\s+объ[её]м',
        'engine_power' => 'мощность(?:\s+двигателя)?',
        'fuel' => 'тип\s+двигателя|тип\s+топлива|двигатель|топливо',
        'transmission' => 'кпп|акпп|мкпп|коробка(?:\s+передач)?|трансмиссия',
        'color' => 'цвет(?:\s+кузова)?',
        'body' => 'кузов|тип\s+кузова',
        'car' => 'марка\s*(?:[,\/и]\s*)?модель|марка|автомобиль|транспортное\s+средство|тс',
        'model' => 'модель',
        'city' => 'город|населённый\s+пункт|населенный\s+пункт|регион',
        'inspection_address' => 'адрес(?:\s+осмотра)?|место\s+осмотра|место\s+нахождения(?:\s+тс)?|местонахождение(?:\s+тс)?|стоянка',
        'contact_name' => 'страхователь|собственник|владелец|клиент|фио|контактное\s+лицо|контакт',
        'contact_phone' => 'контактный\s+телефон|телефон|тел\.?|моб\.?',
        'price' => 'цена|стоимость|закупочная|сумма',
    ];

    /** @return array<string, mixed> только найденное */
    public static function parse(string $text): array
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2007}"], ' ', $text);
        // Регион, названный где угодно в тексте («Пермский край»), сужает поиск места: с ним находятся и деревни.
        self::$region = Region::inText($text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R|\t| {3,}/u', $text) ?: []), fn ($l) => $l !== ''));
        $out = [];
        $free = [];
        foreach ($lines as $line) {
            [$field, $value] = self::labelled($line);
            if ($field === null) {
                $free[] = $line;

                continue;
            }
            self::put($out, $field, $value);
        }

        // Без подписей: номер, VIN, госномер — по виду во всём тексте.
        if (! isset($out['claim_ref']) && ($code = (new CodeMatcher)->findAll(implode("\n", $free))[0] ?? null)) {
            $out['claim_ref'] = $code;
        }
        $out['vin'] ??= Patterns::vin(implode("\n", $free));
        if (! isset($out['plate']) && preg_match(Patterns::PLATE_SPACED, mb_strtoupper(implode("\n", $free)), $m)) {
            $out['plate'] = Patterns::plateKey($m[0]);
        }
        foreach ($free as $line) {
            self::freeLine($out, $line);
        }

        if (isset($out['claim_ref']) && ! isset($out['vendor_id'])) {
            $out['vendor_id'] = self::vendorOf($out['claim_ref']);
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    /** @return array{?string, ?string} поле и значение строки с подписью */
    private static function labelled(string $line): array
    {
        foreach (self::LABELS as $field => $label) {
            if (preg_match('/^(?:'.$label.')(?=[\s:\-–—=.]|$)[\s:\-–—=.]*(.*)$/iu', $line, $m)) {
                $value = trim($m[1], " \t:—–-=");
                if ($value !== '') {
                    return [$field, $value];
                }
            }
        }

        return [null, null];
    }

    private static function put(array &$out, string $field, string $value): void
    {
        match ($field) {
            'claim_ref' => $out['claim_ref'] ??= (new CodeMatcher)->findAll($value)[0] ?? mb_strtoupper($value),
            'vin' => $out['vin'] ??= Patterns::vin($value),
            'plate' => $out['plate'] ??= preg_match(Patterns::PLATE_SPACED, mb_strtoupper($value), $m) ? Patterns::plateKey($m[0]) : null,
            'year' => $out['year'] ??= preg_match('/\b(?:'.Patterns::YEAR.')\b/u', $value, $m) ? (int) $m[0] : null,
            'mileage' => $out['mileage'] ??= self::mileage($value),
            'drive' => $out['drive'] ??= CarWords::drive($value),
            'engine_volume' => $out['engine_volume'] ??= CarWords::cc($value),
            'engine_power' => $out['engine_power'] ??= self::power($value) ?? CarWords::digits($value),
            'fuel' => self::engine($out, $value),
            'transmission' => $out['transmission'] ??= CarWords::transmission($value),
            'color' => $out['color'] ??= Colors::normalize($value) ?? mb_convert_case($value, MB_CASE_TITLE),
            'body' => $out['body'] ??= self::body($value),
            // «Марка Changan Модель UNI-S» — подпись модели внутри строки марки моделью не считается.
            'car' => self::car($out, (string) preg_replace('/(?<!\pL)модель(?!\pL)[\s:]*/iu', ' ', $value)) ?? '',
            'model' => isset($out['brand_id']) && ! isset($out['model']) ? self::model($out, $value) : null,
            'city' => self::city($out, $value),
            'inspection_address' => self::address($out, $value),
            'contact_name' => $out['contact_name'] ??= mb_substr($value, 0, 80),
            'contact_phone' => $out['contact_phone'] ??= Patterns::phones($value)[0] ?? null,
            'price' => $out['price'] ??= CarWords::digits($value),
        };
    }

    /**
     * Строка без подписи. Цена и телефон вынимаются, остальное разбирается дальше («У-001-… Казань 1 250 000 ₽»,
     * «Тигуан 2018 полный привод АКПП»): адрес, марка с моделью, год, город, слова о двигателе, приводе и коробке.
     */
    private static function freeLine(array &$out, string $line): void
    {
        if (preg_match('/(\d[\d ]{2,})(?:[.,]\d{2})?\s*(?:руб\.?|р\.|₽|rub)/iu', $line, $m)) {
            $out['price'] ??= CarWords::digits($m[1]);
            $line = str_replace($m[0], ' ', $line);
        }
        if ($phone = Patterns::phones($line)[0] ?? null) {
            $out['contact_phone'] ??= $phone;
            $line = (string) preg_replace('/'.Patterns::PHONE.'/u', ' ', $line);
        }
        // Номер, VIN и госномер уже взяты — из строки они уходят, чтобы не стать моделью.
        $rest = (string) preg_replace(['/'.Patterns::VIN_CHARS.'/u', Patterns::PLATE_SPACED], ' ', $line);
        if (isset($out['claim_ref'])) {
            $rest = (string) preg_replace(Patterns::codeRegex($out['claim_ref']), ' ', $rest);
        }
        $rest = self::squeeze($rest);
        if ($rest === '') {
            return;
        }
        // Адрес без подписи: «г. Москва, ул. Ленина 5».
        if (preg_match('/^г\.\s*[А-ЯЁ]|(?:^|[\s,])(?:ул|пр-?т|пер|ш|д)\.\s/u', $rest)) {
            self::address($out, $rest);

            return;
        }
        if (! isset($out['brand_id']) && ($left = self::car($out, $rest)) !== null) {
            $rest = $left;
        }
        if (! isset($out['year']) && preg_match('/(?<![\d\-\/])\b(?:'.Patterns::YEAR.')\b(?![\d\-\/])(?:\s*г\.?\s*в?\.?)?/u', $rest, $m)) {
            $out['year'] = (int) $m[0];
            $rest = self::squeeze(str_replace($m[0], ' ', $rest));
        }
        if ($rest === '') {
            return;
        }
        $known = false;
        if (! isset($out['settlement_id'])) {
            $known = self::city($out, $rest);
            foreach ($known ? [] : (preg_split('/[\s,]+/u', $rest) ?: []) as $word) {
                if (preg_match('/^[А-ЯЁ][а-яё\-]{2,}$/u', $word) && self::city($out, $word)) {
                    $known = true;
                    break;
                }
            }
        }
        // Короткая строка — слова о машине: «АКПП», «полный привод», «бензин 1.6».
        if (count(preg_split('/\s+/u', $rest) ?: []) <= 5) {
            foreach (['transmission' => CarWords::transmission($rest), 'drive' => CarWords::drive($rest)] as $field => $value) {
                if ($value) {
                    $out[$field] ??= $value;
                    $known = true;
                }
            }
            if (CarWords::fuel($rest)) {
                self::engine($out, $rest);
                $known = true;
            }
        }
        // Одно-два слова с заглавной, ничем не ставшие, — место: справочник городов знает не все («Серпухов»).
        if (! $known && preg_match('/^[А-ЯЁ][а-яё\-]+(?:\s[А-ЯЁ][а-яё\-]+)?$/u', $rest)) {
            $out['inspection_address'] ??= $rest;
        }
    }

    private static function squeeze(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text), " \t,;");
    }

    /** Марка и модель: «Changan UNI-S», «Хендай Солярис 2019». Что в строке кроме них — назад, нет марки — null. */
    private static function car(array &$out, string $value): ?string
    {
        $found = Names::find($value);
        if (! $found) {
            return null;
        }
        $out['brand_id'] = $found['brand']->id;
        $out['brand'] = $found['brand']->name;
        if ($found['model']) {
            self::model($out, $found['model']);
        }

        return self::squeeze($found['before'].' '.$found['after']);
    }

    private static function model(array &$out, string $name): void
    {
        $brand = Brand::find($out['brand_id']);
        $name = trim($name);
        if (! $brand || $name === '' || mb_strlen($name) > 60) {
            return;
        }
        $model = CarModel::resolve($brand, $name);
        $out['model_id'] = $model->id;
        $out['model'] = $model->name;
    }

    /** Место справочника: «Серпухов», «г. Тула», «д. Ванюки»; регион из текста сужает поиск и пускает деревни. */
    private static function city(array &$out, string $value): bool
    {
        $place = Settlement::inAddress($value) ?? Settlement::named((string) preg_replace('/^(?:г\.|город)\s*/iu', '', $value), self::$region);
        if (! $place) {
            return false;
        }
        $out['settlement_id'] ??= $place['id'];
        $out['city'] ??= $place['title'];

        return true;
    }

    /** Адрес осмотра — как написан; место из него («Пермский край, д. Ванюки, ул. …») — в «Город», если его ещё нет. */
    private static function address(array &$out, string $value): void
    {
        $out['inspection_address'] ??= mb_substr($value, 0, 255);
        if (! isset($out['settlement_id']) && ($place = Settlement::inAddress($value))) {
            $out['settlement_id'] = $place['id'];
            $out['city'] = $place['title'];
        }
    }

    /** «Двигатель Бензиновый», «Тип двигателя: дизель 2.0, 150 л.с.»: топливо, а заодно объём и мощность. */
    private static function engine(array &$out, string $value): void
    {
        $out['fuel'] ??= CarWords::fuel($value);
        if (! isset($out['engine_volume']) && preg_match('/\b(\d[.,]\d)(?!\d)|\b(\d{3,4})\s*(?:см|куб)/u', $value, $m)) {
            $out['engine_volume'] = CarWords::cc($m[1] !== '' ? $m[1] : $m[2]);
        }
        $out['engine_power'] ??= self::power($value);
    }

    private static function power(string $value): ?int
    {
        return preg_match('/(\d{2,4})\s*(?:л\.?\s*с\.?|лс|hp)/iu', $value, $m) ? (int) $m[1] : null;
    }

    /** «120 000 км», «120 тыс. км», «2022». */
    private static function mileage(string $value): ?int
    {
        $n = CarWords::digits($value);
        if ($n !== null && preg_match('/\d\s*тыс/iu', $value)) {
            $n *= 1000;
        }

        return $n;
    }

    private static function body(string $value): ?string
    {
        $word = mb_strtolower($value);
        foreach (Body::cases() as $body) {
            if (str_contains($word, mb_strtolower($body->label()))) {
                return $body->value;
            }
        }

        return null;
    }

    /**
     * Вендор по номеру убытка: чей номер того же вида встречался чаще за последние две сотни записей. Названия
     * страховых в коде не нужны — новая страховая узнаётся сама после первого заведённого руками.
     */
    private static function vendorOf(string $code): ?int
    {
        $pattern = CodeMatcher::patternOf($code);
        if ($pattern === null) {
            return null;
        }
        $regex = '^(?:'.$pattern.')$';
        $ids = Offer::whereNotNull('vendor_id')->whereNotNull('claim_ref')->whereRaw('claim_ref ~ ?', [$regex])->latest('id')->limit(200)->pluck('vendor_id')
            ->merge(Vehicle::whereNotNull('vendor_id')->whereNotNull('ref')->whereRaw('ref ~ ?', [$regex])->latest('id')->limit(200)->pluck('vendor_id'));

        return $ids->countBy()->sortDesc()->keys()->first();
    }
}
