{{-- Метки цветом: справочник и разовые этого предложения (их нет в справочнике — без них сохранение стёрло бы их
     молча), последним — «+» для своей метки с выбором цвета (tag-add). Карточка строки — в «Деньгах», редактор — в шторке
     из шапки ($form — поля вне формы оффера, $label — подпись «Метки», в шторке её заменяет заголовок). Цвет видно у
     каждой метки; «Цвет» — нажатие по метке открывает палитру: метка справочника перекрашивается везде (сразу, без
     «Сохранить»), разовая — у этого предложения (tag_colors, уходит формой). --}}
@php $form ??= null; @endphp
@php
    $current = old('tags', $offer->tags ?? []);
    $own = old('tag_colors') ? (json_decode(old('tag_colors'), true) ?: []) : ($offer->tag_colors ?? []);
@endphp
<div class="field" data-controller="tag-add">
    @if ($label ?? true)<span class="field-label">Метки</span>@endif
    <input type="hidden" name="tag_colors" value="{{ json_encode((object) $own) }}" data-tag-add-target="colors" @if ($form) form="{{ $form }}" @endif>
    <div class="flex flex-wrap gap-1.5">
        @foreach ($tags->pluck('name')->merge(array_diff($current, $tags->pluck('name')->all())) as $name)
            @php $global = $tags->firstWhere('name', $name); @endphp
            <label class="choice choice-tag" style="{{ \App\Offers\Tag::style(\App\Offers\Tag::colorOf($name, $own)) }}" data-tag-add-target="chip" data-color="{{ \App\Offers\Tag::colorOf($name, $own) }}" data-action="click->tag-add#chip" @if ($global) data-tag-id="{{ $global->id }}" @endif><input type="checkbox" name="tags[]" value="{{ $name }}" @checked(in_array($name, $current)) @if ($form) form="{{ $form }}" @endif><span>{{ $name }}</span></label>
        @endforeach
        <button type="button" class="choice-add" data-tag-add-target="button" data-action="tag-add#open" aria-label="Своя метка">+</button>
        <button type="button" class="choice-add" data-tag-add-target="recolor" data-action="tag-add#recolor" aria-pressed="false">Цвет</button>
        <span class="flex flex-wrap items-center gap-1.5" data-tag-add-target="draft" hidden>
            <input type="text" class="choice-input" maxlength="40" data-tag-add-target="input" data-action="keydown.enter->tag-add#add:prevent keydown.esc->tag-add#close">
            @foreach (array_keys(\App\Offers\Tag::COLORS) as $color)
                <button type="button" class="tag-dot" style="{{ \App\Offers\Tag::style($color) }}" data-tag-add-target="dot" data-color="{{ $color }}" data-action="tag-add#pick" aria-label="{{ \App\Offers\Tag::COLORS[$color] }}" aria-pressed="{{ $color === 'grey' ? 'true' : 'false' }}"></button>
            @endforeach
            <button type="button" class="pill pill-plain" data-action="tag-add#add">Добавить</button>
        </span>
    </div>
    {{-- Палитра перекраски: под рядом меток, пока выбрана метка в режиме «Цвет». --}}
    <div class="mt-2 flex flex-wrap items-center gap-2" data-tag-add-target="palette" hidden>
        @foreach (array_keys(\App\Offers\Tag::COLORS) as $color)
            <button type="button" class="tag-dot" style="{{ \App\Offers\Tag::style($color) }}" data-color="{{ $color }}" data-action="tag-add#paint" aria-label="{{ \App\Offers\Tag::COLORS[$color] }}"></button>
        @endforeach
    </div>
</div>
