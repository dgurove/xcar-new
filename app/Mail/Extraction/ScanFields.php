<?php

namespace App\Mail\Extraction;

use App\Mail\Attachment;
use App\Mail\Candidate;
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

    /**
     * @param  iterable<array{0: Attachment, 1: string, 2?: bool}>  $docs  вложение, его текст и «это фото»
     * @return array<string, array{label: string, options: list<array{value: mixed, text: string, from: list<string>}>, pick: int}>
     */
    public static function of(Candidate $candidate, iterable $docs): array
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
        foreach (self::values($candidate->extracted ?? []) as $field => $value) {
            $source = $field === 'car' ? ($candidate->extracted['brand']['source'] ?? '') : ($candidate->extracted[$field]['source'] ?? '');
            // Из файла взятое раньше — источник файл, его покажет сам файл ниже.
            if ($source !== 'file') {
                $add($field, $value, $source === 'scan' ? 'выбрано' : 'письмо');
            }
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
                if (array_intersect($o['from'], ['письмо', 'выбрано'])) {
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
     * человек выбирал, могло прийти письмо и сдвинуть варианты. @param array<string, string> $picks
     */
    public static function chosen(array $rows, array $picks): array
    {
        $chosen = [];
        foreach ($rows as $field => $row) {
            $option = collect($row['options'])->firstWhere('text', $picks[$field] ?? null);
            // Выбирать было не из чего — запоминать нечего: значение и так у цепочки.
            if (count($row['options']) < 2 || ! $option) {
                continue;
            }
            $value = $option['value'];
            if ($field === 'car') {
                $chosen['brand'] = ['value' => $value['brand'], 'source' => 'scan'];
                $chosen['model'] = ['value' => $value['model'], 'source' => 'scan'];
            } else {
                $chosen[$field] = ['value' => $value, 'source' => 'scan'];
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
    private static function source(string $filename): string
    {
        $label = Docs::label($filename);

        return preg_match('/^[\d\s_\-\[\]()]+$|^\[?untitled\]?$/iu', $label) ? 'скан' : $label;
    }

    private static function text(string $field, mixed $value): string
    {
        return match ($field) {
            'car' => trim($value['brand'].' '.($value['model'] ?? '')),
            'value' => number_format((int) $value, 0, ',', ' ').' ₽',
            'color' => mb_convert_case(mb_strtolower((string) $value), MB_CASE_TITLE),
            default => (string) $value,
        };
    }
}
