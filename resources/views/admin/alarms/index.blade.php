{{-- Экран «Горит» (19.10.2026, начальник: «зашёл — сразу видишь, что горит, в чём дело, провалился — потушил»;
     `AlarmController`): два блока — «Сгорели» (срок обязывающего прошёл, свежее сверху) и «Вот-вот сгорят» (ближнее сверху),
     в каждом группы по причине (`Binding::REASONS`) липкими подзаголовками; строка — этап и срок (`x-alarm.row`). Чип
     «Менеджер» — менеджер идущей сделки, «Без менеджера» — машины без покупателя. Пустой блок не рисуется. --}}
<x-ui.shell title="Горит" :count="$total" :detail="$detail">
    <x-ui.toolbar :facets="$facets" name="alarms" search="Номер, марка, VIN, убыток"/>
    <div class="mt-6 flex flex-col gap-8" id="list">
        @if (! $total)
            <x-ui.empty>Ничего не горит</x-ui.empty>
        @endif
        @php $columns = \App\Http\Admin\AlarmController::table(); @endphp
        @foreach (['over' => ['Сгорели', 'text-danger', $over], 'burning' => ['Вот-вот сгорят', 'text-urgent', $burning]] as $key => [$title, $tone, $groups])
            @continue($groups->isEmpty())
            <section data-search-group>
                <h2 class="detail-section {{ $tone }}">{{ $title }} <span class="nums font-normal text-ink-dim">{{ $groups->flatten()->count() }}</span></h2>
                <x-ui.table :table="$columns" :id="'alarms-'.$key" :titles="$groups->flatten()->map->titleWithYear()" :rest="$columns->rest()">
                    <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
                    @foreach ($groups as $reason => $rows)
                        <x-table.group :table="$columns" :key="$key.'-'.$reason" :title="\App\Offers\Binding::REASONS[$reason]" :count="$rows->count()"/>
                        @foreach ($rows as $offer)<x-alarm.row :offer="$offer" :table="$columns" :href="$crm ? null : $href($offer)"/>@endforeach
                    @endforeach
                </x-ui.table>
            </section>
        @endforeach
    </div>
    <x-mail.window/>
</x-ui.shell>
