{{-- Чип «Мигторг» (`Offers\MigtorgChip`) — у любого предложения с номером убытка, пять видов:
     найден (ждёт «Это она» — обычным текстом), качается (кольцо и «12 из 54»), взято, ищем (серое кольцо) и нет на
     Мигторге — приглушённо. Нажатие — шторка: машина лота или номер, строки, одна кнопка там, где нужно действие.
     `live` (редактор) — чип перерисовывается по номеру, вписанному в поле до «Сохранить» (`migtorg_controller`),
     а «Это она» этот номер и сохранит (`ref`). --}}
@props(['offer', 'ref' => null, 'live' => false])
@php
    $chip = \App\Offers\MigtorgChip::of($offer);
    $n = $offer->number;
@endphp
@if ($live)<span id="migtorg-{{ $n }}" class="contents" data-controller="migtorg" data-migtorg-url-value="/offers/{{ $n }}/migtorg">@endif
@if ($chip)
<div class="contents" data-controller="sheet">
    <button type="button" @class(['pill pill-plain pill-migtorg', 'is-quiet' => $chip->quiet()]) data-action="sheet#open">
        @if ($chip->is('running', 'searching'))<span @class(['migtorg-ring', 'is-idle' => $chip->is('searching')]) aria-hidden="true"></span>@else<x-offer.migtorg-mark/>@endif
        <span class="nums">{{ $chip->label() }}</span>
    </button>
    <x-ui.sheet id="migtorg-sheet-{{ $n }}" :title="$chip->title()">
        <div class="list">
            @foreach ($chip->rows() as $label => $value)
                <div class="row items-start"><span class="flex-1">{{ $label }}</span><span class="nums max-w-[65%] text-right text-ink-muted">{{ $value }}</span></div>
            @endforeach
        </div>
        @if ($action = $chip->action())
            <form method="post" action="/offers/{{ $n }}/media/migtorg" class="mt-4">
                @csrf
                <input type="hidden" name="act" value="{{ $action[0] }}">
                @if (filled($ref))<input type="hidden" name="ref" value="{{ $ref }}">@endif
                <x-ui.button block :variant="$action[0] === 'take' ? 'primary' : 'secondary'">{{ $action[1] }}</x-ui.button>
            </form>
        @endif
    </x-ui.sheet>
</div>
@endif
@if ($live)</span>@endif
