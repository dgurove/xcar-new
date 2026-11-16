<?php

namespace App\Http\Admin;

use App\Support\Facets\Actions\DeleteFilter;
use App\Support\Facets\Actions\SaveFilter;
use App\Support\Facets\SavedFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Сохранённые фильтры окна «Фильтр» (17.11.2026): только свои — чужой номер отвечает 404. Ответ — редирект: новый и
 * обновлённый открываются, после переименования и удаления — назад на экран. Ошибка (20 фильтров, пустое название) — тостом:
 * поля окна ошибок не рисуют, и «Сохранить» молча ничего не делало.
 */
final class SavedFilterController
{
    public function store(Request $request, SaveFilter $save)
    {
        return $this->said(function () use ($request, $save) {
            $data = $request->validate(['list' => 'required|in:'.implode(',', array_keys(SavedFilter::PATHS)), 'name' => 'required|string|max:200', 'query' => 'required|string|max:8000'],
                ['name.required' => 'Впишите название', 'query.required' => 'Не получилось прочитать выбор — обновите страницу']);
            $filter = $save($request->user(), $data['list'], $data['name'], $this->query($data['query']));

            return redirect($filter->url())->with('toast', 'Фильтр «'.$filter->name.'» сохранён');
        });
    }

    public function update(Request $request, int $filter, SaveFilter $save)
    {
        $row = $this->own($request, $filter);

        return $this->said(function () use ($request, $row, $save) {
            $data = $request->validate(['name' => 'required_without:query|nullable|string|max:200', 'query' => 'nullable|string|max:8000'], ['name.required_without' => 'Впишите название']);
            $save($request->user(), $row->list, $data['name'] ?? null, isset($data['query']) ? $this->query($data['query']) : null, $row);

            return isset($data['query']) ? redirect($row->url())->with('toast', 'Фильтр «'.$row->name.'» обновлён') : back();
        });
    }

    public function destroy(Request $request, int $filter, DeleteFilter $delete)
    {
        $row = $this->own($request, $filter);
        $delete($row);

        return back()->with('toast', 'Фильтр «'.$row->name.'» удалён');
    }

    private function own(Request $request, int $id): SavedFilter
    {
        return SavedFilter::where('user_id', $request->user()->id)->findOrFail($id);
    }

    /** Ошибка проверки — назад тостом с её словами. */
    private function said(\Closure $run): RedirectResponse
    {
        try {
            return $run();
        } catch (ValidationException $e) {
            return back()->with('toast-danger', collect($e->errors())->flatten()->first() ?? 'Не получилось сохранить');
        }
    }

    /** @return array<string, string> */
    private function query(string $json): array
    {
        $q = json_decode($json, true);
        if (! is_array($q)) {
            throw ValidationException::withMessages(['query' => 'Не получилось прочитать выбор — обновите страницу']);
        }

        return $q;
    }
}
