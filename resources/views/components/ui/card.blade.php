{{-- Секция содержимого: белая карточка со скруглением 24, вложенная — 16 на surface-2. count — число рядом с заголовком,
     слот chips — чипы справа от заголовка (срок этапа, перевозчик), actions — у правого края («···»). --}}
@props(['title' => null, 'nested' => false, 'count' => null])
<section {{ $attributes->merge(['class' => $nested ? 'box-nested' : 'box']) }}>
    @if ($title)
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <h2>{{ $title }}@if ($count !== null) <span class="nums text-sm font-normal text-ink-dim">{{ $count }}</span>@endif</h2>
            @if (isset($chips) && trim($chips) !== '')<div class="flex flex-wrap items-center gap-1.5">{{ $chips }}</div>@endif
            @if (isset($actions) && trim($actions) !== '')<div class="-my-1 ml-auto flex items-center">{{ $actions }}</div>@endif
        </div>
    @endif
    {{ $slot }}
</section>
