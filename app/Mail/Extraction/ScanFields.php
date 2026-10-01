<?php

namespace App\Mail\Extraction;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Names;
use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Mail\Scan\Files;
use App\Offers\Offer;
use App\Park\Vehicle;

/**
 * Поля шага «что подставить» в «✨ Распознать»: что уже знает цепочка или ТС и что нашлось в каждом прочитанном
 * файле (`DocumentFields` по тексту `DocumentText`). Одинаковые значения сливаются, источники копятся. Машина — одним
 * полем: модель без своей марки не имеет смысла.
 */
final class ScanFields
{
    public const LABELS = ['car' => 'Машина', 'vin' => 'VIN', 'year' => 'Год', 'color' => 'Цвет', 'plate' => 'Госномер', 'value' => 'Стоимость'];

    /** Поля предложения CRM: госномера и страховой стоимости у него нет. */
    public const CAR = ['car', 'vin', 'year', 'color'];

    /** Что знает цепочка «Из писем»: поле → значение и откуда («письмо», «выбрано», «по VIN»). */
    public static function ofCandidate(Candidate $candidate): array
    {
        $current = [];
        foreach (self::values($candidate->extracted ?? []) as $field => $value) {
            $source = $field === 'car' ? ($candidate->extracted['brand']['source'] ?? '') : ($candidate->extracted[$field]['source'] ?? '');
            // Из файла взятое раньше — источник файл, его покажет сам файл.
            if ($source !== 'file') {
                $current[$field] = ['value' => $value, 'from' => match ($source) {
                    'scan' => 'выбрано', 'vin' => 'по VIN', default => 'письмо'
                }];
            }
        }

        return $current;
    }

    /** Что стоит в карточке предложения CRM — «в карточке». */
    public static function ofOffer(Offer $offer): array
    {
        $current = [];
        if ($offer->brand) {
            $current['car'] = ['value' => self::car($offer->brand, $offer->model), 'from' => 'в карточке'];
        }
        foreach (['vin', 'year', 'color'] as $field) {
            if (! blank($offer->{$field})) {
                $current[$field] = ['value' => $offer->{$field}, 'from' => 'в карточке'];
            }
        }

        return $current;
    }

    /** Что стоит в карточке заведённой ТС — «в деле». */
    public static function ofVehicle(Vehicle $vehicle): array
    {
        $current = [];
        if ($vehicle->brand) {
            $current['car'] = ['value' => self::car($vehicle->brand, $vehicle->model), 'from' => 'в деле'];
        }
        foreach (['vin', 'year', 'color', 'plate', 'value'] as $field) {
            if (! blank($vehicle->{$field})) {
                $current[$field] = ['value' => $vehicle->{$field}, 'from' => 'в деле'];
            }
        }

        return $current;
    }

    /** Марка без «(ВАЗ)» — как её пишут документы, с моделью карточки. */
    private static function car(Brand $brand, ?CarModel $model): array
    {
        return ['brand' => preg_replace('/\s*\(.*\)/u', '', $brand->name) ?: $brand->name, 'model' => $model?->name];
    }

    /**
     * Поля шага «что подставить», разложенные по тому, что изменится:
     * - `new` — у цепочки или ТС поля нет, документ его нашёл: по умолчанию подставить;
     * - `differs` — уже стоит, а документ говорит иначе: по умолчанию оставить, человек берёт значение документа;
     * - `same` — документ подтверждает то, что стоит.
     * Нашлось в документах несколько разных значений — выбор из них (`options`), одно — включить или нет.
     *
     * @param  array<string, array{value: mixed, from: string}>  $current  что уже знает цепочка или ТС (`ofCandidate`, `ofVehicle`)
     * @param  iterable<array{0: Attachment, 1: string, 2?: bool}>  $docs  вложение, его текст и «это фото»
     * @param  list<string>|null  $fields  какие поля у предмета бывают (`Subject::fields`), null — все
     * @return array<string, array{label: string, state: string, current: ?array{value: mixed, text: string, from: list<string>}, options: list<array{value: mixed, text: string, from: list<string>}>}>
     */
    public static function of(array $current, iterable $docs, ?array $fields = null): array
    {
        $labels = $fields === null ? self::LABELS : array_intersect_key(self::LABELS, array_flip($fields));
        $found = [];
        foreach ($docs as $doc) {
            [$attachment, $text] = $doc;
            $from = Files::label($attachment, (bool) ($doc[2] ?? false));
            $values = self::values(DocumentFields::extract($text));
            if (self::otherCar($current, $values)) {
                continue;
            }
            foreach ($values as $field => $value) {
                $label = self::text($field, $value);
                if ($label === '') {
                    continue;
                }
                $found[$field][self::key($label)] ??= ['value' => $value, 'text' => $label, 'from' => []];
                if (! in_array($from, $found[$field][self::key($label)]['from'], true)) {
                    $found[$field][self::key($label)]['from'][] = $from;
                }
            }
        }

        $out = [];
        foreach ($labels as $field => $label) {
            $now = isset($current[$field]) ? ['value' => $current[$field]['value'], 'text' => self::text($field, $current[$field]['value']), 'from' => [$current[$field]['from']]] : null;
            $options = $found[$field] ?? [];
            if ($now && isset($options[self::key($now['text'])])) {
                $now['from'] = [...$now['from'], ...$options[self::key($now['text'])]['from']];
                unset($options[self::key($now['text'])]);
                $state = $options ? 'differs' : 'same';
            } else {
                $state = $now ? ($options ? 'differs' : null) : ($options ? 'new' : null);
            }
            if ($state) {
                $out[$field] = ['label' => $label, 'state' => $state, 'current' => $now, 'options' => array_values($options)];
            }
        }

        return $out;
    }

