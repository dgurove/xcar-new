{{-- Мои подтверждения (11.10.2026, владелец: «подтверждают кучу машин, а берём редко — в сделках как будто „жди, ща всё
     будет“»): все живые — «Ждут решения». Отданное другому стоит тут же и тем же видом (15.10.2026, владелец: резерв не
     знает о чужой сделке). Отклонённых и отданных другому, чья продажа состоялась, нет. Вход — строкой в конце «Сделок». --}}
<x-ui.shell title="Мои подтверждения" :back="['Сделки', '/deals']">
<div class="flex flex-col gap-4">
    @if ($waiting->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Подтверждений нет</x-ui.empty>
    @else
        <section>
            <h2 class="list-head">Ждут решения <span class="nums">{{ $waiting->count() }}</span></h2>
            <div class="list">
                @foreach ($waiting as $bid)
                    @include('cabinet.deals.bid-row', ['bid' => $bid])
                @endforeach
            </div>
        </section>
    @endif
</div>
</x-ui.shell>
