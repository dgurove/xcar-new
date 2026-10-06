{{-- Вкладка «Оплат» видом «Строками» (`x-money.feed-row`; по умолчанию — таблица `admin/money/tab-table`): links — «Ждём»
     (что со ссылкой) и «Оплачено»; owed — «Проверить» (менеджер пишет, что оплатил), «Ждём» и «Оплачено»; payouts —
     «Выплатить» и «Выплачено». Фразы — `MoneyRows`, пустая группа не рисуется. titled — лупа: вкладки подряд под
     своими названиями. --}}
@php
    use App\Billing\PaymentSource; use App\Http\Admin\MoneyRows as R; use App\Support\Money;
    $paid = $s['paid'];
    $paidItems = $paid instanceof \Illuminate\Support\Collection ? $paid : collect($paid->items());
@endphp
@if (! $titled || $s['claims']->isNotEmpty() || $s['open']->isNotEmpty() || $paidItems->isNotEmpty())
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS[$key] }}</h2>@endif

    @if ($s['claims']->isNotEmpty())
        <div class="list-head">Проверить</div>
        <div class="list">
            @foreach ($s['claims'] as $p)
                @php $i = $p->invoice; @endphp
                <x-money.feed-row :key="$i->id" :title="R::who($i).' пишет, что '.($p->source === PaymentSource::Cash ? 'отдал наличные' : 'оплатил')" :amount="Money::rub($p->amount)" action="Проверить">
                    @if (R::what($i))<span>{{ R::what($i) }}</span>@endif
                    <span class="text-urgent">сообщил {{ $p->paid_at->translatedFormat('j M') }}</span>
                </x-money.feed-row>
            @endforeach
        </div>
    @endif

    @if ($s['open']->isNotEmpty())
        <div class="list-head mt-2">{{ $key === 'payouts' ? 'Выплатить' : 'Ждём' }}</div>
        <div class="list">
            @foreach ($s['open'] as $i)
                @php $due = R::due($i); @endphp
                @if ($key === 'payouts')
                    <x-money.feed-row :key="$i->id" :title="R::who($i).', вознаграждение'" :amount="Money::rub($i->remaining())" action="Выплатить">
                        @if (R::what($i))<span>{{ R::what($i) }}</span>@endif
                        @if ($due)<span class="{{ $due[1] }}">{{ $due[0] }}</span>@endif
                        @unless ($i->party->payoutReady())<span class="text-danger">реквизитов нет</span>@endunless
                    </x-money.feed-row>
                @else
                    <x-money.feed-row :key="$i->id" :title="R::line($i)" :amount="Money::rub($i->remaining())">
                        @if ($m = R::via($i))<x-ui.person :user="$m"/>@endif
                        @if ($key === 'links')
                            <span>взяли {{ $i->issued_at->translatedFormat('j M') }}</span>
                        @else
                            <span>счёт {{ $i->label() }}</span>
                            @if ($due)<span class="{{ $due[1] }}">{{ $due[0] }}</span>@endif
                        @endif
                        @if ($i->isPartial())<span>оплачено {{ Money::rub($i->total - $i->remaining()) }} из {{ Money::rub($i->total) }}</span>@endif
                        {{-- У счёта сделки ссылка не новость: о ней — только если пытались и не вышло. --}}
                        @if (($key === 'links' || $s['tried']->contains($i->id)) && ($l = R::link($i)))<span class="{{ $l[1] }}">{{ $l[0] }}</span>@endif
                    </x-money.feed-row>
                @endif
            @endforeach
        </div>
    @endif

    @if ($paidItems->isNotEmpty())
        <div class="list-head mt-2">{{ $key === 'payouts' ? 'Выплачено' : 'Оплачено' }}</div>
        <div class="list">
            @foreach ($paidItems as $i)
                @if ($key === 'payouts')
                    <x-money.feed-row :key="$i->id" :title="R::who($i).', вознаграждение'" :amount="Money::rub($i->total)">
                        @if (R::what($i))<span>{{ R::what($i) }}</span>@endif
                        @if ($h = R::paidOut($i))<span>{{ $h }}</span>@endif
                    </x-money.feed-row>
                @else
                    <x-money.feed-row :key="$i->id" :title="R::line($i)" :amount="Money::rub($i->total)" tone="text-open">
                        @if ($m = R::via($i))<x-ui.person :user="$m"/>@endif
                        @if ($h = R::how($i, $attempts))<span>{{ $h }}</span>@endif
                    </x-money.feed-row>
                @endif
            @endforeach
        </div>
        @if (! $paid instanceof \Illuminate\Support\Collection && $paid->hasPages())<div class="mt-8"><x-ui.pager :of="$paid"/></div>@endif
    @endif
</section>
@endif
