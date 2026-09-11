<?php

namespace App\Mail\Extraction\Templates;

use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Transmission;

/** Совкомбанк: блок «МАРКА МОДЕЛЬ:/ГОД ВЫПУСКА:/КПП:/ПРИВОД:…», «оценены N руб», VIN в теле или без него. */
final class Sovcombank extends Template
{
    private const FUEL_WORDS = ['бензин' => 'petrol', 'дизел' => 'diesel', 'гибрид' => 'hybrid', 'электро' => 'electric', 'газ' => 'gas'];

    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $this->put($fields, 'code', $this->firstCode($subject), 'subject');
        [$brand, $model] = $this->splitBrandModel((string) $this->block($body, 'МАРКА МОДЕЛЬ'));
        $this->put($fields, 'brand', $brand, 'body');
        $this->put($fields, 'model', $model, 'body');
        $this->put($fields, 'year', $this->digits($this->block($body, 'ГОД ВЫПУСКА')), 'body');
        $this->put($fields, 'mileage', $this->digits($this->block($body, 'ПРОБЕГ')), 'body');
        $this->put($fields, 'fuel', $this->fuel($this->block($body, 'ТИП ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'engine_power', $this->digits($this->block($body, 'МОЩНОСТЬ ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'engine_volume', $this->digits($this->block($body, 'ОБЪЕМ ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'transmission', $this->transmission($this->block($body, 'КПП')), 'body');
        $this->put($fields, 'drive', $this->drive($this->block($body, 'ПРИВОД')), 'body');
        $this->put($fields, 'location', $this->block($body, 'МЕСТОНАХОЖДЕНИЕ ТС'), 'body');
        $this->put($fields, 'floor_price', $this->price($body), 'body');
        if (! isset($fields['brand']) && ! isset($fields['year'])) {
            foreach ($this->freeForm($body) as $field => $value) {
                $this->put($fields, $field, $value, 'body');
            }
        }
        $this->put($fields, 'vin', $this->vinOf($body), 'body');
        $this->put($fields, 'plate', $this->match(self::PLATE, $body), 'body');

        return $fields;
    }

    /** Ключ в письме бывает жирным и без двоеточия: «ТИП ДВИГАТЕЛЯ *бензиновый*». */
    private function block(string $body, string $key): ?string
    {
        if (! preg_match('/^[\s*_]*'.preg_quote($key, '/').'[\s*_]*:?[\s*_]*(.+)$/umi', $body, $m)) {
            return null;
        }
        $value = $this->stripMarkdown($m[1]);

        return $value !== '' ? $value : null;
    }

    /** Первое число в строке: «1998 см3» — 1998, а не 19983. */
    private function digits(?string $value): ?int
    {
        if ($value === null || ! preg_match('/\d[\d\s\x{00A0}]*/u', $value, $m)) {
            return null;
        }
        $digits = preg_replace('/\D/u', '', $m[0]) ?? '';

        return $digits !== '' && (int) $digits > 0 ? (int) $digits : null;
    }

    private function fuel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        if (str_contains($word, 'бензин') && str_contains($word, 'газ')) {
            return 'gas';
        }
        foreach (self::FUEL_WORDS as $needle => $enum) {
            if (str_contains($word, $needle)) {
                return Fuel::tryFrom($enum)?->value;
            }
        }

        return null;
    }

    private function transmission(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        $mapped = match (true) {
            str_contains($word, 'акпп') || str_contains($word, 'автомат') => 'automatic',
            str_contains($word, 'мкпп') || str_contains($word, 'механ') => 'manual',
            str_contains($word, 'вариатор') => 'cvt',
            str_contains($word, 'робот') => 'dual_clutch',
            default => null,
        };

        return $mapped ? Transmission::tryFrom($mapped)?->value : null;
    }

    private function drive(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $word = mb_strtolower($value);
        $mapped = match (true) {
            str_contains($word, 'полн') || preg_match('/4\s*[хx]\s*4|\bawd\b|\b4\s*wd\b/u', $word) === 1 => 'awd',
            str_contains($word, 'передн') || preg_match('/\bf\s*wd\b/u', $word) === 1 => 'fwd',
            str_contains($word, 'задн') || preg_match('/4\s*[хx]\s*2|\brwd\b/u', $word) === 1 => 'rwd',
            default => null,
        };

        return $mapped ? Drive::tryFrom($mapped)?->value : null;
    }

    /** «Haval Jolion, 2023 г.в.» — год выпуска якорь: перед запятой стоят марка и модель. */
    private function freeForm(string $body): array
    {
        $clean = $this->stripMarkdown($body);
        if ($clean === '' || ! preg_match('/([^,]+),\s*(19[89]\d|20[0-4]\d)\s*г\.?\s*в\.?/u', $clean, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $words = preg_split('/\s+/u', trim($m[1][0]));
        if (count($words) < 2) {
            return [];
        }
        $model = (string) array_pop($words);
        $brand = (string) array_pop($words);
        if (in_array(mb_strtolower($brand), array_map('mb_strtolower', self::STOP_WORDS), true)) {
            $brand = $model = null;
        }
        $fields = ['brand' => $brand, 'model' => $model, 'year' => (int) $m[2][0]];
        $car = substr($clean, (int) $m[0][1]);
        if (($at = mb_stripos($car, 'вин:')) !== false) {
            $car = mb_substr($car, 0, $at);
        }
        if (preg_match('/пробег\s*:?\s*([\d\s]{4,})\s*км/iu', $car, $f)) {
            $fields['mileage'] = $this->digits($f[1]);
        }
        if (preg_match('/(\d{3,5})\s*(?:[cс]\s*м\s*[3³]|куб\.?(?:\s*см)?)/iu', $car, $f)) {
            $fields['engine_volume'] = (int) $f[1];
        }
        if (preg_match('/(\d{2,4})\s*л\s*\.?\s*с\s*\.?/iu', $car, $f)) {
            $fields['engine_power'] = (int) $f[1];
        }
        $fields['transmission'] = $this->transmission($car);
        $fields['drive'] = $this->drive($car);

        return array_filter($fields, fn ($v) => $v !== null);
    }

    private function vinOf(string $body): ?string
    {
        if ($body === '') {
            return null;
        }
        $text = mb_strtoupper($body);
        $haystacks = preg_match('/\b(?:ВИН|VIN)\s*:?\s*(.+)$/um', $text, $m) ? [$m[1], $text] : [$text];
        foreach ($haystacks as $haystack) {
            if (preg_match_all(self::VIN, $haystack, $all)) {
                foreach (array_reverse($all[0]) as $vin) {
                    if (strlen(count_chars($vin, 3)) > 1) {  // заглушка из одинаковых цифр
                        return $vin;
                    }
                }
            }
        }

        return null;
    }
}
