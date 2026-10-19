{{-- «Осмотр» — поля продажи, а не машины (19.10.2026: «Транспортное средство» теперь один блок с парковкой): город, адрес
     осмотра с глазиком «на сайте», описание. Та же карточка блока машины (`x-car.card`): с публикации — «Изменить / Готово»,
     в окошке строки — разделом. Ошибка формы «Получения» (адрес — общее поле) блок не раскрывает. --}}
@php
    $as ??= 'card';
    $fold ??= null;
    $keys = ['settlement_id', 'inspection_address', 'description'];
    if ($fold && ! str_starts_with((string) old('_form'), 'handover-') && $errors->hasAny($keys)) {
        $fold = false;
    }
    $description = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</div>', '</p>'], "\n", (string) $offer->description))));
    $grid = $as === 'card' ? 'grid grid-cols-2 gap-3 @4xl:grid-cols-3' : 'grid grid-cols-[repeat(2,minmax(0,1fr))] gap-x-2 gap-y-2.5';
    $empty = ! $offer->settlement_id && ! filled($offer->inspection_address) && $description === '';
@endphp
<x-car.card title="Осмотр" :fold="$fold" :empty="$empty" :as="$as">
    <x-slot:view>
        @unless ($empty)
            <div class="{{ $grid }} !gap-y-2.5 leading-snug">
                @if ($offer->settlement)<div class="min-w-0"><div class="field-label">Город</div><div class="mt-0.5 break-words"><x-ui.place>{{ $offer->settlement->title() }}</x-ui.place></div></div>@endif
                @if (filled($offer->inspection_address))<div class="col-span-2 min-w-0"><div class="field-label">Адрес осмотра</div><div class="mt-0.5 break-words">{{ $offer->inspection_address }}</div></div>@endif
                @if ($description !== '')<div class="col-span-full min-w-0"><div class="field-label">Описание</div><p class="mt-0.5 line-clamp-2 whitespace-pre-line text-ink-muted">{{ $description }}</p></div>@endif
            </div>
        @endunless
    </x-slot:view>
    <div class="{{ $grid }}">
        <x-ui.combobox name="settlement_id" label="Город" url="/reference/settlements" create="/reference/settlements" :value="$offer->settlement_id" :text="$offer->settlement?->title()"/>
        <x-ui.field name="inspection_address" label="Адрес осмотра" :value="$offer->inspection_address" span="col-span-2">
            <x-slot:after-label><x-ui.eye-check name="show_address" :checked="$offer->show_address"/></x-slot:after-label>
        </x-ui.field>
        <x-ui.field name="description" label="Описание" type="textarea" :value="$offer->description" span="col-span-full"/>
    </div>
</x-car.card>
