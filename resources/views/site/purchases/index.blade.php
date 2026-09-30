<x-ui.shell title="Совместные закупки" :trail="[['Главная', '/'], ['Закупки']]">
    @if (! $cards)
        <x-ui.empty>Пока ни одной закупки нет</x-ui.empty>
    @else
        <div class="list max-w-[56rem]">
            @foreach ($cards as $card)<x-purchase.row :card="$card"/>@endforeach
        </div>
    @endif
</x-ui.shell>
