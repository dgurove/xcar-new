{{-- Архив сделок (11.10.2026, владелец: «не состоявшиеся в общем списке не вижу смысла»): закончившиеся и отменённые
     сделки. Подтверждений тут нет — они в «Моих подтверждениях», отклонённые не показываются нигде. Вход — строкой
     «Архив N» в конце «Сделок». --}}
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
            <h2 class="list-head">Отменены <span class="nums">{{ $failed->count() }}</span></h2>
            <div class="list">
                @foreach ($failed as $deal)
                    @include('cabinet.deals.row', ['deal' => $deal])
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-ui.shell>
