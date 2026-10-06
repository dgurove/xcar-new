{{-- Оплаты (раздел CRM) — как история операций в банке (06.10.2026, владелец: «даже я ничего не понимаю», начальник не
     находил оплаты по ссылкам). Без пилюль, три блока строками `.list` (`x-money.feed-row`):
     - «Надо сделать» — менеджер пишет, что оплатил; не смог оплатить по ссылке; ждёт выплату; в банк пришло без счёта;
     - «Ждём деньги» — кто нам должен, со счётом и тем, что с его ссылкой;
     - «История» — что пришло (+), ушло менеджерам (−) и что менеджер оставил себе, по дням.
     Чип менеджера сужает всё, один выбранный — его расчёты строкой сверху; лупа — по всем блокам. «Взять ссылку на
     оплату» — только админу. Нажатие строки — карточка счёта или поступления рядом, действия в ней. --}}
@php
    use App\Billing\Acquiring\PayMethod; use App\Billing\InvoiceState; use App\Billing\PaymentSource; use App\Support\Money;
    // Машина счёта (сделка, гараж) или услуга разовой оплаты.
    // Машина без запятой перед годом («Kia Rio 2019»): запятая уже отделяет её от плательщика.
    $what = fn ($i) => ($o = $i->deal?->offer ?? $i->offer ?? ($i->garageCar ?? $i->garagePayoutCar)?->offer) ? trim($o->title().' '.$o->year) : ($i->isService() ? $i->charges->first()?->title : null);
    $line = fn ($i) => implode(', ', array_filter([$i->party->name, $what($i)]));
    // Менеджер — коротким именем; нет менеджера (разовая оплата) — плательщик.
    $who = fn ($i) => $i->manager()?->shortName() ?? $i->party->name;
    // Платит покупатель менеджера, а не он сам — менеджер второй строкой.
    $via = fn ($i) => ($m = $i->manager()) && $m->party_id !== $i->party_id ? $m : null;
    $toneClass = fn (?string $tone) => match ($tone) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', default => '' };
    $day = fn ($d) => $d->isToday() ? 'Сегодня' : ($d->isYesterday() ? 'Вчера' : $d->translatedFormat($d->year === now()->year ? 'j F' : 'j F Y'));
    $todo = $claims->count() + $tried->count() + $payouts->count() + $bank->count();
    $empty = ! $todo && $waiting->isEmpty() && $history->isEmpty();
