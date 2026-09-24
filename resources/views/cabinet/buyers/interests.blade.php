<x-ui.cabinet title="Интерес">
    <x-ui.toolbar :pills="['new' => 'Новые', 'all' => 'Все']" :pill="$preset" pill-param="preset" :counts="$counts" name="interests" action="/account/interest"/>
    @if ($interests->isEmpty())
        <x-ui.empty href="/account/buyers" link="К покупателям">{{ $preset === 'new' ? 'Новых интересов нет' : 'Покупатели пока ничего не отмечали' }}</x-ui.empty>
    @else
        <div class="list">
            @foreach ($interests as $interest)
                @include('cabinet.buyers.interest-row', ['interest' => $interest])
            @endforeach
        </div>
        <x-ui.pager :of="$interests" :sizes="[]"/>
    @endif
</x-ui.cabinet>
