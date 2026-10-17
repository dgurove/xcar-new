{{-- Документы на вывоз — модератору (Лизе) и админу (17.10.2026): сверху «Отправить» — исполнители без печати, кому
     документы ещё не ушли (кто, телефон, пункт СДЭК), ниже — отправленные за две недели (идут или получены). Нажатие —
     карточка задачи: там поле трека и «Отправлено». --}}
<x-ui.shell title="Документы" :phone-heading="false" :detail="$detail">
    <div id="documents" class="flex flex-col gap-6">
        @foreach (['Отправить' => $waiting, 'Отправлены' => $sent] as $head => $rows)
            @if ($rows->isNotEmpty())
                <section>
                    <h2 class="list-head">{{ $head }} <span class="nums font-normal text-ink-dim">{{ $rows->count() }}</span></h2>
                    <x-ui.table :id="'docs-'.$loop->index" :titles="$rows->map->title()">
                        <x-slot:head>
                            <tr><th class="grow">Марка, модель</th><th class="hidden sm:table-cell">Кому</th><th class="num">{{ $loop->first ? 'Назначен' : 'Отправлены' }}</th></tr>
                        </x-slot:head>
                        @foreach ($rows as $t)
                            @php $u = $t->executorUser; @endphp
                            <tr data-detail-key="{{ $t->id }}">
                                <td class="grow">
                                    <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span></span></x-ui.row-link>
                                    <span class="cell-sub"><span>
                                        @if ($t->docs_received_at)<span class="text-ink-muted">получены</span>@elseif ($t->docs_track)<span class="text-accent-text">в пути</span>@else<span class="text-urgent">{{ $u?->cdek_point ? 'СДЭК: '.$u->cdek_point : 'пункта СДЭК нет' }}</span>@endif<span class="sm:hidden">, {{ $u?->shortName() }}</span>
                                    </span></span>
                                </td>
                                <td class="hidden sm:table-cell cell-dim">{{ $u?->name }}{{ $u?->logistics_city ? ', '.$u->logistics_city : '' }}</td>
                                <td class="num nums cell-dim">{{ ($loop->parent->first ? $t->assigned_at : $t->docs_sent_at)?->translatedFormat('j M') }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </section>
            @endif
        @endforeach
        @if ($waiting->isEmpty() && $sent->isEmpty())
            <x-ui.empty class="mt-6">Документы отправлять некому</x-ui.empty>
        @endif
    </div>
</x-ui.shell>
