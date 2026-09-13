<x-ui.shell title="Совместные закупки" :trail="[['Главная', '/'], ['Закупки']]">
    @if (! $cards)
        <x-ui.empty>Пока ни одной закупки нет.</x-ui.empty>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($cards as $card)<x-purchase.row :card="$card"/>@endforeach
        </div>
    @endif
</x-ui.shell>
