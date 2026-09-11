<x-ui.shell :wide="true">
    <form method="get" class="mb-4 flex flex-col gap-3" data-controller="autosubmit">
        <div class="flex gap-2">
            <label class="relative flex-1">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-dim"/>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Марка, модель, номер" class="field-input !bg-surface pl-11" enterkeyhint="search">
            </label>
            <select name="sort" class="field-input !w-auto !bg-surface" data-action="autosubmit#submit" aria-label="Сортировка">
                @foreach (\App\Offers\CatalogQuery::SORTS as $k => $l)<option value="{{ $k }}" @selected(($filters['sort'] ?? 'fresh') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        @if ($brands->count() > 1 || !empty($filters['brand']))
        <div class="presets">
            <a href="{{ request()->fullUrlWithQuery(['brand' => null, 'page' => null]) }}" class="preset" @if (empty($filters['brand'])) aria-current="true" @endif>Все</a>
            @foreach ($brands as $brand)
                <a href="{{ request()->fullUrlWithQuery(['brand' => $brand->slug, 'page' => null]) }}" class="preset" @if (($filters['brand'] ?? null) === $brand->slug) aria-current="true" @endif>{{ $brand->name }}</a>
            @endforeach
        </div>
        @endif
    </form>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" id="catalog">
        @foreach ($offers as $offer)<x-offer.tile :offer="$offer"/>@endforeach
    </div>
    @if ($offers->isEmpty())
        <div class="py-24 text-center text-ink-muted" id="catalog-empty">@if (array_filter($filters)) Ничего не нашлось @else Предложений пока нет @endif</div>
    @else
        <div class="mt-4">{{ $offers->links() }}</div>
    @endif
</x-ui.shell>
