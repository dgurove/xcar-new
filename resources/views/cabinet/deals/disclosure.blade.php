{{-- Что админ показал из писем страховой одним разом (`Offers\Disclosure`, 03.11.2026, владелец: «показывается ТОЛЬКО то, что
     выбрано»): дата, телефоны ссылками, имя, адрес с картой, сообщение, документы. Самих писем менеджер не видит никогда. --}}
{{-- Телефон, имя и адрес, что уже стоят в «Получении автомобиля» рядом, второй раз не пишутся (`Disclosure::besides`). --}}
@php $items = collect($disclosure->besides($handover ?? null)); $files = $disclosure->files()->reject(fn ($m) => in_array($m->id, $skipFiles ?? [], true))->values(); @endphp
@if ($items->where('kind', '!=', 'file')->isNotEmpty() || $files->isNotEmpty())
<div class="flex flex-col gap-1.5">
    <p class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($disclosure->created_at, true, words: false) }}</p>
    {{-- Сведения значками, как «От XCar» (11.11.2026): текст, человек с телефонами, адрес с картой. --}}
    <x-ui.info>
        @foreach ($items->where('kind', 'text') as $item)
            <x-ui.info-row icon="chat" pre>{{ $item['value'] }}</x-ui.info-row>
        @endforeach
        @php $names = $items->where('kind', 'name'); $phones = $items->where('kind', 'phone'); @endphp
        @if ($names->isNotEmpty() || $phones->isNotEmpty())
            <div class="info-row">
                <x-ui.icon name="user" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">
                    @foreach ($names as $item)<span class="block break-words">{{ $item['value'] }}</span>@endforeach
                    @foreach ($phones as $item)<x-ui.tel :value="$item['value']" class="block w-fit"/>@endforeach
                </span>
            </div>
        @endif
        @foreach ($items->where('kind', 'address') as $item)
            <x-ui.info-row icon="map-pin" :href="'https://yandex.ru/maps/?text='.rawurlencode($item['value'])">{{ $item['value'] }}</x-ui.info-row>
        @endforeach
        {{-- Фото письма — сеткой миниатюр, а не двадцатью строками файлов (16.11.2026, «Показать менеджеру» отдаёт кадры письма разом). --}}
        @php [$shots, $docs] = $files->partition(fn ($m) => str_starts_with((string) $m->mime_type, 'image/')); @endphp
        @if ($docs->isNotEmpty())
            <div class="info-row flex-col !gap-0 !py-1">
                @foreach ($docs as $media)<x-ui.file :name="$media->file_name" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}"/>@endforeach
            </div>
        @endif
        @if ($shots->isNotEmpty())
            <div class="info-row !py-2">
                <div class="grid w-full grid-cols-4 gap-1.5">
                    @foreach ($shots as $media)<a href="/files/{{ $media->id }}" target="_blank" class="block aspect-square overflow-hidden rounded-(--radius-s) bg-surface-3"><img src="/files/{{ $media->id }}" alt="" loading="lazy" class="size-full object-cover"></a>@endforeach
                </div>
            </div>
        @endif
    </x-ui.info>
</div>
@endif
