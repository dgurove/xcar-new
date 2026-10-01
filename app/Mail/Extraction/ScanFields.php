<?php

namespace App\Mail\Extraction;

use App\Cars\Colors;
use App\Mail\Attachment;
use App\Mail\Candidate;
use App\Park\Vehicle;
use App\Support\Docs;

/**
 * Поля шага «что подставить» в «✨ Распознать»: по каждому полю — варианты из цепочки (`extracted`, что карточка
 * знает сейчас) и из каждого прочитанного файла (`DocumentFields` по тексту `DocumentText`). Одинаковые значения
 * сливаются, источники копятся. Выбрано по умолчанию письмо (или выбранное раньше), без него — первый документ:
 * файлы приходят документами вперёд, фото подписано просто «фото». Машина — одним полем: модель без своей марки
 * не имеет смысла.
 */
final class ScanFields
{
    public const LABELS = ['car' => 'Машина', 'vin' => 'VIN', 'year' => 'Год', 'color' => 'Цвет', 'plate' => 'Госномер', 'value' => 'Стоимость'];

    /** Источники «уже стоит»: их вариант выбран по умолчанию, а единственный такой — не запоминается. */
    private const OWN = ['письмо', 'выбрано', 'в деле'];

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

    /** Что стоит в карточке заведённой ТС — «в деле». */
    public static function ofVehicle(Vehicle $vehicle): array
    {
        $current = [];
        if ($vehicle->brand) {
            $current['car'] = ['value' => ['brand' => preg_replace('/\s*\(.*\)/u', '', $vehicle->brand->name) ?: $vehicle->brand->name, 'model' => $vehicle->model?->name], 'from' => 'в деле'];
        }
        foreach (['vin', 'year', 'color', 'plate', 'value'] as $field) {
            if (! blank($vehicle->{$field})) {
                $current[$field] = ['value' => $vehicle->{$field}, 'from' => 'в деле'];
            }
        }

        return $current;
    }

    /**
     * @param  array<string, array{value: mixed, from: string}>  $current  что уже знает цепочка или ТС (`ofCandidate`, `ofVehicle`)
     * @param  iterable<array{0: Attachment, 1: string, 2?: bool}>  $docs  вложение, его текст и «это фото»
     * @return array<string, array{label: string, options: list<array{value: mixed, text: string, from: list<string>}>, pick: int}>
     */
    public static function of(array $current, iterable $docs): array
    {
        $rows = [];
        $add = function (string $field, mixed $value, string $from) use (&$rows) {
            $text = self::text($field, $value);
            if ($text === '') {
                return;
            }
            $key = mb_strtolower((string) preg_replace('/[\s\-.]+/u', '', $text));
            $rows[$field][$key] ??= ['value' => $value, 'text' => $text, 'from' => []];
            if (! in_array($from, $rows[$field][$key]['from'], true)) {
                $rows[$field][$key]['from'][] = $from;
            }
        };
        foreach ($current as $field => $item) {
            $add($field, $item['value'], $item['from']);
        }
        foreach ($docs as $doc) {
            [$attachment, $text] = $doc;
            foreach (self::values(DocumentFields::extract($text)) as $field => $value) {
                $add($field, $value, ($doc[2] ?? false) ? 'фото' : self::source((string) $attachment->filename));
            }
        }

        $out = [];
        foreach (self::LABELS as $field => $label) {
            if (empty($rows[$field])) {
                continue;
            }
            $options = array_values($rows[$field]);
            $pick = 0;
            foreach ($options as $i => $o) {
                if (array_intersect($o['from'], self::OWN)) {
                    $pick = $i;
                    break;
                }
            }
            $out[$field] = ['label' => $label, 'options' => $options, 'pick' => $pick];
        }

        return $out;
    }

    /**
     * Выбранное → поля цепочки (`Candidate::chosen`), источник `scan`. Выбор — текстом варианта, не номером: пока
     * человек выбирал, могло прийти письмо и сдвинуть варианты; без выбора у поля — вариант по умолчанию.
     * Единственное значение из файла тоже запоминается: разбор письма берёт из файлов не всё (стоимость — нет).
     * Единственное значение из письма — нет: оно и так у цепочки, а запомненное не дало бы письму его поправить.
     *
     * @param  array<string, string>  $picks
     */
    public static function chosen(array $rows, array $picks): array
    {
        $chosen = [];
        foreach ($rows as $field => $row) {
            $option = collect($row['options'])->firstWhere('text', $picks[$field] ?? null) ?? $row['options'][$row['pick']];
            // Уже стоит в карточке ТС — менять нечего; единственное из письма — тоже.
            if (in_array('в деле', $option['from'], true) || (count($row['options']) < 2 && array_intersect($option['from'], self::OWN))) {
                continue;
            }
            $value = $option['value'];
            $from = array_values(array_diff($option['from'], self::OWN));
            if ($field === 'car') {
                $chosen['brand'] = ['value' => $value['brand'], 'source' => 'scan', 'from' => $from];
                $chosen['model'] = ['value' => $value['model'], 'source' => 'scan', 'from' => $from];
            } else {
                $chosen[$field] = ['value' => $value, 'source' => 'scan', 'from' => $from];
            }
        }

        return $chosen;
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

    /** Подпись файла: «заявка», «эптс»; имя сканера из одних цифр («20260930142927…») — просто «скан». */
    public static function source(string $filename): string
    {
        $label = Docs::label($filename);

        return preg_match('/^[\d\s_\-\[\]()]+$|^\[?untitled\]?$/iu', $label) ? 'скан' : $label;
    }

    private static function text(string $field, mixed $value): string
    {
        return match ($field) {
            'car' => trim($value['brand'].' '.($value['model'] ?? '')),
            'value' => number_format((int) $value, 0, ',', ' ').' ₽',
            'color' => Colors::normalize((string) $value) ?? mb_convert_case(mb_strtolower((string) $value), MB_CASE_TITLE),
            default => (string) $value,
        };
    }
}
