{{-- Документ «Счетов и актов» на всю страницу (05.11.2026, владелец: «в окошке сжато, а в фуллскрине всё то же, но ещё и все
     тачки»; первая раскладка — «позорище»): шапка документа одной плашкой (`closing-head`), под ней все ТС обычной таблицей
     (`x-billing.registry-car-row`): ширины ставит браузер по содержимому — номера, суммы и даты не режутся; не влезла
     (телефон) — листается вбок, название закреплено. Последняя строка — итог. Открыт акт (шторка справа) — таблица уже. --}}
@php use App\Support\Money; $d = $doc; @endphp
<x-ui.shell :title="$d->name.', '.$d->what.' за '.mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'))" :back="['Счета и акты', '/money/closing?peek='.$d->key]" cache="no-cache">
    <x-slot:lead>@if ($d->vendor)<x-vendor.logo :vendor="$d->vendor"/>@endif</x-slot:lead>
    @include('park.money.closing-head')
    @if ($cars->isNotEmpty())
        <div class="doc-cars-wrap">
            <table class="doc-cars">
                <thead>
                    <tr>
                        <th class="doc-n">№</th>
                        <th class="doc-car">Марка, модель</th>
                        <th class="doc-act">Акт</th>
                        <th>№ убытка</th>
                        <th>Принята</th>
                        <th>Выдана</th>
                        <th class="num">Сут</th>
                        <th class="num">Ставка</th>
                        <th class="num">Сумма</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cars as $n => $car)<x-billing.registry-car-row :car="$car" :n="$n + 1" :acts="$acts[$car['vehicle']->id] ?? null"/>@endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td class="doc-n"></td>
                        <td class="doc-car">Итого {{ $cars->count() }}&nbsp;ТС</td>
                        <td colspan="4"></td>
                        <td class="num nums">{{ Money::nums($cars->sum('days')) }}</td>
                        <td></td>
                        <td class="num nums">{{ Money::nums($cars->sum('amount')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
    <x-mail.window/>
</x-ui.shell>
