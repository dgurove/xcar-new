<x-ui.shell title="Совместные закупки" :trail="[['Главная', '/'], ['Закупки']]">
    @if ($purchases->isEmpty())
        <x-ui.empty>Пока ни одной закупки нет.</x-ui.empty>
    @else
        <div class="flex flex-col gap-3">
            @foreach ($purchases as $p)<x-purchase.row :purchase="$p" :rated="$mine[$p->id] ?? 0"/>@endforeach
        </div>
    @endif
</x-ui.shell>
