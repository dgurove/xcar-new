<?php

namespace App\Support\Facets\Actions;

use App\Support\Facets\SavedFilter;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Сохранить фильтр человека (17.11.2026, шторка «Фильтр»): новый — последним в списке, не больше `SavedFilter::LIMIT`; свой —
 * переименовать или обновить выбором с экрана. Адрес чистится: только строки, без страниц, поиска и открытой карточки.
 */
final class SaveFilter
{
    /** @param  array<string, mixed>|null  $query  null — оставить прежний (переименование) */
    public function __invoke(User $user, string $list, ?string $name, ?array $query, ?SavedFilter $filter = null): SavedFilter
    {
        $name = $name === null ? null : trim(preg_replace('/\s+/u', ' ', $name));
        if ($name !== null && $name === '') {
            throw ValidationException::withMessages(['name' => 'Впишите название']);
        }
        if (! $filter && SavedFilter::where('user_id', $user->id)->where('list', $list)->count() >= SavedFilter::LIMIT) {
            throw ValidationException::withMessages(['name' => 'Сохранённых фильтров уже '.SavedFilter::LIMIT.', удалите ненужный']);
        }
        $clean = $query === null ? null : collect($query)
            ->filter(fn ($v, $k) => is_string($k) && preg_match('/^[a-z_]{1,24}$/', $k) && ! in_array($k, SavedFilter::DROP, true) && (is_string($v) || is_int($v) || $v === null))
            ->map(fn ($v) => mb_substr((string) $v, 0, 200))->take(40)->all();

        $filter ??= new SavedFilter(['user_id' => $user->id, 'list' => $list,
            'position' => (int) SavedFilter::where('user_id', $user->id)->where('list', $list)->max('position') + 1]);
        $filter->fill(array_filter(['name' => $name !== null ? mb_substr($name, 0, 60) : null, 'query' => $clean], fn ($v) => $v !== null))->save();

        return $filter;
    }
}
