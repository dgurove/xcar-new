<x-ui.cabinet title="Интерес">
<div class="flex max-w-[56rem] flex-col gap-6">
    <x-ui.toolbar :sort="$sort" :pills="['new' => 'Новые', 'all' => 'Все']" :pill="$preset" pill-param="preset" :counts="$counts" name="interests" action="/buyers/interest"/>
    @if ($interests->isEmpty())
        <x-ui.empty href="/buyers" link="К покупателям">{{ $preset === 'new' ? 'Новых интересов нет' : 'Покупатели пока ничего не отмечали' }}</x-ui.empty>
    @else
        <div class="list">
            @foreach ($interests as $interest)
                @include('cabinet.buyers.interest-row', ['interest' => $interest])
            @endforeach
        </div>
        <x-ui.pager :of="$interests" :sizes="[]"/>
    @endif
</div>
</x-ui.cabinet>
