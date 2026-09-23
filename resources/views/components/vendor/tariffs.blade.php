{{-- Прайс: строка — категория ТС (первая — «Любая»), в строке чипами цены по услугам; нажатие по строке —
     шторка со всеми её строками прайса и новой. Базовый прайс без вендора; у вендора его строки поверх
     базовых: базовая цена в ячейке без своей строки — притушена. Площадка — пилюлями сверху, всё в адресе. --}}
@props(['rows', 'yards', 'yardId', 'categories', 'services', 'vendor' => null, 'href'])
@php
    use App\Vendors\{Tariff, TariffService};
    $vendorId = $vendor?->id;
    $link = fn ($yard) => $href.(str_contains($href, '?') ? '&' : '?').($yard ? 'yard='.$yard : 'yard=');
    // Ячейка: свои строки вендора важнее базовых, строки площадки важнее общих — как в Tariff::ladder.
    $cell = function (?string $cat, TariffService $service) use ($rows, $vendorId, $yardId) {
        $mine = $rows->filter(fn ($t) => $t->service === $service && ($t->category?->value ?? null) === $cat);
        foreach ([[$vendorId, $yardId], [$vendorId, null], [null, $yardId], [null, null]] as [$v, $y]) {
            $level = $mine->filter(fn ($t) => $t->vendor_id === $v && $t->yard_id === $y)->sortBy('from_day')->values();
            if ($level->isNotEmpty()) {
                return [$level, $v === $vendorId && $y === $yardId];
            }
        }

        return [collect(), true];
    };
    $groups = [null => 'Любая категория'] + collect($categories)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
@endphp
<div class="flex flex-col gap-4">
    @if ($yards->isNotEmpty())
        <div class="flex flex-wrap gap-1.5">
            <x-ui.pill :href="$link(null)" :current="!$yardId">Все парковки</x-ui.pill>
            @foreach ($yards as $yard)<x-ui.pill :href="$link($yard->id)" :current="$yardId === $yard->id">{{ $yard->name }}</x-ui.pill>@endforeach
        </div>
    @endif
    <div class="flex flex-col gap-2">
        @foreach ($groups as $catValue => $catLabel)
            @php $catValue = $catValue === '' ? null : $catValue; $own = $rows->filter(fn ($t) => ($t->category?->value ?? null) === $catValue && $t->vendor_id === $vendorId && $t->yard_id === $yardId)->sortBy(fn ($t) => [$t->service->value, $t->from_value ?? -1, $t->from_day]); @endphp
            <div class="row" data-controller="sheet">
                <button type="button" class="contents text-left" data-action="sheet#open">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $catLabel }}</span>
                        <span class="row-sub mt-1 flex flex-wrap gap-1.5">
                            @foreach ($services as $service)
                                @php [$ladder, $isOwn] = $cell($catValue, $service); $label = Tariff::ladderLabel($ladder); @endphp
                                @if ($label)<span class="chip nums {{ $isOwn ? '' : 'text-ink-dim' }}">{{ $service->label() }} {{ $label }}</span>@endif
                            @endforeach
                        </span>
                    </span>
                    <x-ui.icon name="chevron-right" class="size-5 shrink-0 text-ink-dim"/>
                </button>
                {{-- Шторка остаётся открытой после сохранения (session('sheet')): прайс вводят лестницу за лестницей. --}}
                <x-ui.sheet id="tariff-{{ $catValue ?? 'any' }}" :title="$catLabel" wide :open="session('sheet') === ($catValue ?? 'any') || ($errors->any() && old('sheet') === ($catValue ?? 'any'))">
                    <div class="flex flex-col gap-3">
                        @foreach ($own->groupBy(fn ($t) => $t->service->value) as $steps)
                            <x-vendor.tariff-ladder :service="$steps->first()->service" :rows="$steps->values()" :vendor-id="$vendorId" :yard-id="$yardId" :cat-value="$catValue"/>
                        @endforeach
                        <x-vendor.tariff-ladder :vendor-id="$vendorId" :yard-id="$yardId" :cat-value="$catValue"/>
                    </div>
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
</div>
