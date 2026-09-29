{{-- Номер ДЛ (копируется), факты машины нейтральными тегами, метки оффера цветом, НДС. --}}
@props(['offer', 'facts' => true, 'vat' => true])
@php($style = fn (string $color) => \App\Offers\Tag::style($color))
@if ($ref = $offer->leaseRef())<span class="tag nums gap-1">ДЛ<x-ui.copy-code :value="$ref" done="Номер ДЛ в буфере"/></span>@endif
@if ($facts)
    @foreach (array_filter([$offer->year, $offer->transmission?->label(), $offer->drive?->label()]) as $fact)
        <span class="tag">{{ $fact }}</span>
    @endforeach
@endif
@foreach ($offer->tags ?? [] as $name)
    <span class="tag" style="{{ $style(\App\Offers\Tag::colorOf($name, $offer->tag_colors)) }}">{{ $name }}</span>
@endforeach
@if ($vat)
    <span class="tag" style="{{ $style($offer->prices_include_vat ? 'amber' : 'grey') }}">{{ $offer->prices_include_vat ? 'С НДС' : 'Без НДС' }}</span>
@endif
