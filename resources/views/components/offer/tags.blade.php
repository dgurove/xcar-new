{{-- Номер ДЛ (копируется) и метки оффера цветом — чипами; коробка, привод и НДС — строкой текста (год уже в названии) за ними
     (владелец 10.10.2026: «что-то чипами, что-то текстом»; коды и метки — чипы, факты — текст). extra — ещё куски
     этой строки («видят 4»). --}}
@props(['offer', 'facts' => true, 'vat' => true, 'extra' => []])
@php
    $style = fn (string $color) => \App\Offers\Tag::style($color);
    $text = array_values(array_filter([
        ...($facts ? [$offer->transmission?->label(), $offer->drive?->label()] : []),
        $vat ? ($offer->prices_include_vat ? 'с НДС' : 'без НДС') : null,
        ...$extra,
    ]));
@endphp
@if ($ref = $offer->leaseRef())<span class="tag nums gap-1">ДЛ<x-ui.copy-code :value="$ref" done="Номер ДЛ в буфере"/></span>@endif
@foreach ($offer->tags ?? [] as $name)
    <span class="tag" style="{{ $style(\App\Offers\Tag::colorOf($name, $offer->tag_colors)) }}">{{ $name }}</span>
@endforeach
@if ($text)<span class="nums text-sm text-ink-muted">{{ implode(', ', $text) }}</span>@endif
