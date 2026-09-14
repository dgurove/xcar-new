<?php

declare(strict_types=1);

namespace App\Cars\Vin;

/**
 * Результат разбора VIN.
 *
 * Поля объявления и справочные разведены намеренно: в форму подставляются
 * только первые, остальное показываем модератору как подсказку. Каждое значение
 * помнит источник и уверенность — пустое поле честнее выдуманного.
 */
class VinResult
{
    /** @var list<array{code: string, detail: string}> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, array{value: mixed, source: string, confidence: string}> */
    public array $fields = [];

    public bool $valid = false;

    public function __construct(public readonly string $input, public readonly ?string $vin = null) {}

    private const SOURCE_RANK = ['wmi' => 1, 'iso' => 2, 'vds' => 3, 'engines' => 3,
        'scheme' => 4, 'vds_brand' => 5];

    private const CONFIDENCE_RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    /** Поля формы «Машина» в админке. Больше туда ничего не подставляем. */
    public const FORM_FIELDS = ['brand', 'model', 'year', 'transmission_type', 'drive_type'];

    public function put(string $name, mixed $value, string $source, string $confidence): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $current = $this->fields[$name] ?? null;
        if ($current !== null) {
            $newSource = self::SOURCE_RANK[$source] ?? 0;
            $oldSource = self::SOURCE_RANK[$current['source']] ?? 0;
            $better = $newSource > $oldSource
                || ($newSource === $oldSource
                    && (self::CONFIDENCE_RANK[$confidence] ?? 0) > (self::CONFIDENCE_RANK[$current['confidence']] ?? 0));
            if (! $better) {
                return;
            }
        }

        $this->fields[$name] = ['value' => $value, 'source' => $source, 'confidence' => $confidence];
    }

    public function get(string $name): mixed
    {
        return $this->fields[$name]['value'] ?? null;
    }

    public function confidence(string $name): ?string
    {
        return $this->fields[$name]['confidence'] ?? null;
    }

    public function addError(string $code, string $detail): void
    {
        $this->errors[] = ['code' => $code, 'detail' => $detail];
    }

    /** @return array<string, mixed> Справочное: показываем, но не сохраняем. */
    public function reference(): array
    {
        return array_diff_key(
            array_map(static fn (array $f) => $f['value'], $this->fields),
            array_flip(self::FORM_FIELDS),
        );
    }
}
