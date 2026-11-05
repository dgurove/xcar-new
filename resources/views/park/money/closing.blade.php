{{-- Счета и акты (05.11.2026, владелец: «не разделять по месяцам, а группировать», «логотипов вендоров не хватает»; файлы —
     в карточке, в строке они «бесполезные»): одна таблица документов, заголовками — месяцы («Ноябрь, идёт» — по сегодня), в месяце
     сначала где нужен наш ход (`ClosingDoc::window`). Строка — `x-billing.closing-row`, нажатие — страница документа
     (`closing-page`; окошка рядом нет). Документы живые, пока не ушли: номер встаёт в момент отправки. --}}
@php use App\Support\Money; $columns = $table; @endphp
<x-ui.shell title="Счета и акты" :back="['Оплаты', '/money']" cache="no-cache">
    @if ($docs->isEmpty())
        <x-ui.empty class="mt-6">Документов нет</x-ui.empty>
    @else
        <x-ui.table :table="$columns" id="closing">
            <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
            @foreach ($docs as $ym => $list)
                @php $month = \Illuminate\Support\Carbon::createFromFormat('!Y-m', $ym); $now = $month->isSameMonth(now()); @endphp
                <x-table.group :table="$columns" :key="'m'.$ym" :title="\Illuminate\Support\Str::ucfirst($month->translatedFormat($month->isSameYear(now()) ? 'F' : 'F Y'))" :id="'m'.$ym">
                    <span class="table-group-aside">@if ($now)<span>идёт, по сегодня</span>@endif<span class="nums">{{ Money::rub($list->sum(fn ($d) => $d->drift['now'] ?? $d->amount)) }}</span></span>
                </x-table.group>
                @foreach ($list as $doc)<x-billing.closing-row :doc="$doc" :table="$columns"/>@endforeach
            @endforeach
        </x-ui.table>
        @if ($months < 24)
            <div class="mt-6 flex justify-center"><a href="/money/closing?months={{ $months + 6 }}" class="btn btn-s btn-quiet" data-turbo-action="replace">Ещё раньше</a></div>
        @endif
    @endif
    {{-- Окно писем — «Отправить» из карточки: номер встаёт при отправке. --}}
    <x-mail.window/>
</x-ui.shell>
