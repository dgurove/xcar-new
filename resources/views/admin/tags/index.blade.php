@php
    $palette = ['lime' => '#97bf0d', 'orange' => '#ff8800', 'red' => '#ff0037', 'blue' => '#2456b3', 'grey' => '#808080'];
@endphp
{{-- Метки: первая строка — новая, ниже список, который переставляют за ручку (строка «новая» вне сортируемого). --}}
<x-ui.cabinet title="Метки">
    <div class="list">
        <div data-controller="sheet" class="contents">
            <button type="button" class="row w-full text-left" data-action="sheet#open">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent text-white"><x-ui.icon name="plus" class="size-5"/></span>
                <span class="min-w-0 flex-1 font-medium">Новая метка</span>
            </button>
            <x-ui.sheet id="tag-new" title="Новая метка">
                <form method="post" action="/settings/tags" class="flex flex-col gap-4">
                    @csrf
                    <x-ui.field name="name" label="Название" required maxlength="40" autofocus/>
                    <x-ui.field name="color" label="Цвет" :options="\App\Offers\Tag::COLORS" value="grey"/>
                    <x-ui.button block>Добавить</x-ui.button>
                </form>
            </x-ui.sheet>
        </div>
    <div class="list" data-controller="order" data-order-url-value="/settings/tags/order">
        @foreach ($tags as $tag)
            <div class="row" data-id="{{ $tag->id }}" data-controller="sheet">
                <span class="cursor-grab touch-none text-ink-dim" data-handle><x-ui.icon name="grip" class="size-5"/></span>
                <span class="size-3 shrink-0 rounded-full" style="background: {{ $palette[$tag->color] ?? $palette['grey'] }}"></span>
                <span class="min-w-0 flex-1 truncate font-medium">{{ $tag->name }}</span>
                @if ($used[$tag->name] ?? 0)<a href="/?q={{ urlencode($tag->name) }}" class="chip nums font-normal">{{ $used[$tag->name] }}</a>@endif
                <button type="button" class="btn btn-ghost btn-s px-2" data-action="sheet#open" aria-label="Изменить"><x-ui.icon name="edit" class="size-5"/></button>
                <x-ui.sheet id="tag-{{ $tag->id }}" title="Метка">
                    <form method="post" action="/settings/tags/{{ $tag->id }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="name" label="Название" :value="$tag->name" required maxlength="40"/>
                        <x-ui.field name="color" label="Цвет" :options="\App\Offers\Tag::COLORS" :value="$tag->color"/>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                    <form method="post" action="/settings/tags/{{ $tag->id }}" class="mt-2" data-turbo-confirm="Удалить метку «{{ $tag->name }}»?{{ ($used[$tag->name] ?? 0) ? ' Она снимется с '.$used[$tag->name].' предложений.' : '' }}">
                        @csrf @method('delete')
                        <x-ui.button block variant="danger">Удалить</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
    </div>
</x-ui.cabinet>
