{{-- Поля «Деньги» — одни на редактор и окошко строки. Серые подсказки заявленной и минимальной считаются на ходу (min-bid). --}}
<div class="{{ $grid }}" data-controller="min-bid">
    <x-ui.field name="floor_price" data-controller="digits" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price"/>
    <x-ui.field name="publish_price" data-controller="digits" data-action="input->digits#format" label="Заявленная, ₽" :value="$offer->publish_price" :placeholder="$offer->floor_price ? \App\Support\Money::nums(\App\Offers\Offer::declaredFrom($offer->floor_price)) : null"/>
    {{-- В окошке у черновика цена продажи стоит в «Оценке» рядом с «В продажу» — второй раз не нужна. --}}
    @unless ($askingElsewhere ?? false)<x-ui.field name="asking_price" data-controller="digits" data-action="input->digits#format" label="Цена продажи, ₽" :value="$offer->asking_price"/>@endunless
    <x-ui.field name="min_bid_price" data-controller="digits" data-action="input->digits#format" label="Минимальная, ₽" :value="$offer->min_bid_price" :placeholder="$offer->minBid() ? \App\Support\Money::nums($offer->minBid()) : null"/>
    <x-ui.field name="min_bid_share" label="Доля до продажной" :value="$offer->min_bid_share" placeholder="0,6"/>
    <x-ui.field name="bids_close_at" label="Приём подтверждений до" type="datetime-local" :value="$offer->bids_close_at?->format('Y-m-d\TH:i')" span="col-span-2 lg:col-span-1"/>
    <div class="col-span-full flex flex-wrap items-center gap-x-6 gap-y-2 pt-1">
        <x-ui.check name="recommended" :checked="$offer->recommended">Рекомендуем</x-ui.check>
        <x-ui.check name="prices_include_vat" :checked="$offer->prices_include_vat">С НДС</x-ui.check>
        <x-ui.check name="chat_enabled" :checked="$offer->chat_enabled">Чат с покупателями</x-ui.check>
        <x-ui.check name="share_locked" :checked="$offer->share_locked">Запретить шеринг</x-ui.check>
    </div>
    {{-- Метки цветом: справочник и разовые этого предложения (их нет в справочнике — без них сохранение стёрло бы их
         молча), последним — «+» для своей метки с выбором цвета (tag-add). Под метками — описание. --}}
    @php
        $current = old('tags', $offer->tags ?? []);
        $own = old('tag_colors') ? (json_decode(old('tag_colors'), true) ?: []) : ($offer->tag_colors ?? []);
    @endphp
    <div class="field col-span-full" data-controller="tag-add">
        <span class="field-label">Метки</span>
        <input type="hidden" name="tag_colors" value="{{ json_encode((object) $own) }}" data-tag-add-target="colors">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($tags->pluck('name')->merge(array_diff($current, $tags->pluck('name')->all())) as $name)
                <label class="choice choice-tag" style="{{ \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own)) }}"><input type="checkbox" name="tags[]" value="{{ $name }}" @checked(in_array($name, $current))><span>{{ $name }}</span></label>
            @endforeach
            <button type="button" class="choice-add" data-tag-add-target="button" data-action="tag-add#open" aria-label="Своя метка">+</button>
            <span class="flex flex-wrap items-center gap-1.5" data-tag-add-target="draft" hidden>
                <input type="text" class="choice-input" maxlength="40" data-tag-add-target="input" data-action="keydown.enter->tag-add#add:prevent keydown.esc->tag-add#close">
                @foreach (array_keys(\App\Offers\Tag::COLORS) as $color)
                    <button type="button" class="tag-dot" style="{{ \App\Offers\Tag::style($color) }}" data-tag-add-target="dot" data-color="{{ $color }}" data-action="tag-add#pick" aria-label="{{ \App\Offers\Tag::COLORS[$color] }}" aria-pressed="{{ $color === 'grey' ? 'true' : 'false' }}"></button>
                @endforeach
                <button type="button" class="pill pill-plain" data-action="tag-add#add">Добавить</button>
            </span>
        </div>
    </div>
    <x-ui.field name="description" label="Описание" type="textarea" :value="$offer->description" span="col-span-full"/>
</div>
