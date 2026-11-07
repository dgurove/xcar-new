{{-- «Настройки → Парсинг» (`Admin\ParsingController`, 08.11.2026): машины, у которых источники спорят, — карточкой на машину
     (`admin/parsing/car`). Пилюли — по полю с числом машин; пусто — одна фраза. --}}
<x-ui.cabinet title="Парсинг">
    <x-ui.pills>
        <x-ui.pill href="/settings/parsing" :current="! $field" :tone="! $field ? 'accent' : null">Все <span class="nums opacity-70">{{ $total }}</span></x-ui.pill>
        @foreach ($counts as $f => $n)
            <x-ui.pill :href="'/settings/parsing?field='.$f" :current="$field === $f" :tone="$field === $f ? 'accent' : null">{{ \App\Cars\Sources\Values::title($f) }} <span class="nums opacity-70">{{ $n }}</span></x-ui.pill>
        @endforeach
    </x-ui.pills>
    <div class="mt-4 flex flex-col gap-2" id="list">
        @forelse ($cars as $item)
            @include('admin.parsing.car', ['item' => $item, 'only' => $field])
        @empty
            <x-ui.empty>Источники нигде не спорят</x-ui.empty>
        @endforelse
    </div>
</x-ui.cabinet>
