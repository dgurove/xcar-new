<?php

namespace App\Mail\Extraction;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Colors;
use App\Cars\Drive;
use App\Cars\Fuel;
use App\Cars\Names;
use App\Cars\Transmission;
use App\Mail\Candidate;
use App\Mail\Scan\Files;
use App\Mail\Scan\ScanFile;
use App\Offers\Offer;
use App\Park\Vehicle;

/**
 * Поля шага «что подставить» в «✨ Распознать»: что уже знает цепочка или ТС и что нашлось в каждом прочитанном
 * файле (`DocumentFields` по тексту `DocumentText`). Одинаковые значения сливаются, источники копятся. Машина — одним
 * полем: модель без своей марки не имеет смысла.
 */
final class ScanFields
{
    public const LABELS = ['car' => 'Машина', 'vin' => 'VIN', 'year' => 'Год', 'color' => 'Цвет', 'plate' => 'Госномер', 'value' => 'Оценочная стоимость'];

    /** Поля предложения CRM: госномера и страховой стоимости у него нет. */
    public const CAR = ['car', 'vin', 'year', 'color'];

    /**
     * Характеристики, которые документ знает, а карточка предложения ждёт (`OfferSubject`): только дописываются —
     * стоящее в карточке документ не правит, расхождений по ним нет. У цепочек и ТС их не бывает.
     */
    public const SPECS = ['mileage' => 'Пробег', 'transmission' => 'КПП', 'drive' => 'Привод', 'fuel' => 'Топливо', 'engine_volume' => 'Объём', 'engine_power' => 'Мощность', 'city' => 'Город'];

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
        foreach (['vin', 'year', 'color', 'mileage', 'transmission', 'drive', 'fuel', 'engine_volume', 'engine_power'] as $field) {
            $value = $offer->{$field} instanceof \BackedEnum ? $offer->{$field}->value : $offer->{$field};
            if (! blank($value)) {
                $current[$field] = ['value' => $value, 'from' => 'в карточке'];
            }
        }
        if ($offer->settlement) {
            $current['city'] = ['value' => $offer->settlement->name, 'from' => 'в карточке'];
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
     * Характеристики (`SPECS`) только дописываются: у заполненной расхождения нет, есть лишь «совпадает».
     * Нашлось в документах несколько разных значений — выбор из них (`options`), одно — включить или нет.
     *
     * @param  array<string, array{value: mixed, from: string}>  $current  что уже знает цепочка или ТС (`ofCandidate`, `ofVehicle`)
     * @param  iterable<array{0: ScanFile, 1: string, 2?: bool}>  $docs  файл, его текст и «это фото»
     * @param  list<string>|null  $fields  какие поля у предмета бывают (`Subject::fields`), null — все
     * @return array<string, array{label: string, state: string, current: ?array{value: mixed, text: string, from: list<string>}, options: list<array{value: mixed, text: string, from: list<string>}>}>
     */
    public static function of(array $current, iterable $docs, ?array $fields = null): array
    {
        $labels = $fields === null ? self::LABELS : array_intersect_key(self::LABELS + self::SPECS, array_flip($fields));
        $found = self::found($current, $docs);

        $out = [];
        foreach ($labels as $field => $label) {
            $now = isset($current[$field]) ? ['value' => $current[$field]['value'], 'text' => self::text($field, $current[$field]['value']), 'from' => [$current[$field]['from']]] : null;
            $options = $found[$field] ?? [];
            $confirmed = $now && isset($options[self::key($now['text'])]);
            if ($confirmed) {
                $now['from'] = [...$now['from'], ...$options[self::key($now['text'])]['from']];
                unset($options[self::key($now['text'])]);
                $state = $options ? 'differs' : 'same';
            } else {
                $state = $now ? ($options ? 'differs' : null) : ($options ? 'new' : null);
            }
            if ($state === 'differs' && isset(self::SPECS[$field])) {
                [$state, $options] = [$confirmed ? 'same' : null, []];
            }
            if ($state) {
                $out[$field] = ['label' => $label, 'state' => $state, 'current' => $now, 'options' => array_values($options)];
            }
        }

        return $out;
    }

    /**
     * Что нашлось в документах: поле → варианты по ключу сравнения, у каждого — откуда. Файл о другой машине
     * (`otherCar` против `$current`) не даёт ничего. Порядок вариантов — порядок файлов: первым идёт найденное в том,
     * что прочитано первым.
     *
     * @param  iterable<array{0: ScanFile, 1: string, 2?: bool}>  $docs
     * @return array<string, array<string, array{value: mixed, text: string, from: list<string>}>>
     */
    public static function found(array $current, iterable $docs): array
    {
        $found = [];
        foreach ($docs as $doc) {
            [$file, $text] = $doc;
            $from = Files::label($file, (bool) ($doc[2] ?? false));
            $values = self::values(DocumentFields::extract($text));
            $nowBrand = isset($current['car']) ? Names::brand((string) $current['car']['value']['brand'])?->id : null;
            if (self::otherCar($current['vin']['value'] ?? null, $nowBrand, $values['vin'] ?? null, $values['car']['brand'] ?? null)) {
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

        return $found;
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
    public static function otherCar(?string $vin, ?int $brandId, ?string $docVin, ?string $docBrand): bool
    {
        if ($vin && $docVin && strlen($vin) === 17 && levenshtein(strtoupper($vin), strtoupper($docVin)) > 2) {
            return true;
        }
        $doc = $docBrand ? Names::brand($docBrand)?->id : null;

        return $brandId && $doc && $brandId !== $doc;
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
        foreach (['vin', 'year', 'color', 'plate', 'value', ...array_keys(self::SPECS)] as $f) {
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
            'mileage' => number_format((int) $value, 0, ',', ' ').' км',
            'transmission' => Transmission::tryFrom((string) $value)?->label() ?? (string) $value,
            'drive' => Drive::tryFrom((string) $value)?->label() ?? (string) $value,
            'fuel' => Fuel::tryFrom((string) $value)?->label() ?? (string) $value,
            'engine_volume' => number_format((int) $value / 1000, 1, ',', '').' л',
            'engine_power' => (int) $value.' л. с.',
            'color' => Colors::normalize((string) $value) ?? mb_convert_case(mb_strtolower((string) $value), MB_CASE_TITLE),
            default => (string) $value,
        };
    }
}
