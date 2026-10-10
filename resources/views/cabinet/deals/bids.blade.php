{{-- Мои подтверждения (11.10.2026, владелец: «подтверждают кучу машин, а берём редко — в сделках как будто „жди, ща всё
     будет“»): ждут решения — по предложению ещё никого не выбрали; в резерве — отдали другому, его сделка идёт. Отклонённых
     и отданных другому, чья продажа состоялась, нет. Вход — строкой в конце «Сделок». --}}
<x-ui.shell title="Мои подтверждения" :back="['Сделки', '/deals']">
<div class="flex flex-col gap-4">
    @if ($waiting->isEmpty() && $reserve->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Подтверждений нет</x-ui.empty>
    @endif
    @foreach (['Ждут решения' => [$waiting, false], 'В резерве' => [$reserve, true]] as $heading => [$list, $isReserve])
        @continue($list->isEmpty())
        <section>
            <h2 class="list-head">{{ $heading }} <span class="nums">{{ $list->count() }}</span></h2>
            <div class="list">
                @foreach ($list as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid, 'reserve' => $isReserve])
                @endforeach
            </div>
        </section>
    @endforeach
</div>
</x-ui.shell>
