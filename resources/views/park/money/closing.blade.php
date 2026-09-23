{{-- Закрытие месяца: пилюли месяцев, строки по контрагентам — ТС, период, сутки, сумма, периодичность, почему сейчас;
     галочки, кнопка считает выбранное: «Выставить 3 счета на 2 900 ₽» (счета с актами). Ниже — что уже выставлено за месяц.
     Письма — вручную с каждого счёта. --}}
@php use App\Support\Money; use App\Billing\Cadence; $me = auth()->user(); $n = $items->flatten(1)->count(); @endphp
<x-ui.shell :title="'Закрытие '.mb_strtolower($month->translatedFormat('F Y'))" :back="['Деньги', '/money']" cache="no-cache">
    <x-ui.pills class="mb-4">
        @foreach ($months as $m)
            <x-ui.pill :href="'/money/closing?month='.$m->format('Y-m')" :current="$m->format('Y-m') === $month->format('Y-m')">{{ $m->translatedFormat('F') }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    @if ($errors->has('closing'))<p class="field-error mb-4">{{ $errors->first('closing') }}</p>@endif
    @if ($n === 0)
        <x-ui.empty class="mt-6">Выставлять нечего</x-ui.empty>
    @else
        <form method="post" action="/money/closing" class="flex flex-col gap-6" data-controller="sum" data-sum-verb-value="Выставить" data-sum-words-value="счёт|счета|счетов">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
            @foreach ($items as $partyName => $rows)
                @php $party = $rows->first()['party']; @endphp
                <section>
                    {{-- Плательщик один на всю группу: и «некому выставить», и «нет реквизитов» — про него, в заголовке. --}}
                    <h2 class="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1.5 text-lg">{{ $partyName }} <span class="nums text-base text-ink-muted">{{ Money::rub($rows->sum('amount')) }}</span>
                        @if (! $party)<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">некому выставить</x-ui.pill>
                        @elseif (! $rows->first()['ready'])<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">нет реквизитов</x-ui.pill>@endif
                    </h2>
                    <div class="flex flex-col gap-2">
                        @foreach ($rows as $r)
                            @php $v = $r['vehicle']; $can = $r['ready'] && $me->canManagePark(); @endphp
                            <label class="row row-check {{ $can ? '' : 'opacity-60' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium"><a href="/cars/{{ $v->id }}" class="hover:underline">{{ $v->titleWithYear() }}</a>@if ($v->ref) <span class="nums text-ink-muted">{{ $v->ref }}</span>@endif</span>
                                    <span class="row-sub mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="tag nums">{{ $r['from']->translatedFormat('j M') }} – {{ $r['to']->translatedFormat('j M') }}, {{ $r['days'] }} сут</span>
                                        <span class="tag">{{ $r['reason'] }}</span>
                                        @if ($r['cadence'] === Cadence::Release)<span class="tag">после выдачи</span>@endif
                                        @if ($r['payer'] === 'buyer')<span class="tag">покупатель</span>@elseif ($r['payer'] === 'owner')<span class="tag">страхователь</span>@endif
                                        @if ($r['charges'])<span class="tag">+ начисления</span>@endif
                                    </span>
                                </span>
                                <span class="nums font-medium">{{ Money::rub($r['amount']) }}</span>
                                <span class="check"><input type="checkbox" name="items[]" value="{{ $r['key'] }}" data-sum-target="box" data-amount="{{ $r['amount'] }}" data-action="sum#update" @checked($can) @disabled(!$can)></span>
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
    @if ($issued->isNotEmpty())
        <section class="mt-10">
            <h2 class="mb-2 text-lg">Выставлено за {{ mb_strtolower($month->translatedFormat('F')) }} <span class="nums text-ink-muted">{{ Money::rub($issued->sum('total')) }}</span></h2>
            <div class="flex flex-col gap-2">
                @foreach ($issued as $i)<x-billing.row :invoice="$i"/>@endforeach
            </div>
        </section>
    @endif
</x-ui.shell>
