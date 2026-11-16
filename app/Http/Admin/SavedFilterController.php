<?php

namespace App\Http\Admin;

use App\Support\Facets\Actions\SaveFilter;
use App\Support\Facets\SavedFilter;
use Illuminate\Http\Request;

/**
 * Сохранённые фильтры шторки «Фильтр» (17.11.2026): только свои — чужой номер отвечает 404. Ответ — редирект: новый и
 * обновлённый открываются, после переименования и удаления — назад на экран.
 */
final class SavedFilterController
{
    public function store(Request $request, SaveFilter $save)
    {
        $data = $request->validate(['list' => 'required|in:'.implode(',', array_keys(SavedFilter::PATHS)), 'name' => 'required|string|max:200', 'query' => 'required|string|max:8000']);
        $filter = $save($request->user(), $data['list'], $data['name'], $this->query($data['query']));

        return redirect($filter->url())->with('toast', 'Фильтр «'.$filter->name.'» сохранён');
    }

    public function update(Request $request, int $filter, SaveFilter $save)
    {
        $row = $this->own($request, $filter);
        $data = $request->validate(['name' => 'nullable|string|max:200', 'query' => 'nullable|string|max:8000']);
        $save($request->user(), $row->list, $data['name'] ?? null, isset($data['query']) ? $this->query($data['query']) : null, $row);

        return isset($data['query']) ? redirect($row->url())->with('toast', 'Фильтр «'.$row->name.'» обновлён') : back();
    }

    public function destroy(Request $request, int $filter)
    {
        $row = $this->own($request, $filter);
        $row->delete();

        return back()->with('toast', 'Фильтр «'.$row->name.'» удалён');
    }

    private function own(Request $request, int $id): SavedFilter
    {
        return SavedFilter::where('user_id', $request->user()->id)->findOrFail($id);
    }

    /** @return array<string, string> */
    private function query(string $json): array
    {
        $q = json_decode($json, true);
        abort_unless(is_array($q), 422);

        return $q;
    }
}
