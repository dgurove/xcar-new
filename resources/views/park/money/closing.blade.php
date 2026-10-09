{{-- Закрытие месяца: пилюли месяцев, строки по контрагентам — ТС, период, сутки, сумма, периодичность, почему сейчас;
     галочки, кнопка считает выбранное: «Выставить 3 счёта на 2 900 ₽». Вендору и страхователю — один счёт-реестр на
     группу (строки группы — один `data-group`), покупателю — счёт на ТС. Ниже — что уже выставлено. --}}
@php use App\Support\Money; use App\Billing\Cadence; $me = auth()->user(); $n = $items->flatten(1)->count(); @endphp
<x-ui.shell :title="'Закрытие '.\Illuminate\Support\Str::after($month->isoFormat('D MMMM YYYY'), ' ')" :back="['Оплаты', '/money']" cache="no-cache">
    <x-ui.pills class="mb-4">
        @foreach ($months as $m)
            <x-ui.pill :href="'/money/closing?month='.$m->format('Y-m')" :current="$m->format('Y-m') === $month->format('Y-m')">{{ $m->translatedFormat('F') }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    @if ($errors->has('closing'))<p class="field-error mb-4">{{ $errors->first('closing') }}</p>@endif
    @if ($n === 0)
        <x-ui.empty class="mt-6">Выставлять нечего</x-ui.empty>
    @else
        <form method="post" action="/money/closing" class="flex flex-col gap-2" data-controller="sum" data-sum-verb-value="Выставить" data-sum-words-value="счёт|счета|счетов">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
            @foreach ($items as $group => $rows)
                @php $party = $rows->first()['party']; $partyName = $party?->name ?? 'Без плательщика'; $fill = $party && ! $rows->first()['ready'] && $me->canManagePark() && ($party->exists || $rows->first()['payer'] === 'vendor'); @endphp
                <section>
                    {{-- Плательщик один на всю группу: и «некому выставить», и «нет реквизитов» — про него, в заголовке.
                         Реквизиты заполняются тут же шторкой: записанный контрагент — правкой, у вендора без контрагента — новый
                         от его данных. Покупателю без записанного контрагента — нет: его заводит выдача. --}}
                    <h2 class="list-head flex-wrap">{{ $partyName }} <span class="nums">{{ Money::rub($rows->sum('amount')) }}</span>
                        @if (! $party)<x-ui.state tone="danger">некому выставить</x-ui.state>
                        @elseif ($fill)
                            <button type="button" class="pill pill-urgent !min-h-0 !py-1 text-xs" data-controller="emit" data-action="emit#send" data-emit-event-param="party-{{ $loop->index }}:open">Заполнить реквизиты</button>
                        @elseif (! $rows->first()['ready'])<x-ui.state tone="danger">нет реквизитов</x-ui.state>@endif
                    </h2>
                    <div class="list">
                        @foreach ($rows as $r)
                            @php $v = $r['vehicle']; $can = $r['ready'] && $me->canManagePark(); @endphp
                            <label class="row row-check {{ $can ? '' : 'opacity-60' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate"><a href="/cars/{{ $v->id }}" class="hover:underline">{{ $v->titleWithYear() }}</a></span>
                                    <span class="row-sub">
                                        {{-- Год — у даты не этого года: «26 дек 2023 – 30 сен» вместо «26 дек – 30 сен», что читалось тремя месяцами. --}}
                                        <span class="nums">{{ $r['from']->translatedFormat($r['from']->isCurrentYear() ? 'j M' : 'j M Y') }} – {{ $r['to']->translatedFormat($r['to']->isCurrentYear() ? 'j M' : 'j M Y') }}, {{ $r['days'] }} сут</span>
                                        <span>{{ $r['reason'] }}</span>
                                        @if ($r['cadence'] === Cadence::Release)<span>по выбытию</span>@endif
                                        @if ($r['payer'] === 'buyer')<span class="text-ink">покупатель</span>@elseif ($r['payer'] === 'owner')<span class="text-ink">страхователь</span>@endif
                                        @if ($r['charges'])<span>+ начисления</span>@endif
                                        @if ($v->ref)<span>{{ $v->ref }}</span>@endif
                                    </span>
                                </span>
                                <span class="nums shrink-0">{{ Money::rub($r['amount']) }}</span>
                                <span class="check"><input type="checkbox" name="items[]" value="{{ $r['key'] }}" data-sum-target="box" data-group="{{ $group }}" data-amount="{{ $r['amount'] }}" data-action="sum#update" @checked($can) @disabled(!$can)></span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach
            @if ($me->canManagePark())
                <x-ui.action-bar><x-ui.button class="min-w-0 flex-1" data-turbo-confirm="Выставить выбранные счета?"><span data-sum-target="label">Выставить</span></x-ui.button></x-ui.action-bar>
            @endif
        </form>
    @endif
    {{-- Шторки реквизитов — вне формы выставления: формы в форме браузер не допускает. --}}
    @foreach ($items as $rows)
        @php $party = $rows->first()['party']; $vendorRow = $rows->first()['payer'] === 'vendor'; @endphp
        @if ($party && ! $rows->first()['ready'] && $me->canManagePark() && ($party->exists || $vendorRow))
            <div data-controller="sheet" data-action="party-{{ $loop->index }}:open@window->sheet#open" class="contents">
                <x-ui.sheet id="party-{{ $loop->index }}" :title="$party->name" wide tall>@include('park.money.party-form', ['party' => $party, 'vendor' => $vendorRow ? $rows->first()['vehicle']->vendor : null])</x-ui.sheet>
            </div>
        @endif
    @endforeach
    @if ($issued->isNotEmpty())
        <section class="mt-10">
            <h2 class="list-head">Выставлено за {{ mb_strtolower($month->translatedFormat('F')) }} <span class="nums">{{ Money::rub($issued->sum('total')) }}</span></h2>
            <div class="list">
                @foreach ($issued as $i)<x-billing.row :invoice="$i"/>@endforeach
            </div>
        </section>
    @endif
</x-ui.shell>
