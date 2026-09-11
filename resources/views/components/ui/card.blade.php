@props(['title' => null, 'nested' => false])
<section {{ $attributes->merge(['class' => $nested ? 'card-nested' : 'card']) }}>
    @if ($title)<h2 class="mb-4 text-lg">{{ $title }}</h2>@endif
    {{ $slot }}
</section>
