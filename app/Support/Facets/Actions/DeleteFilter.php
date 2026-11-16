<?php

namespace App\Support\Facets\Actions;

use App\Support\Facets\SavedFilter;

/** Удалить свой сохранённый фильтр (окно «Фильтр», 17.11.2026). Чей он — проверяет контроллер (чужой — 404). */
final class DeleteFilter
{
    public function __invoke(SavedFilter $filter): void
    {
        $filter->delete();
    }
}
