{{-- Счета и акты: таблица кита (06.11.2026, владелец — после блоков и меток файлов: «удаляй всё, что нагородил»): месяцы —
     заголовками групп (`ClosingDoc::window`, в месяце сначала где нужен наш ход), итог месяца — справа, над столбцом «Сумма»;
     фильтров нет (владелец: «убери»). Строка — `x-billing.closing-row`, нажатие — страница документа (`row-go`). Документы
     живые, пока не ушли: номер встаёт в момент отправки. --}}
@php use App\Support\Money; use Illuminate\Support\Carbon; use Illuminate\Support\Str; $columns = $table; @endphp
<x-ui.shell title="Счета и акты" :back="['Оплаты', '/money']" cache="no-cache">
    @if ($docs->isEmpty())
        <x-ui.empty class="mt-6">Документов нет</x-ui.empty>
    @else
        <div class="mt-4" data-controller="row-go" data-action="click->row-go#go">
            <x-ui.table :table="$columns" id="closing">
                <x-slot:head><x-table.head :table="$columns"/></x-slot:head>
                @foreach ($docs as $ym => $list)
                    @php $month = Carbon::createFromFormat('!Y-m', $ym); @endphp
                    <x-table.group :table="$columns" class="is-quiet" :key="'m'.$ym" :title="Str::ucfirst($month->translatedFormat($month->isSameYear(now()) ? 'F' : 'F Y')).($month->isSameMonth(now()) ? ', идёт' : '')" :id="'m'.$ym">
                        <span class="ml-auto nums">{{ Money::rub($list->sum(fn ($d) => $d->drift['now'] ?? $d->amount)) }}</span>
                    </x-table.group>
                    @foreach ($list as $doc)<x-billing.closing-row :doc="$doc" :table="$columns"/>@endforeach
                @endforeach
            </x-ui.table>
        </div>
        @if ($months < 24)
            <div class="mt-6 flex justify-center"><a href="/money/closing?months={{ $months + 6 }}" class="btn btn-s btn-quiet" data-turbo-action="replace">Ещё раньше</a></div>
        @endif
    @endif
    <x-mail.window/>
</x-ui.shell>
