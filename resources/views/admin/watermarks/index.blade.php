{{-- Водяные знаки площадок: встроенные и собранные по фото рамкой в шторке «Водяной знак». Строка — шторка: название,
     «Снимать» (выключенный не ищется ни при загрузке, ни в шторке), у собранного — «Удалить». --}}
<x-ui.cabinet title="Водяные знаки">
    <div class="list">
        @foreach ($marks as $name => $mark)
            <div class="contents" data-controller="sheet">
                <button type="button" class="row w-full text-left" data-action="sheet#open">
                    <span @class(["row-photo row-photo-s flex items-center justify-center p-1", "bg-chrome" => $mark["learned"]])><img src="/settings/watermarks/{{ $name }}/image" alt="" class="!size-auto max-h-full max-w-full object-contain"></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $mark['title'] }}</span>
                        <span class="row-sub">
                            @if ($mark['off'])<span class="text-ink-dim">не снимается</span>@endif
                            @if ($done[$name] ?? 0)<span>снят с {{ $done[$name] }} фото</span>@endif
                        </span>
                    </span>
                    <x-ui.chevron/>
                </button>
                <x-ui.sheet id="mark-{{ $name }}" :title="$mark['title']">
                    <form method="post" action="/settings/watermarks/{{ $name }}" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.field name="title" label="Площадка" :value="$mark['title']" required maxlength="40"/>
                        <div class="flags"><x-ui.check name="on" :checked="! $mark['off']">Снимать</x-ui.check></div>
                        <x-ui.button block>Сохранить</x-ui.button>
                    </form>
                    @if ($mark['learned'])
                        <form method="post" action="/settings/watermarks/{{ $name }}" class="mt-2" data-turbo-confirm="Удалить знак «{{ $mark['title'] }}»?">
                            @csrf @method('delete')
                            <x-ui.button block variant="danger">Удалить</x-ui.button>
                        </form>
                    @endif
                </x-ui.sheet>
            </div>
        @endforeach
    </div>
</x-ui.cabinet>
