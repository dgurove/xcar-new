{{-- Документы на вывоз — модератору (Лизе) и админу (17.10.2026): сверху «Отправить» — исполнители без печати, кому
     документы ещё не ушли (кто, телефон, пункт СДЭК), ниже — отправленные за две недели (идут или получены). Строка —
     `x-logistics.doc-row`, её же отдаёт карточка (иначе открытая строка менялась на строку «Вывоза» и столбцы съезжали).
     Нажатие — карточка задачи: там поле трека и «Отправлено». --}}
<x-ui.shell title="Документы" :phone-heading="false" :detail="$detail">
    <div id="documents" class="flex flex-col gap-6">
        @foreach (['Отправить' => $waiting, 'Отправлены' => $sent] as $head => $rows)
            @if ($rows->isNotEmpty())
                <section>
                    <h2 class="list-head">{{ $head }} <span class="nums font-normal text-ink-dim">{{ $rows->count() }}</span></h2>
                    <x-ui.table :id="'docs-'.$loop->index" :titles="$rows->map->title()">
                        <x-slot:head>
                            <tr><th class="grow">Марка, модель</th><th class="hidden sm:table-cell">Вендор, № убытка</th><th class="hidden sm:table-cell">Кому</th><th class="num">{{ $loop->first ? 'Назначен' : 'Отправлены' }}</th></tr>
                        </x-slot:head>
                        @foreach ($rows as $t)<x-logistics.doc-row :t="$t"/>@endforeach
                    </x-ui.table>
                </section>
            @endif
        @endforeach
        @if ($waiting->isEmpty() && $sent->isEmpty())
            <x-ui.empty class="mt-6">Документы отправлять некому</x-ui.empty>
        @endif
    </div>
</x-ui.shell>
