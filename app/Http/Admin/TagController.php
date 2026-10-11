<?php

namespace App\Http\Admin;

use App\Offers\Offer;
use App\Offers\Tag;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Метки предложений: название, цвет, порядок. Метка живёт в offers.tags названием — переименование и удаление проходят по офферам. */
class TagController
{
    public function index()
    {
        $used = Offer::query()->select(DB::raw('t as name, count(*) as n'))->crossJoin(DB::raw('jsonb_array_elements_text(tags) as t'))->groupBy('t')->pluck('n', 'name');

        // Вендоры CRM для «Ставится вендорам» (11.10.2026): парковочный двойник («Альфа СПб») здесь не стоит.
        $vendors = Vendor::whereNull('crm_vendor_id')->where('is_active', true)->orderBy('name')->get();

        return view('admin.tags.index', ['tags' => Tag::with('vendors')->orderBy('sort')->get(), 'used' => $used, 'vendors' => $vendors]);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $data['sort'] = (int) Tag::max('sort') + 1;
        Tag::create($data)->vendors()->sync($this->vendors($request));

        return redirect('/settings/tags')->with('toast', 'Добавлена');
    }

    public function update(Request $request, Tag $tag)
    {
        $data = $this->data($request, $tag);
        $vendors = $this->vendors($request);
        DB::transaction(function () use ($tag, $data, $vendors) {
            if ($data['name'] !== $tag->name) {
                $this->rename($tag->name, $data['name']);
            }
            $tag->update($data);
            $tag->vendors()->sync($vendors);
        });

        return redirect('/settings/tags')->with('toast', 'Сохранено');
    }

    /** Перекрасить метку из редактора предложения («Цвет» у меток): сразу, без «Сохранить», и везде. */
    public function color(Request $request, Tag $tag)
    {
        $tag->update($request->validate(['color' => ['required', Rule::in(array_keys(Tag::COLORS))]]));

        return response()->json(['style' => Tag::style($tag->color)]);
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

    /** Вендоры, чьим черновикам метка ставится сама; только CRM-вендоры. */
    private function vendors(Request $request): array
    {
        $ids = array_map('intval', (array) ($request->validate(['vendors' => ['array'], 'vendors.*' => ['integer']])['vendors'] ?? []));

        return Vendor::whereKey($ids)->whereNull('crm_vendor_id')->pluck('id')->all();
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
