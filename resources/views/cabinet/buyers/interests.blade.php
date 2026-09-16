<x-ui.cabinet title="Интерес">
    <x-ui.toolbar :pills="['new' => 'Новые', 'all' => 'Все']" :pill="$preset" pill-param="preset" :counts="$counts" name="interests" action="/lk/interes"/>
    @if ($interests->isEmpty())
        <x-ui.empty href="/lk/pokupateli" link="К покупателям">{{ $preset === 'new' ? 'Новых интересов нет.' : 'Покупатели пока ничего не отмечали.' }}</x-ui.empty>
    @else
        <div class="flex flex-col gap-2">
            @foreach ($interests as $interest)
                @include('cabinet.buyers.interest-row', ['interest' => $interest])
            @endforeach
        </div>
        {{ $interests->links() }}
    @endif
</x-ui.cabinet>
