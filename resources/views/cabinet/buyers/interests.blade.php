<x-ui.cabinet title="Интерес" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Интерес']]">
    <x-ui.toolbar :pills="['new' => 'Новые', 'all' => 'Все']" :pill="$preset" pill-param="preset" :counts="$counts" name="interests" action="/lk/interes"/>
    @if ($interests->isEmpty())
        <x-ui.empty class="mt-6" href="/lk/pokupateli" link="К покупателям">{{ $preset === 'new' ? 'Новых интересов нет.' : 'Покупатели пока ничего не отмечали.' }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($interests as $interest)
                @include('cabinet.buyers.interest-row', ['interest' => $interest])
            @endforeach
        </div>
        <div class="mt-8">{{ $interests->links() }}</div>
    @endif
</x-ui.cabinet>
