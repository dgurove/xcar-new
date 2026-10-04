{{-- Чип «Мигторг» в шапке редактора и карточки строки (`Offers\MigtorgChip`): нашёлся лот по номеру убытка — знак и
     слово; идёт загрузка — кольцо и «12 из 54»; всё взято — приглушён, знак того, откуда фото и поля. Нажатие — шторка
     лота: торги, фото, взятые поля и одна кнопка по состоянию. Лота нет — ничего. --}}
@props(['offer'])
@php $chip = \App\Offers\MigtorgChip::of($offer); @endphp
@if ($chip)
<div class="contents" data-controller="sheet">
    <button type="button" @class(['pill pill-plain pill-migtorg', 'is-done' => $chip->done()]) data-action="sheet#open">
        @if ($chip->running())<span class="migtorg-ring" aria-hidden="true"></span>@else<x-offer.migtorg-mark/>@endif
        Мигторг@if ($chip->counter()) <span class="nums">{{ $chip->counter() }}</span>@endif
    </button>
    <x-ui.sheet id="migtorg-{{ $offer->number }}" :title="$chip->lot->title ?: 'Лот Мигторга'">
        <div class="list">
            @if ($chip->ends())<div class="row"><span class="flex-1">Торги</span><span class="text-ink-muted">{{ $chip->ends() === 'закончились' ? 'закончились' : 'до '.$chip->ends() }}</span></div>@endif
            @if ($chip->photos() !== '')<div class="row"><span class="flex-1">Фото</span><span class="nums text-ink-muted">{{ $chip->photos() }}</span></div>@endif
            @if ($chip->fieldsText())<div class="row items-start"><span class="flex-1">Поля</span><span class="max-w-[65%] text-right text-ink-muted">{{ $chip->fieldsText() }}</span></div>@endif
        </div>
        @if ($chip->action())
            <form method="post" action="/offers/{{ $offer->number }}/media/migtorg" class="mt-4">@csrf<x-ui.button block>{{ $chip->action() }}</x-ui.button></form>
        @endif
    </x-ui.sheet>
</div>
@endif
