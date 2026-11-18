{{-- Плитки страницы каталога и хвост догрузки (18.11.2026, `App\Support\PagePart`): страница приходит порциями — первая
     сразу, следующую приносит `endless_controller`, когда до конца остаётся экран, и ставит на место хвоста. Этот же кусок
     — ответ на догрузку (`X-List`). before — плитка перед порцией: подпись пачки посреди пачки не повторяется. --}}
@php
    $before ??= null;
    $group = $before && $groups ? \App\Offers\CloseGroups::label($before, $gallery) : null;
    $labelled = $groups && $view !== \App\Support\ListView::GRID;
@endphp
@foreach ($offers as $offer)
    @if ($labelled && $group !== ($label = \App\Offers\CloseGroups::label($offer, $gallery)))
        @php $group = $label; @endphp
        <h2 class="cards-group">{{ $label }} <span class="nums">{{ $groupSizes[$label] ?? '' }}</span></h2>
    @endif
    <x-offer.card :offer="$offer" :context="$context"/>
@endforeach
@if ($offers instanceof \App\Support\PagePart && $offers->next())
    <div class="cards-tail" data-endless-next="{{ $offers->next() }}" data-rows-next="{{ $offers->after() }}" aria-hidden="true"></div>
@endif