    /**
     * Выбранное → поля (`Candidate::chosen` или карточка ТС), источник `scan`. Выбор — текстом варианта из документа:
     * не выбрано — поле не трогается. Выбор текстом, а не номером: пока человек выбирал, могло прийти письмо.
     *
     * @param  array<string, string>  $picks
     */
    public static function chosen(array $rows, array $picks): array
    {
        $chosen = [];
        foreach ($rows as $field => $row) {
            $option = collect($row['options'])->firstWhere('text', $picks[$field] ?? null);
            if (! $option) {
                continue;
            }
            $value = $option['value'];
            if ($field === 'car') {
                $chosen['brand'] = ['value' => $value['brand'], 'source' => 'scan', 'from' => $option['from']];
                $chosen['model'] = ['value' => $value['model'], 'source' => 'scan', 'from' => $option['from']];
            } else {
                $chosen[$field] = ['value' => $value, 'source' => 'scan', 'from' => $option['from']];
            }
        }

        return $chosen;
    }

    /**
     * Файл о другой машине (в цепочке пересланное письмо о соседней): VIN дальше двух знаков от известного (ближе —
     * ошибка OCR той же машины) или другая марка. Его поля не предлагаются — иначе «Новое» включало бы чужой год.
     */
    private static function otherCar(array $current, array $fields): bool
    {
        $vin = (string) ($current['vin']['value'] ?? '');
        if ($vin !== '' && isset($fields['vin']) && strlen($vin) === 17 && levenshtein(strtoupper($vin), strtoupper((string) $fields['vin'])) > 2) {
            return true;
        }
        $brand = fn (?array $car) => $car ? Names::brand((string) $car['brand'])?->id : null;
        $now = $brand($current['car']['value'] ?? null);
        $doc = $brand($fields['car'] ?? null);

        return $now && $doc && $now !== $doc;
    }

    /** Ключ сравнения: «Lada (ВАЗ) Granta» и «Lada Granta», «А 123 ВС» и «А123ВС» — одно. */
    private static function key(string $text): string
    {
        return mb_strtolower((string) preg_replace(['/\s*\(.*?\)/u', '/[\s\-.]+/u'], ['', ''], $text));
    }

    /** Поля в виде шага: марка с моделью — `car`. @return array<string, mixed> */
    private static function values(array $fields): array
    {
        $v = fn (string $f) => $fields[$f]['value'] ?? null;
        $out = [];
        if ($v('brand')) {
            $out['car'] = ['brand' => (string) $v('brand'), 'model' => $v('model') ? (string) $v('model') : null];
        }
        foreach (['vin', 'year', 'color', 'plate', 'value'] as $f) {
            if ($v($f) !== null && $v($f) !== '') {
                $out[$f] = $v($f);
            }
        }

        return $out;
    }

    private static function text(string $field, mixed $value): string
    {
        return match ($field) {
            // Марка без «(ВАЗ)»: в карточке и в документе она должна читаться одинаково.
            'car' => trim((preg_replace('/\s*\(.*?\)/u', '', (string) $value['brand']) ?: $value['brand']).' '.($value['model'] ?? '')),
            'value' => number_format((int) $value, 0, ',', ' ').' ₽',
            'color' => Colors::normalize((string) $value) ?? mb_convert_case(mb_strtolower((string) $value), MB_CASE_TITLE),
            default => (string) $value,
        };
    }
}
