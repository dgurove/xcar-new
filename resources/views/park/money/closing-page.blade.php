{{-- Документ «Счетов и актов» на всю страницу (05.11.2026, владелец: «в окошке сжато, а в фуллскрине всё то же самое, но ещё и
     все тачки»): слева — тело карточки (`closing-body`), справа — все ТС документа таблицей для сверки дат по актам
     (`x-billing.registry-car-row`); на телефоне — карточка, под ней таблица. Открыт акт (шторка документов панелью справа) —
     левая колонка уходит, таблица встаёт во всю ширину рядом с актом. «···» — в строке заголовка. --}}
@php $d = $doc; $columns = $table; @endphp
<x-ui.shell :title="$d->name.', '.$d->what.' за '.mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'))" :back="['Счета и акты', '/money/closing?peek='.$d->key]" cache="no-cache">
    <x-slot:actions>@include('park.money.closing-menu', ['button' => 'btn btn-quiet btn-round btn-s'])</x-slot:actions>
    <div class="doc-page">
        <div class="box doc-page-side">@include('park.money.closing-body', ['page' => true])</div>
        @if ($cars->isNotEmpty())
            <div class="min-w-0">
                <h2 class="list-head">Все ТС <span class="nums text-ink-muted">{{ $cars->count() }}</span></h2>
                <x-ui.table :table="$columns" id="doc-cars">
                    <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
                    @foreach ($cars as $car)<x-billing.registry-car-row :car="$car" :table="$columns" :acts="$acts[$car['vehicle']->id] ?? null"/>@endforeach
                </x-ui.table>
            </div>
        @endif
    </div>
    <x-mail.window/>
</x-ui.shell>
