{{-- Факты машины нейтральными тегами, метки оффера цветом, НДС. --}}
@props(['offer', 'facts' => true, 'vat' => true])
@php
    $palette = [
        'lime' => ['#f0f7d8', '#669709', '#1a2605', '#a6cf3a'],
        'orange' => ['#fff3e0', '#c26a00', '#2a1a00', '#ffb15c'],
        'red' => ['#ffe8ec', '#d10030', '#33000a', '#ff6b8a'],
        'blue' => ['#e3ecfb', '#2456b3', '#101c33', '#8ab4ff'],
        'grey' => ['#ececec', '#666666', '#242424', '#9a9a9a'],
        'amber' => ['#fef3c7', '#92400e', '#3f2606', '#fcd34d'],
    ];
    $colors = once(fn () => \App\Offers\Tag::query()->pluck('color', 'name')->all());
    $style = fn (string $color) => isset($palette[$color]) ? sprintf('--tag-bg:%s;--tag-text:%s;--tag-bg-d:%s;--tag-text-d:%s', ...$palette[$color]) : '';
@endphp
@if ($facts)
    @foreach (array_filter([$offer->year, $offer->transmission?->label(), $offer->drive?->label()]) as $fact)
        <span class="tag">{{ $fact }}</span>
    @endforeach
@endif
@foreach ($offer->tags ?? [] as $name)
    <span class="tag" style="{{ $style($colors[$name] ?? 'grey') }}">{{ $name }}</span>
@endforeach
@if ($vat)
    <span class="tag" style="{{ $style($offer->prices_include_vat ? 'amber' : 'grey') }}">{{ $offer->prices_include_vat ? 'С НДС' : 'Без НДС' }}</span>
@endif
