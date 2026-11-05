{{-- Счета и акты (05.11.2026, владелец: «не разделять по месяцам, а группировать», «логотипов вендоров не хватает»; 06.11.2026 —
     «табличный вид не подходит, красивые блоки со строками и подписи месяцев вне блоков»): как выписка банка — месяц подписью
     над своим блоком `.list` (слева название, у текущего «идёт», справа итог), в блоке строки `x-billing.closing-row`, в месяце
     сначала где нужен наш ход (`ClosingDoc::window`). Пилюли — шаг документа (`?state=`). Месяцы одной колонкой (две — владелец:
     «точно не подходят»); на ПК в строке — файлы счёта и акта тихими метками, на телефоне — только на странице. Документы живые, пока не ушли: номер встаёт в момент отправки. --}}
@php use App\Support\Money; use Illuminate\Support\Carbon; use Illuminate\Support\Str; @endphp
<x-ui.shell title="Счета и акты" :back="['Оплаты', '/money']" cache="no-cache">
    @if ($counts)
        <x-ui.toolbar :pills="['' => 'Все', 'send' => 'Отправить', 'wait' => 'Ждём оплату', 'paid' => 'Оплачены']" :pill="$state" pill-param="state" :counts="$counts" name="closing"/>
    @endif
    @if ($docs->isEmpty())
        <x-ui.empty class="mt-6">Документов нет</x-ui.empty>
    @else
        <div class="doc-months">
            @foreach ($docs as $ym => $list)
                @php $month = Carbon::createFromFormat('!Y-m', $ym); @endphp
                <section id="m{{ $ym }}" class="min-w-0">
                    <h2 class="list-head doc-month-head">
                        <span>{{ Str::ucfirst($month->translatedFormat($month->isSameYear(now()) ? 'F' : 'F Y')) }}</span>
                        @if ($month->isSameMonth(now()))<span class="doc-month-now">идёт</span>@endif
                        <span class="nums ml-auto">{{ Money::rub($list->sum(fn ($d) => $d->drift['now'] ?? $d->amount)) }}</span>
                    </h2>
                    <div class="list">
                        @foreach ($list as $doc)<x-billing.closing-row :doc="$doc"/>@endforeach
                    </div>
                </section>
            @endforeach
        </div>
        @if ($months < 24)
            <div class="mt-6 flex justify-center"><a href="/money/closing?{{ http_build_query(array_filter(['state' => $state, 'months' => $months + 6])) }}" class="btn btn-s btn-quiet" data-turbo-action="replace">Ещё раньше</a></div>
        @endif
    @endif
    {{-- Окно писем — «Отправить» со страницы документа: номер встаёт при отправке. --}}
    <x-mail.window/>
</x-ui.shell>
