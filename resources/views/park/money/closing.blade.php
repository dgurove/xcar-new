{{-- Счета и акты за месяц (05.11.2026, владелец: «не должно быть понятия „Выставить“ — актуальные всегда, зафиксировать,
     если отправили»): строка на плательщика. Не отправлен — живой документ (`Closing::items` группой, сумма по текущим
     данным), нажатие — его карточка с «Отправить»; отправлен — счёт с номером; данные изменились после отправки —
     «было → сейчас» красным. Машины месяца, появившиеся после отправки, — новой живой строкой «дополнительно». --}}
@php use App\Support\Money; $me = auth()->user(); $current = $month->isSameMonth(now()); @endphp
<x-ui.shell :title="'Счета и акты за '.mb_strtolower($month->translatedFormat('F'))" :back="['Оплаты', '/money']" cache="no-cache">
    <x-ui.pills class="mb-4">
        @foreach ($months as $m)
            <x-ui.pill :href="'/money/closing?month='.$m->format('Y-m')" :current="$m->format('Y-m') === $month->format('Y-m')">{{ $m->translatedFormat('F') }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    @if ($items->isEmpty() && $sent->isEmpty())
        <x-ui.empty class="mt-6">За {{ mb_strtolower($month->translatedFormat('F')) }} документов нет</x-ui.empty>
    @endif
    @if ($items->isNotEmpty())
        <section>
            <h2 class="list-head">Не отправлены @if ($current)<span class="text-ink-muted">месяц идёт, по сегодня</span>@endif</h2>
            <div class="list">
                @foreach ($items as $group => $rows)
                    @php
                        $first = $rows->first(); $party = $first['party'];
                        $fill = $party && ! $first['ready'] && $me->canManagePark() && ($party->exists || $first['payer'] === 'vendor');
                    @endphp
                    <a href="/money/draft?{{ http_build_query(['month' => $month->format('Y-m'), 'group' => $group]) }}" class="row">
                        <div class="min-w-0 flex-1">
                            {{-- Плательщик-вендор — именем вендора: у Альфы Москвы и СПб одно юрлицо, строки иначе не различить. --}}
                            <div class="truncate">{{ $first['payer'] === 'vendor' ? $first['vehicle']->vendor?->name : ($party?->name ?? 'Без плательщика') }}</div>
                            <div class="row-sub">
                                @if ($first['tow'])<span class="text-ink">эвакуация</span>@elseif ($first['payer'] === 'buyer')<span class="text-ink">покупатель, {{ $first['vehicle']->titleWithYear() }}</span>@elseif ($first['payer'] === 'owner')<span class="text-ink">страхователь</span>@endif
                                <span class="nums whitespace-nowrap">{{ $rows->pluck('vehicle.id')->unique()->count() }}&nbsp;ТС</span>
                                @if (in_array($group, $sentGroups, true))<span>дополнительно</span>@endif
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="nums">{{ Money::rub($rows->sum('amount')) }}</div>
                            <div class="text-sm {{ $first['ready'] ? 'text-urgent' : 'text-danger' }}">{{ ! $party ? 'некому' : ($first['ready'] ? 'не отправлен' : 'нет реквизитов') }}</div>
                        </div>
                    </a>
                    @if ($fill)
                        {{-- Реквизиты — тут же шторкой: записанный контрагент — правкой, у вендора без контрагента — новый от его данных. --}}
                        <div class="row !py-2" data-controller="sheet">
                            <button type="button" class="pill pill-urgent !min-h-0 !py-1 text-xs" data-action="sheet#open">Заполнить реквизиты {{ $party->name }}</button>
                            <x-ui.sheet id="party-{{ $loop->index }}" :title="$party->name" wide tall>@include('park.money.party-form', ['party' => $party, 'vendor' => $first['payer'] === 'vendor' ? $first['vehicle']->vendor : null])</x-ui.sheet>
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endif
    @if ($sent->isNotEmpty())
        <section class="mt-8">
            <h2 class="list-head">Отправлены <span class="nums">{{ Money::rub($sent->sum('total')) }}</span></h2>
            <div class="list">
                @foreach ($sent as $i)
                    @if ($d = $drift[$i->id] ?? null)
                        <a href="/money/invoices/{{ $i->id }}" class="row">
                            <div class="min-w-0 flex-1">
                                <div class="truncate"><x-vendor.name :party="$i->party"/></div>
                                <div class="row-sub"><span class="nums">{{ $i->label() }}</span><span class="text-danger">изменилось после отправки</span></div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="nums">{{ Money::rub($d['now']) }}</div>
                                <div class="nums text-sm text-ink-muted line-through">{{ Money::rub($d['was']) }}</div>
                            </div>
                        </a>
                    @else
                        <x-billing.row :invoice="$i"/>
                    @endif
                @endforeach
            </div>
        </section>
    @endif
</x-ui.shell>
