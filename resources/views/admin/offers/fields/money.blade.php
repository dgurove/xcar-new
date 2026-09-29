{{-- Поля «Деньги» — одни на редактор и окошко строки. Серые подсказки заявленной и минимальной считаются на ходу (min-bid). --}}
<div class="{{ $grid }}" data-controller="min-bid">
    <x-ui.field name="floor_price" data-controller="digits" data-action="input->digits#format" label="Закупочная, ₽" :value="$offer->floor_price"/>
    <x-ui.field name="publish_price" data-controller="digits" data-action="input->digits#format" label="Заявленная, ₽" :value="$offer->publish_price" :placeholder="$offer->floor_price ? \App\Support\Money::nums($offer->floor_price) : null"/>
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
    @if ($tags->isNotEmpty())
        <div class="field col-span-full">
            <span class="field-label">Метки</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($tags as $tag)
                    <label class="choice"><input type="checkbox" name="tags[]" value="{{ $tag->name }}" @checked(in_array($tag->name, old('tags', $offer->tags ?? [])))><span>{{ $tag->name }}</span></label>
                @endforeach
            </div>
        </div>
    @endif
</div>
