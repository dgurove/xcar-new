{{-- Архив сделок (11.10.2026, владелец: «не состоявшиеся в общем списке не вижу смысла»): закончившиеся сделки и не
     состоявшиеся — отменённые сделки и подтверждения, которые не выбрали или отозвали, одной лентой по дате. Вход —
     строкой «Архив N» в конце «Сделок». --}}
<x-ui.shell title="Архив" :back="['Сделки', '/deals']">
<div class="flex flex-col gap-4">
    @if ($done->isEmpty() && $failed->isEmpty())
        <x-ui.empty>Пока пусто</x-ui.empty>
    @endif
    @if ($done->isNotEmpty())
        <section>
            <h2 class="list-head">Закончены <span class="nums">{{ $done->count() }}</span></h2>
            <div class="list">
                @foreach ($done as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
    @endif
    @if ($failed->isNotEmpty())
        <section>
            <h2 class="list-head">Не состоялись <span class="nums">{{ $failed->count() }}</span></h2>
            <div class="list">
                @foreach ($failed as $item)
                    @if ($item instanceof \App\Offers\Deal)
                        @include('cabinet.deals.row', ['deal' => $item])
                    @else
                        @include('cabinet.deals.bid-row', ['bid' => $item, 'state' => true])
                    @endif
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-ui.shell>
