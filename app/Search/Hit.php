<?php

namespace App\Search;

/**
 * Строка выдачи поиска в шапке: название, код (номер убытка с логотипом вендора — только CRM и парковка), фраза «где и
 * что» кусками и куда ведёт. `action` — кнопка формой вместо перехода («Завести» номера из таблицы Кати). `external` —
 * другой хост (дело ТС на парковке из CRM): ссылка без Turbo.
 */
final class Hit
{
    /**
     * @param  list<string>  $line
     * @param  array{url: string, label: string}|null  $action
     */
    public function __construct(
        public string $title,
        public ?string $href = null,
        public array $line = [],
        public ?string $ref = null,
        public ?int $vendorId = null,
        public ?array $action = null,
        public bool $external = false,
        public string $icon = 'car',
        public int $rank = 1,
    ) {}
}
