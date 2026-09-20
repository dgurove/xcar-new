{{-- Секция содержимого: белая карточка со скруглением 24, вложенная — 16 на surface-2. count — число рядом с заголовком,
     слот chips — чипы справа от заголовка (срок этапа, перевозчик). --}}
@props(['title' => null, 'nested' => false, 'count' => null])
<section {{ $attributes->merge(['class' => $nested ? 'box-nested' : 'box']) }}>
    @if ($title)
        <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <h2 class="text-xl">{{ $title }}@if ($count !== null) <span class="nums text-base font-normal text-ink-dim">{{ $count }}</span>@endif</h2>
            @if (isset($chips) && trim($chips) !== '')<div class="flex flex-wrap items-center gap-1.5">{{ $chips }}</div>@endif
        </div>
    @endif
    {{ $slot }}
</section>
