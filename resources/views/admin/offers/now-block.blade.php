{{-- Что сейчас с предложением и что с этим сделать — плашкой сверху окошка, тем же видом, что «Цены» черновика и «Приём»
     (19.10.2026, владелец: «непонятно, что от тебя хотят в этом окне», «к унификации»): кнопками, а не пунктами «Действий».
     Слот («Публикация») — когда выйдет, «Опубликовать сейчас», «Перенести» (шторка слотов), «Убрать из слота»; на аукционе —
     «Вернуть в продажу» (шторка `x-offer.resale-sheet`) и «В черновики»; архив — «Вернуть в черновики» и красным «Удалить
     навсегда». Этих пунктов в «Действиях» тогда нет. --}}
@php
    use App\Offers\{OfferState, Slots};
    $kind = match (true) {
        $offer->isScheduled() => 'slot',
        $offer->onAuction() => 'auction',
        default => 'archive',
    };
@endphp
<section class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3">
    @if ($kind === 'slot')
        <h2 class="text-lg font-medium">Выйдет {{ Slots::phrase($offer->slot_at) }}</h2>
        <div class="flex flex-wrap gap-2" data-controller="sheet">
            <form method="post" action="/offers/{{ $n }}/state" class="flex basis-full">
                @csrf<input type="hidden" name="state" value="open"><input type="hidden" name="when" value="{{ Slots::NOW }}">
                <button class="btn btn-s btn-quiet flex-1">Опубликовать сейчас</button>
            </form>
            <button type="button" class="btn btn-s btn-quiet min-w-0 flex-1" data-action="sheet#open">Перенести</button>
            <form method="post" action="/offers/{{ $n }}/unschedule" class="flex min-w-0 flex-1">@csrf<button class="btn btn-s btn-quiet min-w-0 flex-1">Убрать из слота</button></form>
            <x-offer.publish-sheet :offer="$offer" :id="'slot-move-'.$n" :action="'/offers/'.$n.'/state'" state="open" title="Перенести" move/>
        </div>
    @elseif ($kind === 'auction')
        <h2 class="text-lg font-medium">На аукционе{{ $offer->auction_at ? ' с '.$offer->auction_at->translatedFormat('j M') : '' }}</h2>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-s btn-accent min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="resale:open">Вернуть в продажу</button>
            <form method="post" action="/offers/{{ $n }}/auction" class="flex">@csrf @method('delete')<button class="btn btn-s btn-quiet">В черновики</button></form>
        </div>
    @else
        <h2 class="text-lg font-medium">{{ $offer->state === OfferState::Cancelled ? 'Снято' : 'В архиве' }}</h2>
        <div class="flex flex-wrap gap-2">
            @foreach ($transitions as [$next, $label])
                <form method="post" action="/offers/{{ $n }}/state" class="flex min-w-0 flex-1">
                    @csrf<input type="hidden" name="state" value="{{ $next->value }}">
                    <button class="btn btn-s btn-quiet min-w-0 flex-1">{{ $label }}</button>
                </form>
            @endforeach
            @if ($purge)
                <form method="post" action="/offers/{{ $n }}/purge" class="flex" data-turbo-frame="_top" data-turbo-confirm="Удалить навсегда? Фото, документы, сделки, чаты и счета по нему удалятся без возврата" data-turbo-confirm-label="Удалить">
                    @csrf<button class="btn btn-s btn-danger">Удалить навсегда</button>
                </form>
            @endif
        </div>
    @endif
</section>
