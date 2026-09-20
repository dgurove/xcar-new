{{-- Секция содержимого: белая карточка со скруглением 24, вложенная — 16 на surface-2. count — число рядом с заголовком. --}}
@props(['title' => null, 'nested' => false, 'count' => null])
<section {{ $attributes->merge(['class' => $nested ? 'box-nested' : 'box']) }}>
    @if ($title)<h2 class="mb-4 text-xl">{{ $title }}@if ($count !== null) <span class="nums text-base font-normal text-ink-dim">{{ $count }}</span>@endif</h2>@endif
    {{ $slot }}
</section>
