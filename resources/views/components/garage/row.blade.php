{{-- Машина гаража строкой сгруппированного списка `.list` на сайте (вид «Строками», телефон без выбора): кадр как у строк
     сделок (.row-photo, нет фото — силуэт в той же клетке), название, этап цветным словом с когда и чья, справа главное
     число этапа с подписью; числа нет — шеврон. Вся строка — ссылка на страницу машины или вывоза. --}}
@props(['offer', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null])
@php $photo = $offer->mainPhoto(); $tail = implode(', ', array_filter([$when, $who])); @endphp
<a href="{{ $href }}" class="row">
    <span class="row-photo flex items-center justify-center text-ink-dim">@if ($photo)<x-offer.photo :media="$photo" sizes="72px"/>@else<x-ui.icon name="cat-car" class="size-7"/>@endif</span>
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $offer->titleWithYear() }}</span>
        <span class="row-sub block"><span class="{{ $tone }}">{{ $word }}</span>@if ($tail), {{ $tail }}@endif</span>
    </span>
    @if ($caption)
        <span class="shrink-0 text-right">
            <span class="nums block">{{ \App\Support\Money::exact($value) }}</span>
            <span class="block text-sm text-ink-muted">{{ $caption }}</span>
        </span>
    @else
        <x-ui.chevron/>
    @endif
</a>
