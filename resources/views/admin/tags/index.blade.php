@php
    $palette = ['lime' => '#97bf0d', 'orange' => '#ff8800', 'red' => '#ff0037', 'blue' => '#2456b3', 'grey' => '#808080'];
@endphp
<x-ui.shell title="Метки" :count="$tags->count()" narrow>
    <div class="flex flex-col gap-2" data-controller="order" data-order-url-value="/settings/tags/order">
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
    <div class="mt-4" data-controller="sheet">
        <x-ui.button type="button" variant="secondary" data-action="sheet#open"><x-ui.icon name="plus" class="size-5"/> Метка</x-ui.button>
        <x-ui.sheet id="tag-new" title="Новая метка">
            <form method="post" action="/settings/tags" class="flex flex-col gap-4">
                @csrf
                <x-ui.field name="name" label="Название" required maxlength="40" autofocus/>
                <x-ui.field name="color" label="Цвет" :options="\App\Offers\Tag::COLORS" value="grey"/>
                <x-ui.button block>Добавить</x-ui.button>
            </form>
        </x-ui.sheet>
    </div>
</x-ui.shell>
