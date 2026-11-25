{{-- «Вывоз» — вкладки колеса «Предложений» (24.11.2026, `PickupController::tab`): вывозы продажи строками логистики
     (`x-logistics.row`), нажатие — окно машины с дорожкой «Вывоз». «Везём» — группами по шагу. --}}
@php
    $empty = [
        'pickup_contacts' => 'Все контакты показаны',
        'pickup_assign' => 'Всем назначены исполнители',
        'pickup_docs' => 'Документы на вывоз отправлены',
        'pickup_letters' => 'Поставщику всё сообщено',
        'pickup_moving' => 'Сейчас никого не везём',
    ][$tab];
@endphp
<x-ui.shell :title="$presets[$tab] ?? 'Вывоз'" :heading="false" :detail="$detail">
    <x-admin.tab-head :presets="$presets" :preset="$tab" :counts="$counts" :sections="$sections" :name="$tab" search="Номер, марка, VIN, убыток"/>

    <div id="list">
    @if ($total === 0)
        <x-ui.empty class="mt-6">{{ $searching ? 'Ничего не нашлось' : $empty }}</x-ui.empty>
    @else
        <x-ui.table :id="$tab.'-table'" class="mt-4" :table="$table">
            <x-slot:head><x-table.head :table="$table"/></x-slot:head>
            @foreach ($groups as $title => $rows)
                @if ($title !== '')<x-table.group :table="$table" :key="'g'.$loop->index" :title="$title" :count="$rows->count()"/>@endif
                @foreach ($rows as $t)<x-logistics.row :t="$t" :table="$table" :preset="$tab" :peek="$t->offer?->number"/>@endforeach
            @endforeach
        </x-ui.table>
    @endif
    </div>
    <x-mail.window/>
</x-ui.shell>
