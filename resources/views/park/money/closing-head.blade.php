{{-- Шапка документа на его странице (05.11.2026, владелец про прежнюю: «вёрстка позорище» — витрина с логотипом и суммой
     по центру и зажатая таблица): одна плашка во всю ширину — состояние и фраза (НДС, номер, сколько ТС) слева, сумма
     справа (плательщик с логотипом — заголовок страницы); ниже одной строкой действия, файлы (просмотр в шторке документов, скачать — в её полосе) и «···».
     Плашки «изменилось», «нет реквизитов», «не вошли» и история — тем же видом, что в карточке. --}}
@php
    use App\Support\Money; use App\Support\Ago; use App\Billing\InvoiceState;
    $d = $doc; $i = $invoice; $manage = auth()->user()->canManagePark();
    $shown = $i ?? $draft;
    $monthWord = mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'));
    $sum = $d->drift['now'] ?? ($i ? ($i->remaining() > 0 && $i->state === InvoiceState::Issued ? $i->remaining() : $i->total) : ($draft?->total ?? $d->amount));
    $closingKey = $d->month->format('Y-m').'|'.$d->group;
    $mailUrl = $d->live() ? '/mail/new?'.http_build_query(['closing' => $closingKey]) : '/mail/new?invoice='.$i->id;
    $reviseMail = $d->drift && $i->paid == 0 && $d->group ? '/mail/new?'.http_build_query(['closing' => $closingKey, 'replaces' => $i->id]) : null;
    $issued = $i && $i->state === InvoiceState::Issued;
@endphp
<section class="box doc-head">
    {{-- Плательщик, вид и месяц — в заголовке страницы (с логотипом): здесь — что с документом и сумма. --}}
    <div class="doc-head-top">
        <div class="min-w-0 flex-1">
            <div class="doc-head-state {{ $d->tone() }}">{{ $d->state === 'send' ? 'Не отправлен' : \Illuminate\Support\Str::ucfirst($d->word()) }}</div>
            <div class="detail-line text-sm text-ink-muted">
                @if ($d->month->isSameMonth(now()) && $d->live())<span>по сегодня</span>@endif
                @if ($shown)<span>{{ $shown->vatRate() ? 'НДС '.$shown->vatRate().' %' : 'без НДС' }}</span>@endif
                @if ($i)<span class="nums">{{ $i->label() }}</span>@elseif ($draft && $d->state === 'send')<span class="nums">{{ $draft->label() }} при отправке</span>@endif
                <span class="nums">{{ $cars->count() }}&nbsp;ТС</span>
            </div>
        </div>
        <div class="doc-head-sum nums">{{ Money::rub($sum) }}@if ($i && $i->isPartial() && ! $d->drift)<span class="doc-head-of">из {{ Money::rub($i->total) }}</span>@endif</div>
    </div>
    <div class="doc-head-acts">
        @if ($manage && $d->state === 'send' && $d->live())
            <button type="button" class="btn btn-s btn-accent" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><x-ui.icon name="send" class="size-4"/>Отправить</button>
            <span data-controller="sheet" class="contents">
                <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="check" class="size-4"/>Отправили сами</button>
                <x-ui.sheet :id="'fix-'.$d->key" title="Отправили сами">@include('park.money.closing-fix', ['replaces' => null])</x-ui.sheet>
            </span>
        @elseif ($manage && $i)
            @if ($issued)
                <span data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-accent" data-action="sheet#open"><x-ui.icon name="check" class="size-4"/>Оплачен</button>
                    <x-ui.sheet :id="'paid-'.$i->id" :title="'Оплата по счёту '.$i->label()"><x-billing.pay-form :invoice="$i" :action="'/money/invoices/'.$i->id.'/payments'" :sources="$sources" sheet/></x-ui.sheet>
                </span>
            @endif
            <button type="button" class="btn btn-s btn-quiet" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $mailUrl }}"><x-ui.icon name="send" class="size-4"/>Отправить снова</button>
        @endif
        @if ($files && $d->state !== 'blocked')
            <span class="doc-head-files" data-docs-scope>
                @foreach ($files as $f)
                    <x-ui.doc :doc="$f" class="btn btn-s btn-quiet doc-file"><x-ui.file-icon :name="$f['name']" class="file-icon-s"/>{{ $f['label'] }}</x-ui.doc>
                @endforeach
            </span>
        @endif
        <span class="ml-auto">@include('park.money.closing-menu', ['button' => 'btn btn-s btn-quiet btn-round'])</span>
    </div>
</section>

@if ($d->drift)
    <div class="doc-alert">
        <div>Изменилось после отправки: было <span class="nums">{{ Money::rub($d->drift['was']) }}</span>, сейчас <span class="nums">{{ Money::rub($d->drift['now']) }}</span>@if ($i->paid > 0). По счёту уже платили — исправленный не отправить@endif</div>
        @if ($reviseMail && $manage)
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="btn btn-s btn-accent" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="{{ $reviseMail }}"><x-ui.icon name="send" class="size-4"/>Отправить исправленный</button>
                <span data-controller="sheet" class="contents">
                    <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open">Отправили сами</button>
                    <x-ui.sheet :id="'fix-r-'.$d->key" title="Исправленный отправили сами">@include('park.money.closing-fix', ['replaces' => $i])</x-ui.sheet>
                </span>
            </div>
        @endif
    </div>
@endif
@if ($d->state === 'blocked')
    <div class="doc-alert flex flex-wrap items-center gap-3" data-controller="sheet">
        <span class="min-w-0 flex-1">Нет реквизитов {{ $d->party?->name }} — без них счёт не собрать</span>
        @if ($manage)
            <button type="button" class="btn btn-s btn-accent" data-action="sheet#open">Заполнить реквизиты</button>
            <x-ui.sheet id="party-{{ $d->key }}" :title="(string) $d->party?->name" wide tall>@include('park.money.party-form', ['party' => $d->party, 'vendor' => $d->rows->first()['payer'] === 'vendor' ? $d->rows->first()['vehicle']->vendor : null])</x-ui.sheet>
        @endif
    </div>
@endif
@if ($skipped)
    <div class="doc-alert">Не вошли: {{ implode('; ', $skipped) }}</div>
@endif
@if ($history || ($issued && $i->remaining() > 0))
    <div class="doc-history detail-line text-sm text-ink-muted" data-docs-scope>
        @foreach ($history as $h)
            <span>{{ $h['text'] }} {!! Ago::html($h['at'], words: false) !!}@if ($h['doc']) <x-ui.doc :doc="$h['doc']" class="text-accent-text">письмо</x-ui.doc>@endif</span>
        @endforeach
        @if ($issued && $i->remaining() > 0)<span class="{{ $i->isOverdue() ? 'text-danger' : '' }}">{{ $i->isOverdue() ? 'просрочен' : 'ждём оплату' }}@if ($i->due_at) до {!! Ago::html($i->due_at, words: false) !!}@endif</span>@endif
    </div>
@endif
