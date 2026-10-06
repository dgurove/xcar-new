{{-- Вкладка «Оплат» группами строк `x-money.feed-row`: links — «Ждём» (что со ссылкой) и «Оплачено»; owed — «Проверить»
     (менеджер пишет, что оплатил), «Ждём» (счёт, срок, не прошла по ссылке) и «Оплачено» (как заплатили); payouts —
     «Выплатить» и «Выплачено». Пустая группа не рисуется. titled — лупа: вкладки идут подряд под своими названиями. --}}
@php
    use App\Billing\Acquiring\PayMethod; use App\Billing\PaymentSource; use App\Support\Money;
    // Машина без запятой перед годом («Kia Rio 2019»): запятая уже отделяет её от плательщика; у разовой — услуга.
    $what = fn ($i) => ($o = $i->deal?->offer ?? $i->offer ?? ($i->garageCar ?? $i->garagePayoutCar)?->offer) ? trim($o->title().' '.$o->year) : ($i->isService() ? $i->charges->first()?->title : null);
    $line = fn ($i) => implode(', ', array_filter([$i->party->name, $what($i)]));
    // Менеджер — коротким именем; нет менеджера — плательщик.
    $who = fn ($i) => $i->manager()?->shortName() ?? $i->party->name;
    // Платит покупатель менеджера, а не он сам — менеджер второй строкой.
    $via = fn ($i) => ($m = $i->manager()) && $m->party_id !== $i->party_id ? $m : null;
    $toneClass = fn (?string $tone) => match ($tone) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', default => '' };
    // Как заплатили — по последней оплате счёта.
    $how = function ($i) use ($attempts) {
        $p = $i->payments->sortBy('paid_at')->last();
        if (! $p) {
            return null;
        }
        $day = $p->paid_at->translatedFormat('j M');

        return match ($p->source) {
            PaymentSource::Acquiring => 'оплатил '.$day.' по ссылке '.PayMethod::label($attempts->get($p->id)?->method),
            PaymentSource::Cash => 'оплатил '.$day.' наличными',
            PaymentSource::Offset => 'оставил себе вознаграждение '.$day,
            default => 'оплатил '.$day.' в банк'.($p->refNumber() ? ', п/п '.$p->refNumber() : ''),
        };
    };
    // Что со ссылкой: пытались и не вышло, открывали — словами ЮKassa; ссылку не трогали — «ещё не открывали».
    $link = function ($i) use ($toneClass) {
        $l = $i->openLink();
        if (! $l) {
            return null;
        }
        [$state, $tone] = $l->error_at || $l->attempts->isNotEmpty() ? $l->stateLine() : ['ссылку ещё не открывали', null];

        return '<span class="'.$toneClass($tone).'">'.e($state).'</span>';
    };
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
                <x-money.feed-row :key="$i->id" :title="$who($i).' пишет, что '.($p->source === PaymentSource::Cash ? 'отдал наличные' : 'оплатил')" :amount="Money::rub($p->amount)" action="Проверить">
                    @if ($what($i))<span>{{ $what($i) }}</span>@endif
                    <span class="text-urgent">сообщил {{ $p->paid_at->translatedFormat('j M') }}</span>
                </x-money.feed-row>
            @endforeach
        </div>
    @endif

    @if ($s['open']->isNotEmpty())
        <div class="list-head mt-2">{{ $key === 'payouts' ? 'Выплатить' : 'Ждём' }}</div>
        <div class="list">
            @foreach ($s['open'] as $i)
                @if ($key === 'payouts')
                    <x-money.feed-row :key="$i->id" :title="$who($i).', вознаграждение'" :amount="Money::rub($i->remaining())" action="Выплатить">
                        @if ($what($i))<span>{{ $what($i) }}</span>@endif
                        @if ($i->due_at)<span class="{{ $toneClass($i->light()) }}">{{ $i->isOverdue() ? 'срок прошёл' : 'до' }} {{ $i->due_at->translatedFormat('j M') }}</span>@endif
                        @unless ($i->party->payoutReady())<span class="text-danger">реквизитов нет</span>@endunless
                    </x-money.feed-row>
                @else
                    <x-money.feed-row :key="$i->id" :title="$line($i)" :amount="Money::rub($i->remaining())">
                        @if ($m = $via($i))<x-ui.person :user="$m"/>@endif
                        @if ($key === 'links')
                            <span>взяли {{ $i->issued_at->translatedFormat('j M') }}</span>
                        @else
                            <span>счёт {{ $i->label() }}</span>
                            @if ($i->due_at)<span class="{{ $toneClass($i->light()) }}">{{ $i->isOverdue() ? 'срок прошёл' : 'до' }} {{ $i->due_at->translatedFormat('j M') }}</span>@endif
                        @endif
                        @if ($i->isPartial())<span>оплачено {{ Money::rub($i->total - $i->remaining()) }} из {{ Money::rub($i->total) }}</span>@endif
                        {{-- У счёта сделки ссылка не новость: о ней — только если пытались и не вышло. --}}
                        @if ($key === 'links' || $s['tried']->contains($i->id)){!! $link($i) !!}@endif
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
                    @php $p = $i->payments->sortBy('paid_at')->last(); @endphp
                    <x-money.feed-row :key="$i->id" :title="$who($i).', вознаграждение'" :amount="Money::rub($i->total)">
                        @if ($what($i))<span>{{ $what($i) }}</span>@endif
                        @if ($p)<span>выплатили {{ $p->paid_at->translatedFormat('j M') }} {{ $p->source === PaymentSource::Cash ? 'наличными' : 'на счёт' }}</span>@endif
                    </x-money.feed-row>
                @else
                    <x-money.feed-row :key="$i->id" :title="$line($i)" :amount="Money::rub($i->total)" tone="text-open">
                        @if ($m = $via($i))<x-ui.person :user="$m"/>@endif
                        @if ($h = $how($i))<span>{{ $h }}</span>@endif
                    </x-money.feed-row>
                @endif
            @endforeach
        </div>
        @if (! $paid instanceof \Illuminate\Support\Collection && $paid->hasPages())<div class="mt-8"><x-ui.pager :of="$paid"/></div>@endif
    @endif
</section>
@endif