@endphp
<x-ui.shell title="Оплаты" :heading="false" :detail="$detail">
    <x-admin.work-titles current="money" :count="$todo ?: null"/>
    <x-ui.toolbar class="mt-5" name="money" :facets="$facets" search="Имя, машина, номер счёта" search-target="#money-feed">
        @if (auth()->user()->isAdmin() && app(\App\Billing\Acquiring\Gateway::class)->configured())
            <x-slot:actions>
                @include('admin.money.service-sheet')
            </x-slot:actions>
        @endif
    </x-ui.toolbar>
    <div id="money-feed">
    @if ($manager)
        <div class="list mt-4"><x-money.manager-row :user="$manager['user']" :position="$manager['position']"/></div>
    @endif
    @if ($empty)
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Денег пока не было' }}</x-ui.empty>
    @endif

    @if ($todo)
        <section class="mt-4" data-search-group>
            <div class="list-head">Надо сделать</div>
            <div class="list">
                @foreach ($claims as $p)
                    @php $i = $p->invoice; @endphp
                    <x-money.feed-row :key="$i->id" :title="$who($i).' пишет, что '.($p->source === PaymentSource::Cash ? 'отдал наличные' : 'оплатил')" :amount="Money::rub($p->amount)" action="Проверить">
                        @if ($what($i))<span>{{ $what($i) }}</span>@endif
                        <span class="text-urgent">сообщил {{ $p->paid_at->translatedFormat('j M') }}</span>
                    </x-money.feed-row>
                @endforeach
                @foreach ($tried as $i)
                    @php [$state, $tone] = $i->openLink()?->stateLine() ?? [null, null]; @endphp
                    <x-money.feed-row :key="$i->id" :title="$i->party->name.' не смог оплатить по ссылке'" :amount="Money::rub($i->remaining())">
                        @if ($what($i))<span>{{ $what($i) }}</span>@endif
                        @if ($state)<span class="{{ $toneClass($tone) }}">{{ $state }}</span>@endif
                    </x-money.feed-row>
                @endforeach
                @foreach ($payouts as $i)
                    <x-money.feed-row :key="$i->id" :title="$who($i).' ждёт выплату'" :amount="Money::rub($i->remaining())" action="Выплатить">
                        @if ($what($i))<span>{{ $what($i) }}</span>@endif
                        @if ($i->due_at)<span class="{{ $toneClass($i->light()) }}">до {{ $i->due_at->translatedFormat('j M') }}</span>@endif
                        @unless ($i->party->payoutReady())<span class="text-danger">реквизитов нет</span>@endunless
                    </x-money.feed-row>
                @endforeach
                @foreach ($bank as $tx)
                    <x-money.feed-row :key="'t'.$tx->id" :title="$tx->counterparty ? 'Пришло в банк от '.$tx->counterparty : 'Пришло в банк, плательщик не указан'" :amount="Money::rub($tx->amount)" action="Привязать">
                        <span>{{ $tx->booked_at->translatedFormat('j M') }}</span>
                        @if ($tx->purpose)<span>{{ \Illuminate\Support\Str::limit($tx->purpose, 90) }}</span>@endif
                    </x-money.feed-row>
                @endforeach
            </div>
        </section>
    @endif

    @if ($waiting->isNotEmpty())
        <section class="mt-4" data-search-group>
            <div class="list-head">Ждём деньги</div>
            <div class="list">
                @foreach ($waiting as $i)
                    @php
                        $link = $i->openLink();
                        [$state, $tone] = $link && ($link->error_at || $link->attempts->isNotEmpty()) ? $link->stateLine() : [null, null];
                    @endphp
                    <x-money.feed-row :key="$i->id" :title="$line($i)" :amount="Money::rub($i->remaining())">
                        @if ($m = $via($i))<x-ui.person :user="$m"/>@endif
                        @unless ($i->isService())<span>счёт {{ $i->label() }}</span>@endunless
                        @if ($i->due_at)<span class="{{ $toneClass($i->light()) }}">{{ $i->isOverdue() ? 'срок прошёл' : 'до' }} {{ $i->due_at->translatedFormat('j M') }}</span>@endif
                        @if ($i->isPartial())<span>оплачено {{ Money::rub($i->total - $i->remaining()) }} из {{ Money::rub($i->total) }}</span>@endif
                        @if ($state)<span class="{{ $toneClass($tone) }}">по ссылке {{ $state }}</span>@elseif ($link)<span>ссылку ещё не открывали</span>@endif
                    </x-money.feed-row>
                @endforeach
            </div>
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section class="mt-4" data-search-group>
            <div class="list-head">История</div>
            @foreach ($history->groupBy(fn ($p) => $p->paid_at->toDateString()) as $payments)
                <div class="@if (! $loop->first) mt-4 @endif" data-search-group>
                    <div class="list-cap">{{ $day($payments->first()->paid_at) }}</div>
                    <div class="list">
                        @foreach ($payments as $p)
                            @php $i = $p->invoice; $a = $attempts->get($p->id); @endphp
                            @if ($p->source === PaymentSource::Offset)
                                <x-money.feed-row :key="$i->id" :title="implode(', ', array_filter([$who($i), $what($i)]))" :amount="'себе '.Money::rub($p->amount)" tone="text-ink-muted">
                                    <span>оставил себе вознаграждение</span>
                                </x-money.feed-row>
                            @elseif ($i->isOwed())
                                <x-money.feed-row :key="$i->id" :title="$who($i).', вознаграждение'" :amount="'−'.Money::rub($p->amount)">
                                    @if ($what($i))<span>{{ $what($i) }}</span>@endif
                                    <span>выплатили {{ $p->source === PaymentSource::Cash ? 'наличными' : 'на счёт' }}</span>
                                </x-money.feed-row>
                            @else
                                <x-money.feed-row :key="$i->id" :title="$line($i)" :amount="'+'.Money::rub($p->amount)" tone="text-open">
                                    @if ($m = $via($i))<x-ui.person :user="$m"/>@endif
                                    @switch ($p->source)
                                        @case (PaymentSource::Acquiring)<span>по ссылке {{ PayMethod::label($a?->method) }}</span>@break
                                        @case (PaymentSource::Cash)<span>наличными</span>@break
                                        @default<span>в банк{{ $p->refNumber() ? ', п/п '.$p->refNumber() : '' }}</span>
                                    @endswitch
                                </x-money.feed-row>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
            @if ($history->hasPages())<div class="mt-8"><x-ui.pager :of="$history"/></div>@endif
        </section>
    @endif
    </div>
</x-ui.shell>
