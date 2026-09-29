<?php

namespace App\Mail\Extraction\Templates;

use App\Mail\Extraction\CarWords;

/** Совкомбанк: блок «МАРКА МОДЕЛЬ:/ГОД ВЫПУСКА:/КПП:/ПРИВОД:…», «оценены N руб», VIN в теле или без него. */
final class Sovcombank extends Template
{
    public function extract(string $subject, string $body): array
    {
        $subject = $this->subjectOf($subject, $body);
        $fields = [];
        $this->put($fields, 'code', $this->firstCode($subject), 'subject');
        [$brand, $model] = $this->splitBrandModel((string) $this->block($body, 'МАРКА МОДЕЛЬ'));
        $this->put($fields, 'brand', $brand, 'body');
        $this->put($fields, 'model', $model, 'body');
        $this->put($fields, 'year', CarWords::digits($this->block($body, 'ГОД ВЫПУСКА')), 'body');
        $this->put($fields, 'mileage', CarWords::digits($this->block($body, 'ПРОБЕГ')), 'body');
        $this->put($fields, 'fuel', CarWords::fuel($this->block($body, 'ТИП ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'engine_power', CarWords::digits($this->block($body, 'МОЩНОСТЬ ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'engine_volume', CarWords::digits($this->block($body, 'ОБЪЕМ ДВИГАТЕЛЯ')), 'body');
        $this->put($fields, 'transmission', CarWords::transmission($this->block($body, 'КПП')), 'body');
        $this->put($fields, 'drive', CarWords::drive($this->block($body, 'ПРИВОД')), 'body');
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
            $fields['mileage'] = CarWords::digits($f[1]);
        }
        if (preg_match('/(\d{3,5})\s*(?:[cс]\s*м\s*[3³]|куб\.?(?:\s*см)?)/iu', $car, $f)) {
            $fields['engine_volume'] = (int) $f[1];
        }
        if (preg_match('/(\d{2,4})\s*л\s*\.?\s*с\s*\.?/iu', $car, $f)) {
            $fields['engine_power'] = (int) $f[1];
        }
        $fields['transmission'] = CarWords::transmission($car);
        $fields['drive'] = CarWords::drive($car);

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
