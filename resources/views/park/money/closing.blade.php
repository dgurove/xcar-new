{{-- Закрытие месяца: пилюли месяцев, итог, строки по контрагентам — ТС, период, сутки, сумма, периодичность, почему сейчас;
     галочки и «Выставить выбранные» (счета с актами). Ниже — что уже выставлено за месяц. Письма — вручную с каждого счёта. --}}
@php use App\Support\Money; use App\Billing\Cadence; $me = auth()->user(); $n = $items->flatten(1)->count(); @endphp
<x-ui.shell :title="'Закрытие '.mb_strtolower($month->translatedFormat('F Y'))" :back="['Деньги', '/money']" narrow cache="no-cache">
    <x-ui.pills class="mb-4">
        @foreach ($months as $m)
            <x-ui.pill :href="'/money/closing?month='.$m->format('Y-m')" :current="$m->format('Y-m') === $month->format('Y-m')">{{ $m->translatedFormat('F') }}</x-ui.pill>
        @endforeach
    </x-ui.pills>
    <div class="mb-4 grid grid-cols-3 gap-2">
        <x-ui.stat :value="$n" label="Счетов"/>
        <x-ui.stat :value="Money::rub($total)" label="Сумма"/>
        <x-ui.stat :value="$items->count()" label="Контрагентов"/>
    </div>
    @if ($errors->has('closing'))<p class="field-error mb-4">{{ $errors->first('closing') }}</p>@endif
    @if ($n === 0)
        <x-ui.empty class="mt-6">Выставлять нечего</x-ui.empty>
    @else
        <form method="post" action="/money/closing" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
            @foreach ($items as $partyName => $rows)
                @php $party = $rows->first()['party']; @endphp
                <section>
                    <div class="mb-2 flex items-baseline justify-between gap-3">
                        <h2 class="text-lg">{{ $partyName }}</h2>
                        <span class="nums text-ink-muted">{{ Money::rub($rows->sum('amount')) }}</span>
                    </div>
                    <div class="flex flex-col gap-2">
                        @foreach ($rows as $r)
                            @php $v = $r['vehicle']; $can = $party && $me->canManagePark(); @endphp
                            <label class="row row-check {{ $can ? '' : 'opacity-60' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium"><a href="/cars/{{ $v->id }}" class="hover:underline">{{ $v->titleWithYear() }}</a>@if ($v->ref) <span class="nums text-ink-muted">{{ $v->ref }}</span>@endif</span>
                                    <span class="row-sub mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="tag nums">{{ $r['from']->translatedFormat('j M') }} – {{ $r['to']->translatedFormat('j M') }}, {{ $r['days'] }} сут</span>
                                        <span class="tag">{{ $r['reason'] }}</span>
                                        @if ($r['cadence'] === Cadence::Release)<span class="tag">после выдачи</span>@endif
                                        @if ($r['payer'] === 'buyer')<span class="tag">покупатель</span>@elseif ($r['payer'] === 'owner')<span class="tag">страхователь</span>@endif
                                        @if ($r['charges'])<span class="tag">+ начисления</span>@endif
                                        @unless ($party)<x-ui.pill tone="danger" class="!min-h-0 !py-0.5 text-xs">некому выставить</x-ui.pill>@endunless
                                    </span>
                                </span>
                                <span class="nums font-medium">{{ Money::rub($r['amount']) }}</span>
                                <span class="check"><input type="checkbox" name="items[]" value="{{ $r['key'] }}" @checked($can) @disabled(!$can)></span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach
            @if ($me->canManagePark())
                <x-ui.action-bar><x-ui.button class="min-w-0 flex-1" data-turbo-confirm="Выставить выбранные счета?">Выставить выбранные</x-ui.button></x-ui.action-bar>
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
