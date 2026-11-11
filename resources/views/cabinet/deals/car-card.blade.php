{{-- «Автомобиль» своей карточкой (11.11.2026, владелец: в карточке шага «От XCar», «У владельца», серая «Автомобиль забрал» и
     текстом «Попросить вывоз…» — «уродство»; решено разделить): где он (`cabinet.deals.handover` строками без подписи
     источника, у нашей парковки — пропуск), кто везёт, и два действия рядом одного вида — «Забрал» и «Вывоз». Только когда
     получение — не само дело шага (`show.blade.php`, `$carCard`). --}}
@php
    /** @var \App\Offers\Handover $handover */
    $showWhere = ($handover->shows() || ($handover->park && $deal->releasable())) && ! $handover->withBuyer;
    $pick = ! $handover->carrier && $handover->canRequest && $handover->canPick;
    $ask = ! $handover->carrier && $handover->canRequest;
@endphp
<section class="box min-w-0">
    <h2>Автомобиль</h2>
    @if ($showWhere)
        <div class="mt-3">@include($handover->park ? 'cabinet.deals.park-pickup' : 'cabinet.deals.handover', ['caption' => false, 'carrier' => $handover->carrier])</div>
    @endif
    @if ($handover->carrier && (! $showWhere || $handover->park))
        <x-ui.info class="mt-3"><x-ui.info-row icon="cat-truck">{{ $handover->carrier }}</x-ui.info-row></x-ui.info>
    @endif
    @if ($pick || $ask)
        <div @class(['mt-3 grid gap-2', 'grid-cols-2' => $pick && $ask])>
            @if ($pick)
                <form method="post" action="/deals/{{ $deal->id }}/picked" data-turbo-confirm="Забрали автомобиль?" data-turbo-confirm-label="Забрал">
                    @csrf<button type="submit" class="btn btn-quiet btn-block"><x-ui.icon name="check" class="size-5"/>Забрал</button>
                </form>
            @endif
            @if ($ask)
                {{-- Что будет и просьба — в шторке (`x-deal.pickup-ask-sheet`). --}}
                <div data-controller="sheet">
                    <button type="button" class="btn btn-quiet btn-block" data-action="sheet#open"><x-ui.icon name="cat-truck" class="size-5"/>Вывоз</button>
                    <x-deal.pickup-ask-sheet :deal="$deal"/>
                </div>
            @endif
        </div>
    @endif
</section>
