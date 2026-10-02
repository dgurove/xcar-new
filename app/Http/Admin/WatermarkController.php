<?php

namespace App\Http\Admin;

use App\Media\Watermarks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Чужие водяные знаки: название, «снимать», сколько снято; собранные по фото удаляются, встроенные — только выключаются. */
class WatermarkController
{
    public function index()
    {
        $done = Media::query()->whereNotNull(DB::raw("custom_properties->>'unmarked'"))
            ->groupBy(DB::raw("custom_properties->>'unmarked'"))
            ->pluck(DB::raw('count(*)'), DB::raw("custom_properties->>'unmarked'"));

        return view('admin.watermarks.index', ['marks' => Watermarks::registry(), 'done' => $done]);
    }

    public function update(Request $request, string $name)
    {
        $this->known($name);
        $data = $request->validate(['title' => ['required', 'string', 'max:40']]);
        Watermarks::set($name, ['title' => trim($data['title']), 'off' => ! $request->boolean('on')]);

        return redirect('/settings/watermarks')->with('toast', 'Сохранено');
    }

    public function destroy(string $name)
    {
        abort_unless($this->known($name)['learned'], 404);
        Watermarks::forget($name);

        return redirect('/settings/watermarks')->with('toast', 'Удалён');
    }

    /** Знак картинкой для строки списка: вектор как есть, собранный — серая форма. */
    public function image(string $name)
    {
        $path = $this->known($name)['image'];
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png']);
    }

    private function known(string $name): array
    {
        return Watermarks::registry()[$name] ?? abort(404);
    }
}
