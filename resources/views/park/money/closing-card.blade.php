{{-- Карточка документа «Счетов и актов» (05.11.2026, владелец выбрал «Сводка и файлы»; было — голый список из 53 машин):
     сверху сумма крупно, состояние словом и плательщик с логотипом; круглые кнопки — «Отправить» (окно писем, номер
     встаёт при отправке) и «Отправили сами» у живого, «Оплачен» и «Отправить снова» у отправленного; плашки — изменилось
     после отправки, нет реквизитов, не вошли; файлы строками (просмотр в шторке документов, «Скачать»); состав — по
     движению в месяце и типу ТС, все ТС — шторкой; оплаты и история. Живой и отправленный — одна карточка. --}}
@php
    use App\Support\Money; use App\Support\Ago; use App\Billing\InvoiceState;
    $d = $doc; $i = $invoice; $me = auth()->user(); $manage = $me->canManagePark();
    $shown = $i ?? $draft;
    $monthWord = mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'));
    $sum = $d->drift['now'] ?? ($i ? ($i->remaining() > 0 && $i->state === InvoiceState::Issued ? $i->remaining() : $i->total) : ($draft?->total ?? $d->amount));
    $closingKey = $d->month->format('Y-m').'|'.$d->group;
    $mailUrl = $d->live() ? '/mail/new?'.http_build_query(['closing' => $closingKey]) : '/mail/new?invoice='.$i->id;
    $reviseMail = $d->drift && $i->paid == 0 && $d->group ? '/mail/new?'.http_build_query(['closing' => $closingKey, 'replaces' => $i->id]) : null;
    $issued = $i && $i->state === InvoiceState::Issued;
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$i ? '/money/invoices/'.$i->id : null" :title="$d->name" :head="false" :photo="false" :action="false">
        <div class="money-hero">
            @if ($d->vendor)<x-vendor.logo :vendor="$d->vendor" class="doc-hero-logo"/>@endif
            <div class="money-hero-sum nums">{{ Money::nums($sum, fmod((float) $sum, 1) ? 2 : 0) }}<small> ₽</small></div>
            @if ($i && $i->isPartial() && ! $d->drift)<div class="money-hero-car nums">из {{ Money::rub($i->total) }}</div>@endif
            <div class="money-hero-state {{ $d->tone() }}">{{ $d->state === 'send' ? 'не отправлен' : $d->word() }}</div>
            <div class="money-hero-who">{{ $d->name }}</div>
            <div class="detail-line justify-center text-sm text-ink-muted">
                <span>{{ $d->what }} за {{ $monthWord }}</span>
                @if ($d->month->isSameMonth(now()) && $d->live())<span>по сегодня</span>@endif
                @if ($shown)<span>{{ $shown->vatRate() ? 'НДС '.$shown->vatRate().' %' : 'без НДС' }}</span>@endif
                @if ($i)<span class="nums">{{ $i->label() }}</span>@elseif ($draft && $d->state === 'send')<span class="nums">{{ $draft->label() }} при отправке</span>@endif
            </div>
        </div>

        @if ($manage && $d->state !== 'nobody' && ($d->live() ? $d->state === 'send' : true))
            <div class="acts acts-hero">
                @if ($d->live())
                    <button type="button" class="act" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><span class="btn btn-accent btn-round"><x-ui.icon name="send"/></span>Отправить</button>
                    <form method="post" action="/money/draft/fix" class="contents" data-turbo-confirm="Документ ушёл не письмом отсюда — отметить отправленным?">
                        @csrf<input type="hidden" name="month" value="{{ $d->month->format('Y-m') }}"><input type="hidden" name="group" value="{{ $d->group }}">
                        <button class="act"><span class="btn btn-quiet btn-round"><x-ui.icon name="check"/></span>Отправили сами</button>
                    </form>
                @else
                    @if ($issued)
                        <div data-controller="sheet" class="contents">
                            <button type="button" class="act" data-action="sheet#open"><span class="btn btn-accent btn-round"><x-ui.icon name="check"/></span>Оплачен</button>
                            <x-ui.sheet :id="'paid-'.$i->id" :title="'Оплата по счёту '.$i->label()"><x-billing.pay-form :invoice="$i" :action="'/money/invoices/'.$i->id.'/payments'" :sources="$sources" sheet/></x-ui.sheet>
                        </div>
                    @endif
                    <button type="button" class="act" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><span class="btn btn-quiet btn-round"><x-ui.icon name="send"/></span>Отправить снова</button>
                @endif
            </div>
        @endif

        {{-- Где нужен наш ход — плашкой, до файлов. --}}
        @if ($d->drift)
            <div class="doc-alert">
                <div>Изменилось после отправки</div>
                <div class="text-sm text-ink-muted">было <span class="nums">{{ Money::rub($d->drift['was']) }}</span>, сейчас <span class="nums">{{ Money::rub($d->drift['now']) }}</span>@if ($i->paid > 0). По счёту уже платили — исправленный не отправить@endif</div>
                @if ($reviseMail && $manage)
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-s btn-accent" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $reviseMail }}"><x-ui.icon name="send" class="size-4"/> Отправить исправленный</button>
                        <form method="post" action="/money/draft/fix" class="contents" data-turbo-confirm="Исправленный ушёл не письмом отсюда — отметить отправленным? Прежний аннулируется">
                            @csrf<input type="hidden" name="month" value="{{ $d->month->format('Y-m') }}"><input type="hidden" name="group" value="{{ $d->group }}"><input type="hidden" name="replaces" value="{{ $i->id }}">
                            <button class="btn btn-s btn-quiet">Отправили сами</button>
                        </form>
                    </div>
                @endif
            </div>
        @endif
        @if ($d->state === 'blocked')
            <div class="doc-alert" data-controller="sheet">
                <div>Нет реквизитов {{ $d->party?->name }}</div>
                <div class="text-sm text-ink-muted">Без них счёт не собрать</div>
                @if ($manage)
                    <button type="button" class="btn btn-s btn-accent mt-3" data-action="sheet#open">Заполнить реквизиты</button>
                    <x-ui.sheet id="party-{{ $d->key }}" :title="(string) $d->party?->name" wide tall>@include('park.money.party-form', ['party' => $d->party, 'vendor' => $d->rows->first()['payer'] === 'vendor' ? $d->rows->first()['vehicle']->vendor : null])</x-ui.sheet>
                @endif
            </div>
        @endif
        @if ($d->state === 'nobody')
            <div class="doc-alert">Покупатель без контрагента — документ не из чего собрать</div>
        @endif
        @if ($skipped)
            <div class="doc-alert"><div>Не вошли</div><div class="text-sm text-ink-muted">{{ implode('; ', $skipped) }}</div></div>
        @endif

        @if ($files && $d->state !== 'blocked')
            <div class="list-cap mt-5">Файлы</div>
            <div class="list" data-docs-scope>
                @foreach ($files as $f)
                    <x-ui.file :name="$f['name']" :href="$f['url']" class="row" download/>
                @endforeach
            </div>
        @endif

        @if ($shown && $composition['cars']->isNotEmpty())
            <x-billing.registry-summary :composition="$composition" :month="$d->month" :total="(float) ($d->live() ? $shown->total : $i->total)" :id="$d->key"/>
        @endif

        @if ($i && ($history || $i->remaining() > 0))
            <div class="list-cap mt-5">История</div>
            <div class="list">
                @foreach ($history as $h)
                    <div class="row">
                        <span class="min-w-0 flex-1">{{ $h['text'] }}@if ($h['doc']) <x-ui.doc :doc="$h['doc']" class="text-accent-text">письмо</x-ui.doc>@endif</span>
                        <span class="shrink-0 text-sm text-ink-muted">{!! Ago::html($h['at'], words: false) !!}</span>
                    </div>
                @endforeach
                @if ($issued && $i->remaining() > 0)
                    <div class="row"><span class="min-w-0 flex-1 {{ $i->isOverdue() ? 'text-danger' : '' }}">{{ $i->isOverdue() ? 'Просрочен, ' : 'Ждём оплату' }}@if ($i->due_at) до {!! Ago::html($i->due_at, words: false) !!}@endif</span><span class="nums shrink-0">{{ Money::rub($i->remaining()) }}</span></div>
                @endif
            </div>
        @endif

        @if ($issued && $manage && (float) $i->paid === 0.0)
            <form method="post" action="/money/invoices/{{ $i->id }}/void" class="mt-5" data-turbo-confirm="Аннулировать {{ $i->label() }}? Документ снова станет живым">@csrf<button class="btn btn-ghost btn-block text-danger">Аннулировать</button></form>
        @endif
    </x-ui.row-card>
</x-ui.detail>
