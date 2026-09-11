{{-- Секция содержимого: белая карточка со скруглением 24, вложенная — 16 на surface-2. --}}
@props(['title' => null, 'nested' => false])
<section {{ $attributes->merge(['class' => $nested ? 'box-nested' : 'box']) }}>
    @if ($title)<h2 class="mb-4 text-xl">{{ $title }}</h2>@endif
    {{ $slot }}
</section>
