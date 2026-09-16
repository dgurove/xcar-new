<?php

namespace App\Http\Admin;

use App\Offers\Offer;
use App\Offers\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Метки предложений: название, цвет, порядок. Метка живёт в offers.tags названием — переименование и удаление проходят по офферам. */
class TagController
{
    public function index()
    {
        $used = Offer::query()->select(DB::raw('t as name, count(*) as n'))->crossJoin(DB::raw('jsonb_array_elements_text(tags) as t'))->groupBy('t')->pluck('n', 'name');

        return view('admin.tags.index', ['tags' => Tag::orderBy('sort')->get(), 'used' => $used]);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['sort'] = (int) Tag::max('sort') + 1;
        Tag::create($data);

        return redirect('/settings/tags')->with('toast', 'Добавлена');
    }

    public function update(Request $request, Tag $tag)
    {
        $data = $this->data($request, $tag);
        DB::transaction(function () use ($tag, $data) {
            if ($data['name'] !== $tag->name) {
                $this->rename($tag->name, $data['name']);
            }
            $tag->update($data);
        });

        return redirect('/settings/tags')->with('toast', 'Сохранено');
    }

    public function destroy(Tag $tag)
    {
        DB::transaction(function () use ($tag) {
            $this->rename($tag->name, null);
            $tag->delete();
        });

        return redirect('/settings/tags')->with('toast', 'Удалена');
    }

    public function reorder(Request $request)
    {
        $order = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']])['order'];
        foreach (array_values($order) as $i => $id) {
            Tag::whereKey($id)->update(['sort' => $i]);
        }

        return response()->noContent();
    }

    private function data(Request $request, ?Tag $tag = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('tags', 'name')->ignore($tag)],
            'color' => ['required', Rule::in(array_keys(Tag::COLORS))],
        ]);
    }

    /** Переименовать метку на офферах; null — убрать. */
    private function rename(string $from, ?string $to): void
    {
        $offers = Offer::whereJsonContains('tags', $from)->get();
        foreach ($offers as $offer) {
            $tags = array_values(array_unique(array_filter(array_map(fn ($t) => $t === $from ? $to : $t, $offer->tags ?? []))));
            $offer->forceFill(['tags' => $tags])->saveQuietly();
        }
    }
}
