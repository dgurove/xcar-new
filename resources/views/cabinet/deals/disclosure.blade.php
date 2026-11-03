{{-- Что админ показал из писем страховой одним разом (`Offers\Disclosure`, 03.11.2026, владелец: «показывается ТОЛЬКО то, что
     выбрано»): дата, телефоны ссылками, имя, адрес с картой, сообщение, документы. Самих писем менеджер не видит никогда. --}}
@php $items = collect($disclosure->items); @endphp
<div class="reply">
    <p class="nums text-sm text-ink-dim">{{ \App\Support\Ago::html($disclosure->created_at, true, words: false) }}</p>
    @foreach ($items->whereIn('kind', ['name', 'text']) as $item)
        <p @class(['mt-1 break-words', 'whitespace-pre-line' => $item['kind'] === 'text'])>{{ $item['value'] }}</p>
    @endforeach
    @if ($items->where('kind', 'phone')->isNotEmpty())
        <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($items->where('kind', 'phone') as $item)<a href="tel:{{ preg_replace('/[^\d+]/', '', $item['value']) }}" class="handover-phone nums"><x-ui.icon name="phone" class="size-4"/>{{ $item['value'] }}</a>@endforeach
        </p>
    @endif
    @foreach ($items->where('kind', 'address') as $item)
        <p class="mt-1 break-words"><a href="https://yandex.ru/maps/?text={{ rawurlencode($item['value']) }}" target="_blank" rel="noopener"><x-ui.place>{{ $item['value'] }}</x-ui.place></a></p>
    @endforeach
    @if (($files = $disclosure->files())->isNotEmpty())
        <div class="mt-2 flex flex-col">
            @foreach ($files as $media)<x-ui.file :name="$media->file_name" :mime="$media->mime_type" :size="$media->humanReadableSize" href="/files/{{ $media->id }}"/>@endforeach
        </div>
    @endif
</div>
