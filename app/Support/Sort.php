<?php

namespace App\Support;

/**
 * Сортировка списка: поле и направление (владелец 06.10.2026: «кнопка сортировки, окошко — выбор критерия и
 * ascending/descending»). Описание полей у списка — ключ → [подпись, 'asc'|'desc'], второе — направление, с которым
 * поле включается. В адресе один параметр `sort`: `-ключ` — по убыванию, `ключ` — по возрастанию (как у каталога
 * всегда было); ListPrefs помнит его как есть. Неизвестное поле — умолчание списка. Шторка — x-ui.sort.
 */
final class Sort
{
    /** @param array<string, array{0: string, 1: 'asc'|'desc'}> $options */
    private function __construct(public readonly string $key, public readonly bool $desc, public readonly array $options, public readonly string $default) {}

    /** @param array<string, array{0: string, 1: 'asc'|'desc'}> $options */
    public static function from(mixed $value, array $options, string $default): self
    {
        $value = is_string($value) ? $value : '';
        $key = ltrim($value, '-');
        if (! isset($options[$key])) {
            $value = $default;
            $key = ltrim($default, '-');
        }

        return new self($key, str_starts_with($value, '-'), $options, $default);
    }

    public function dir(): string
    {
        return $this->desc ? 'desc' : 'asc';
    }

    /** Значение для адреса: `-ключ` или `ключ`. */
    public function value(): string
    {
        return ($this->desc ? '-' : '').$this->key;
    }

    public function label(): string
    {
        return $this->options[$this->key][0];
    }

    public function isDefault(): bool
    {
        return $this->value() === $this->default;
    }

    /** Значение поля с его направлением — строка шторки. */
    public function of(string $key): string
    {
        return ($this->options[$key][1] === 'desc' ? '-' : '').$key;
    }

    /** Текущее поле в другом направлении — сегмент шторки. */
    public function toward(bool $desc): string
    {
        return ($desc ? '-' : '').$this->key;
    }

    /**
     * Нажатие на заголовок столбца (владелец 06.10.2026): первое — по убыванию, второе — по возрастанию, третье снимает
     * сортировку столбца и возвращает умолчание списка. Умолчание — явным значением: пустое `sort=` ListPrefs не забывает.
     */
    public function next(string $key): string
    {
        return match (true) {
            $key !== $this->key => '-'.$key,
            $this->desc => $key,
            // По возрастанию — это и есть умолчание (срок, порядок файла): снимать нечего, круг начинается заново.
            $this->value() === $this->default => '-'.$key,
            default => $this->default,
        };
    }

    public function has(string $key): bool
    {
        return isset($this->options[$key]);
    }

    /** Адрес списка с этой сортировкой: тот же путь и запрос, без страницы; запятые значений чипов не кодируются. */
    public static function url(string $value, string $param = 'sort', ?string $action = null): string
    {
        $action ??= '/'.ltrim(request()->path(), '/');
        $query = array_merge(request()->query(), [$param => $value, 'page' => null]);

        return $action.'?'.str_replace('%2C', ',', http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== '')));
    }
}
